<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Concerns\RunsTeamSync;
use Platform\AssetManager\Services\EmployeeService;
use Platform\AssetManager\Services\IntuneGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncLicensesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RunsTeamSync;

    public int $timeout = 300;
    public int $tries   = 1;

    /** Eindeutigkeits-Sperre nach 10 Min automatisch freigeben (> $timeout=300s). Verhindert, dass eine
     *  geleakte Sperre — etwa nach einem Worker-Neustart mitten im Job — künftige Dispatches stillschweigend verschluckt. */
    public int $uniqueFor = 600;

    /** Team-/Tenant-Kontext des Connectors — in handle() gesetzt (nicht serialisiert). */
    protected ?int $teamId   = null;
    protected ?int $tenantId = null;

    /** Serialisiert parallele Lizenz-Syncs DESSELBEN Connectors (verhindert Duplikat-Races vor dem Unique-Index). */
    public function uniqueId(): string
    {
        return (string) $this->connectorId;
    }

    protected array $skuNames = [
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM'    => 'Microsoft 365 Business Standard',
        'SPB'                      => 'Microsoft 365 Business Premium',
        'ENTERPRISEPACK'           => 'Office 365 E3',
        'ENTERPRISEPREMIUM'        => 'Office 365 E5',
        'POWER_AUTOMATE_PER_USER'  => 'Power Automate Premium',
        'Copilot_For_M365'         => 'Microsoft 365 Copilot',
        'EXCHANGESTANDARD'         => 'Exchange Online Plan 1',
        'EXCHANGEENTERPRISE'       => 'Exchange Online Plan 2',
    ];

    public function __construct(
        public readonly int $connectorId
    ) {}

    /**
     * Fan-out: dispatcht je aktivem, konfiguriertem Connector des Teams einen eigenen Job.
     */
    public static function dispatchForTeam(int $teamId): int
    {
        $count = 0;
        foreach (AssetConnectorConfig::where('team_id', $teamId)->where('enabled', true)->get() as $config) {
            if (!$config->isConfigured()) continue;
            // Consent-Guard (M15): consent-lose Connector nicht dispatchen (würde mit 403 auf 'error' kippen).
            if (!$config->isConsentConfirmed()) continue;
            self::dispatch($config->id);
            $count++;
        }
        return $count;
    }

    public function handle(IntuneGraphService $service, EmployeeService $employeeService): void
    {
        $config = AssetConnectorConfig::where('id', $this->connectorId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured() || !$config->tenant_id) {
            Log::warning('AssetManager: Lizenz-Sync übersprungen — Connector nicht konfiguriert/ohne Tenant', ['connector_id' => $this->connectorId]);
            return;
        }

        $this->teamId   = $config->team_id;
        $this->tenantId = $config->tenant_id;

        $startedAt = now();
        $log = AssetLicenseSyncLog::create([
            'team_id'    => $this->teamId,
            'tenant_id'  => $this->tenantId,
            'status'     => 'started',
            'started_at' => $startedAt,
        ]);

        try {
            // 1. SKUs abrufen
            $skus = $service->getSubscribedSkus($config);
            if ($skus === null) {
                $this->markFailed($log, $startedAt, $service->lastError ?? 'SKU-Abruf fehlgeschlagen.');
                return;
            }

            foreach ($skus as $sku) {
                $purchased = $sku['prepaidUnits']['enabled'] ?? 0;
                $consumed  = $sku['consumedUnits'] ?? 0;
                $available = max(0, $purchased - $consumed);
                $partNum   = $sku['skuPartNumber'] ?? '';

                $existing = AssetLicenseSku::where('tenant_id', $this->tenantId)
                    ->where('sku_id', $sku['skuId'])
                    ->first();

                $data = [
                    'sku_part_number' => $partNum,
                    'display_name'    => $this->skuNames[$partNum] ?? $partNum,
                    'purchased_units' => $purchased,
                    'consumed_units'  => $consumed,
                    'available_units' => $available,
                    'synced_at'       => now(),
                    'raw_data'        => $sku,
                ];

                if ($existing) {
                    // unit_price NIEMALS überschreiben
                    $existing->update($data);
                } else {
                    AssetLicenseSku::create(array_merge($data, [
                        'team_id'    => $this->teamId,
                        'tenant_id'  => $this->tenantId,
                        'sku_id'     => $sku['skuId'],
                        'unit_price' => null,
                    ]));
                }
            }

            // SKU-Lookup für Part Numbers
            $skuPartMap = collect($skus)->keyBy('skuId')->map(fn($s) => $s['skuPartNumber'] ?? '');

            // 2. User-Lizenzen abrufen
            $users = $service->getUsersWithLicenses($config);
            if ($users === null) {
                $this->markFailed($log, $startedAt, $service->lastError ?? 'User-Abruf fehlgeschlagen.');
                return;
            }

            $added   = 0;
            $keptIds = [];

            foreach ($users as $user) {
                $upn         = $user['userPrincipalName'] ?? null;
                $displayName = $user['displayName']       ?? null;

                if (!$upn) continue;

                // Employee aus Graph-User-Daten anlegen/aktualisieren (tenant-skopiert)
                $employee = $employeeService->findOrCreateByUpn(
                    $this->teamId,
                    $this->tenantId,
                    $upn,
                    $displayName,
                    'graph'
                );
                // Wenn Sync von Graph kommt, source nachträglich auf 'graph' upgrade
                if ($employee->source === 'derived') {
                    $employee->update(['source' => 'graph', 'synced_at' => now()]);
                } else {
                    $employee->update(['synced_at' => now()]);
                }

                foreach (($user['assignedLicenses'] ?? []) as $license) {
                    $skuId = $license['skuId'] ?? null;
                    if (!$skuId) continue;

                    $record = AssetUserLicense::updateOrCreate(
                        [
                            'tenant_id'           => $this->tenantId,
                            'user_principal_name' => $upn,
                            'sku_id'              => $skuId,
                        ],
                        [
                            'team_id'         => $this->teamId,
                            'sku_part_number' => $skuPartMap[$skuId] ?? null,
                            'display_name'    => $displayName,
                            'raw_data'        => $license,
                        ]
                    );

                    if ($record->wasRecentlyCreated) {
                        $record->update(['assigned_at' => now()]);
                        $added++;
                    }

                    $keptIds[] = $record->id;
                }
            }

            $durationMs       = (int) ($startedAt->diffInMilliseconds(now()));
            $removed          = 0;
            $totalAssignments = 0;

            // Reconcile-Delete + Status-Schreiben atomar: kein halb-bereinigter Stand und kein ewig
            // hängendes 'started', falls zwischen Delete und Log-Update etwas schiefgeht.
            DB::transaction(function () use ($keptIds, $log, $skus, $added, $durationMs, &$removed, &$totalAssignments) {
                // Schutz analog zu SyncIntuneDevicesJob: Ist KEINE einzige Lizenzzuweisung erhalten geblieben
                // (leere Graph-Antwort oder alle User ohne Lizenzen), nichts löschen — whereNotIn('id', [])
                // würde sonst sämtliche Zuweisungen des Tenants entfernen. Scope strikt pro Tenant.
                $removed = $this->reconcileDelete(
                    fn () => AssetUserLicense::where('tenant_id', $this->tenantId),
                    'id',
                    $keptIds
                );

                $totalAssignments = AssetUserLicense::where('tenant_id', $this->tenantId)->count();

                $log->update([
                    'status'              => 'success',
                    'skus_synced'         => count($skus),
                    'assignments_synced'  => $totalAssignments,
                    'assignments_added'   => $added,
                    'assignments_removed' => $removed,
                    'duration_ms'         => $durationMs,
                    'completed_at'        => now(),
                ]);
            });

            // Safety-Net: alle UPNs aus Bestandsdaten nachziehen
            $employeeService->backfillForTenant($this->teamId, $this->tenantId);

            Log::info('AssetManager: Lizenz-Sync erfolgreich', [
                'connector_id' => $this->connectorId,
                'tenant_id'    => $this->tenantId,
                'skus'         => count($skus),
                'total'        => $totalAssignments,
                'added'        => $added,
                'removed'      => $removed,
                'duration'     => $durationMs . 'ms',
            ]);

        } catch (\Throwable $e) {
            $this->markFailed($log, $startedAt, $e->getMessage());
            Log::error('AssetManager: Lizenz-Sync Exception', [
                'connector_id' => $this->connectorId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    protected function markFailed(AssetLicenseSyncLog $log, \Carbon\Carbon $startedAt, string $message): void
    {
        $this->markSyncLogFailed($log, $startedAt, $message);
    }

    /**
     * Wird von Laravel bei terminalem Scheitern (Timeout/Kill/uncaught) aufgerufen — der try/catch in
     * handle() greift dort NICHT. Räumt hängengebliebene 'started'-Logs des Tenants auf, damit die UI nicht
     * ewig „läuft" zeigt. Darf selbst nie werfen.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $config = AssetConnectorConfig::find($this->connectorId);
            if ($config && $config->tenant_id) {
                $this->failStuckSyncLogs(AssetLicenseSyncLog::class, $config->tenant_id, $e->getMessage());
            }
        } catch (\Throwable $ignored) {
            // failed() muss schweigend bleiben
        }
    }
}
