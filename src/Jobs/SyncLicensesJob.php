<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetUserLicense;
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

    public int $timeout = 300;
    public int $tries   = 1;

    /** Serialisiert parallele Lizenz-Syncs desselben Teams (verhindert Duplikat-Races vor dem Unique-Index). */
    public function uniqueId(): string
    {
        return (string) $this->teamId;
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
        public readonly int $teamId
    ) {}

    public function handle(IntuneGraphService $service, EmployeeService $employeeService): void
    {
        $config = AssetConnectorConfig::where('team_id', $this->teamId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured()) {
            Log::warning('AssetManager: Lizenz-Sync übersprungen — Connector nicht konfiguriert', ['team_id' => $this->teamId]);
            return;
        }

        $startedAt = now();
        $log = AssetLicenseSyncLog::create([
            'team_id'    => $this->teamId,
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

                $existing = AssetLicenseSku::where('team_id', $this->teamId)
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

                // Employee aus Graph-User-Daten anlegen/aktualisieren
                $employee = $employeeService->findOrCreateByUpn(
                    $this->teamId,
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
                            'team_id'             => $this->teamId,
                            'user_principal_name' => $upn,
                            'sku_id'              => $skuId,
                        ],
                        [
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
                // würde sonst sämtliche Zuweisungen des Teams entfernen.
                if (!empty($keptIds)) {
                    $removed = AssetUserLicense::where('team_id', $this->teamId)
                        ->whereNotIn('id', $keptIds)
                        ->count();

                    AssetUserLicense::where('team_id', $this->teamId)
                        ->whereNotIn('id', $keptIds)
                        ->delete();
                }

                $totalAssignments = AssetUserLicense::where('team_id', $this->teamId)->count();

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
            $employeeService->backfillForTeam($this->teamId);

            Log::info('AssetManager: Lizenz-Sync erfolgreich', [
                'team_id'  => $this->teamId,
                'skus'     => count($skus),
                'total'    => $totalAssignments,
                'added'    => $added,
                'removed'  => $removed,
                'duration' => $durationMs . 'ms',
            ]);

        } catch (\Throwable $e) {
            $this->markFailed($log, $startedAt, $e->getMessage());
            Log::error('AssetManager: Lizenz-Sync Exception', [
                'team_id' => $this->teamId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function markFailed(AssetLicenseSyncLog $log, \Carbon\Carbon $startedAt, string $message): void
    {
        $log->update([
            'status'        => 'error',
            'error_message' => $message,
            'duration_ms'   => (int) ($startedAt->diffInMilliseconds(now())),
            'completed_at'  => now(),
        ]);
    }

    /**
     * Wird von Laravel bei terminalem Scheitern (Timeout/Kill/uncaught) aufgerufen — der try/catch in
     * handle() greift dort NICHT. Räumt hängengebliebene 'started'-Logs des Teams auf, damit die UI nicht
     * ewig „läuft" zeigt. Darf selbst nie werfen.
     */
    public function failed(\Throwable $e): void
    {
        try {
            AssetLicenseSyncLog::where('team_id', $this->teamId)
                ->where('status', 'started')
                ->update([
                    'status'        => 'error',
                    'error_message' => $e->getMessage(),
                    'completed_at'  => now(),
                ]);
        } catch (\Throwable $ignored) {
            // failed() muss schweigend bleiben
        }
    }
}
