<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Concerns\RunsTeamSync;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\HolderTypeService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TenantContext;
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

    public function handle(IntuneGraphService $service, HolderService $holderService): void
    {
        // Regeln frisch lesen: der Cache ist statisch und lebt im Queue-Worker sonst bis zum Neustart —
        // eine in den Einstellungen geänderte Typisierungsregel wirkte im Sync nie (#946).
        HolderClassifier::forget();

        $config = AssetConnectorConfig::where('id', $this->connectorId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured() || !$config->tenant_id) {
            Log::warning('AssetManager: Lizenz-Sync übersprungen — Connector nicht konfiguriert/ohne Tenant', ['connector_id' => $this->connectorId]);
            return;
        }

        $this->teamId   = $config->team_id;
        $this->tenantId = $config->tenant_id;

        // Tenant-Grenze im Job (ADR 0016): der Sync arbeitet ausdrücklich IM Tenant dieses Connectors.
        // clearOverride() zuerst, damit in einem langlebigen Queue-Worker kein Override eines
        // vorherigen Jobs haftet — genau der Fehler, den man erst an falschen Daten bemerkt.
        TenantContext::clearOverride();
        TenantContext::forceTenant($this->tenantId);

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

            // BEWUSST NUR Upsert, KEIN Reconcile-Delete (N11): aus dem Tenant verschwundene/gekündigte SKUs
            // bleiben in asset_license_skus stehen (mit altem Stand). Anders als bei Geräten gibt es hier
            // KEINE Soft-Delete-Strecke — der Katalog kann daher Karteileichen enthalten; seit #968 stehen
            // ihre Mengen auf 0 (siehe unten), sie zählen also nicht mehr als Kapazität. Akzeptiert, weil
            // SKUs selten gekündigt werden und unit_price (manuell gepflegt) erhalten bleiben soll;
            // ein späterer Soft-Delete bräuchte ein Schema-Feld + Reconcile analog reconcileDelete().
            foreach ($skus as $sku) {
                $purchased = $sku['prepaidUnits']['enabled'] ?? 0;
                $consumed  = $sku['consumedUnits'] ?? 0;
                $available = max(0, $purchased - $consumed);
                // Gesperrte/abgelaufene Abos (#968) haben keine nutzbaren freien Plätze — sonst stünden
                // sie dauerhaft als „ungenutzt, Sparpotenzial" in der Auswertung. „Warning" ist die
                // Kulanzphase und bleibt nutzbar.
                if (in_array($sku['capabilityStatus'] ?? 'Enabled', ['Suspended', 'LockedOut', 'Deleted'], true)) {
                    $available = 0;
                }
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

            // Aus dem Tenant verschwundene SKUs (#968): nicht löschen (unit_price und Historie bleiben, N11),
            // aber Mengen auf 0 — sonst zählten gekündigte Abos weiter als Kapazität und Sparpotenzial.
            // Nur bei nicht-leerer Antwort: eine leere Seite ist ein Glitch, kein Massen-Kündigen.
            $seenSkuIds = array_values(array_filter(array_map(fn ($s) => $s['skuId'] ?? null, $skus)));
            if ($seenSkuIds !== []) {
                AssetLicenseSku::where('tenant_id', $this->tenantId)
                    ->whereNotIn('sku_id', $seenSkuIds)
                    ->where(fn ($q) => $q->where('purchased_units', '>', 0)->orWhere('available_units', '>', 0)->orWhere('consumed_units', '>', 0))
                    ->update(['purchased_units' => 0, 'consumed_units' => 0, 'available_units' => 0, 'updated_at' => now()]);
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

                // Asset-Träger aus Graph-User-Daten anlegen/aktualisieren (tenant-skopiert)
                $holder = $holderService->findOrCreateByUpn(
                    $this->teamId,
                    $this->tenantId,
                    $upn,
                    $displayName,
                    'graph'
                );
                // Wenn Sync von Graph kommt, source nachträglich auf 'graph' upgrade
                if ($holder->source === 'derived') {
                    $holder->source = 'graph';
                }
                $holder->synced_at = now();
                // Graph-Profil (department/jobTitle/Rufnummern) beim REGULÄREN Sync mitziehen — gemeinsame
                // Precedence-Logik (ADR 0014), damit die Anreicherung nicht nur am manuellen User-Import hängt.
                $holderService->applyGraphProfile($holder, $user);
                $holder->save();

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
            $holderService->backfillForTenant($this->teamId, $this->tenantId);

            // Seats haben sich geändert → Preise der jüngsten Abrechnungsperiode neu verteilen (#944).
            // Sonst hätten neue Zuweisungen bis zum nächsten Rechnungsimport keinen Preis, und die
            // Mengen an den Vertragszeilen (zugeordnet/Pool/Überhang) wären veraltet. Läuft NACH dem
            // Backfill, damit neu angelegte Träger schon ihren Typ tragen (Funktionskonten-Kaskade).
            // Ein Fehler hier darf den erfolgreichen Sync nicht umwerfen.
            if ($added > 0 || $removed > 0) {
                try {
                    app(HolderTypeService::class)->redistribute($this->teamId, $this->tenantId);
                } catch (\Throwable $e) {
                    Log::warning('AssetManager: Lizenz-Preisverteilung nach Sync fehlgeschlagen', [
                        'tenant_id' => $this->tenantId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

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
