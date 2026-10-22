<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Support\ImportRunRecorder;
use Platform\AssetManager\Models\AssetImportRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderMergeService;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportTenantUsersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Eindeutig je Connector wie die beiden anderen Sync-Jobs (#954): ein manueller Lauf (UI/MCP) und
     * der 01:30-Zeitplan sollen nicht zwei Stilllegungs-Abgleiche gleichzeitig fahren. Die Sperre gibt
     * sich nach 20 Min. selbst frei (> $timeout), damit ein abgestürzter Worker sie nicht ewig hält.
     */
    public int $uniqueFor = 1200;

    public function uniqueId(): string
    {
        return (string) $this->connectorId;
    }

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        public readonly int $connectorId
    ) {}

    /** Fan-out: importiert Tenant-User je aktivem, konfiguriertem Connector des Teams. */
    public static function dispatchForTeam(int $teamId): int
    {
        $count = 0;
        foreach (AssetConnectorConfig::where('team_id', $teamId)->where('enabled', true)->get() as $config) {
            if (!$config->isConfigured()) continue;
            // Consent-Guard wie bei Geräte-/Lizenz-Sync: ohne Admin-Zustimmung liefe der Abruf in 403.
            if (!$config->isConsentConfirmed()) continue;
            self::dispatch($config->id);
            $count++;
        }
        return $count;
    }

    public function handle(IntuneGraphService $graph, HolderService $holders): void
    {
        // Regeln frisch lesen: der Cache ist statisch und lebt im Queue-Worker sonst bis zum Neustart —
        // eine in den Einstellungen geänderte Typisierungsregel wirkte im Sync nie (#946).
        HolderClassifier::forget();

        $config = AssetConnectorConfig::where('id', $this->connectorId)
            ->where('enabled', true)
            ->first();
        if (!$config || !$config->isConfigured() || !$config->tenant_id) {
            Log::warning('AssetManager: Import übersprungen — Connector nicht konfiguriert/ohne Tenant', ['connector_id' => $this->connectorId]);
            return;
        }

        $teamId   = $config->team_id;
        $tenantId = $config->tenant_id;

        // Tenant-Grenze im Job (ADR 0016): der Sync arbeitet ausdruecklich IM Tenant dieses Connectors.
        // clearOverride() zuerst, damit in einem langlebigen Queue-Worker kein Override eines
        // vorherigen Jobs haftet — genau der Fehler, den man erst an falschen Daten bemerkt.
        TenantContext::clearOverride();
        TenantContext::forceTenant($tenantId);

        Log::info('AssetManager: Tenant-User-Import gestartet', ['connector_id' => $this->connectorId, 'tenant_id' => $tenantId]);

        $startedAt = microtime(true);

        $users = $graph->getUsersWithLicenses($config);
        if ($users === null) {
            Log::error('AssetManager: Tenant-User-Import fehlgeschlagen', [
                'connector_id' => $this->connectorId,
                'error'        => $graph->lastError ?? 'unbekannt',
            ]);
            // Ins Import-Protokoll, NICHT in config.sync_error (#954): das Feld gehört dem Geräte-Sync,
            // und dessen nächster Erfolg hätte den Fehler still überschrieben.
            app(ImportRunRecorder::class)->recordFailure(AssetImportRun::SOURCE_DIRECTORY, $teamId, $tenantId,
                'Verzeichnis-Abgleich: ' . ($graph->lastError ?? 'unbekannt'), null, false, null, $startedAt);
            return;
        }

        $count    = 0;
        $seenUpns = [];
        $renamed  = 0;
        $merged   = 0;
        $merger   = app(HolderMergeService::class);

        foreach ($users as $u) {
            if (empty($u['userPrincipalName'])) continue;

            // Umbenennung im AD (#986): erst über die Entra-Objekt-ID den bestehenden Träger finden und
            // umbenennen — sonst entstünde für die neue UPN ein zweiter Träger, und der alte würde stillgelegt.
            if (! empty($u['id'])) {
                $follow   = $merger->followRename($teamId, $tenantId, (string) $u['id'], (string) $u['userPrincipalName']);
                $renamed += $follow['renamed'] ? 1 : 0;
                $merged  += $follow['merged'];
            }

            $holder = $holders->findOrCreateByUpn(
                $teamId,
                $tenantId,
                $u['userPrincipalName'],
                $u['displayName'] ?? null,
                'graph'
            );
            if ($holder->source === 'derived') {
                $holder->source = 'graph';
            }

            // Graph-Profil (department/jobTitle/Rufnummern) anreichern — gemeinsame Precedence-Logik (ADR 0014).
            $holders->applyGraphProfile($holder, $u);

            // Kontostatus übernehmen. Fehlt das Feld (alter Graph-Cache, eingeschränkte Berechtigung),
            // bleibt der bisherige Wert stehen — lieber nichts wissen als fälschlich deaktivieren.
            if (array_key_exists('accountEnabled', $u)) {
                $holder->is_active = (bool) $u['accountEnabled'];
            }

            $holder->graph_id  = $u['id'] ?? $holder->graph_id;
            $holder->synced_at = now();
            $holder->save();

            $seenUpns[] = mb_strtolower((string) $holder->user_principal_name);
            $count++;
        }

        $deactivated = $this->reconcileHolders($teamId, $tenantId, $seenUpns);

        // Nachweis, dass der Abgleich lief — die Weiterberechnung zählt `is_active`, und ein still
        // ausgefallener Abgleich rechnete Ausgeschiedene weiter ab (#954).
        app(ImportRunRecorder::class)->record(AssetImportRun::SOURCE_DIRECTORY, $teamId, $tenantId,
            ['count' => $count, 'deactivated' => $deactivated, 'renamed' => $renamed, 'merged' => $merged], null, false, null, $startedAt);

        Log::info('AssetManager: Tenant-User-Import abgeschlossen', [
            'connector_id' => $this->connectorId,
            'tenant_id'    => $tenantId,
            'count'        => $count,
            'deactivated'  => $deactivated,
        ]);
    }

    /**
     * Träger stilllegen, die das Verzeichnis nicht mehr kennt.
     *
     * **Deaktivieren, nicht löschen.** Ein ausgeschiedener Mensch bleibt Teil der Historie: an ihm
     * hängen Übergabeprotokolle, Zuordnungsverläufe und Kostenzeilen vergangener Monate. Gelöscht
     * wären die Auswertungen der Vorjahre still falsch.
     *
     * Drei Sicherungen, weil ein falsch laufender Reconcile schlimmer ist als gar keiner:
     *
     * 1. **Leere Antwort tut nichts.** Ein erfolgreicher Abruf mit `value: []` ist syntaktisch
     *    gültig, aber fachlich ein Tenant-Glitch — er würde sonst den ganzen Bestand stilllegen.
     *    (Fehlerhafte Abrufe sind oben schon abgefangen: `$users === null`.)
     * 2. **Nur `source = 'graph'`.** Abgeleitete Träger stammen aus Excel-Import, Gerätezuordnung
     *    oder Rechnungen — das Verzeichnis hat sie nie gekannt und kann sie nicht bestätigen.
     * 3. **Nur bereits aktive.** Wer schon inaktiv ist, wird nicht erneut angefasst.
     *
     * Der Abgleich läuft in PHP und nicht als `whereNotIn`: UPNs kommen in gemischter Schreibweise,
     * und MySQL vergleicht ohne Rücksicht darauf, SQLite aber schon — derselbe Lauf käme lokal und
     * live sonst auf verschiedene Ergebnisse.
     *
     * @param  list<string>  $seenUpns  kleingeschriebene UPNs aus der Graph-Antwort
     * @return int Anzahl stillgelegter Träger
     */
    protected function reconcileHolders(int $teamId, int $tenantId, array $seenUpns): int
    {
        if ($seenUpns === []) {
            Log::warning('AssetManager: Reconcile übersprungen — Graph lieferte keine Benutzer', [
                'connector_id' => $this->connectorId,
                'tenant_id'    => $tenantId,
            ]);

            return 0;
        }

        $seen = array_flip($seenUpns);

        $stale = \Platform\AssetManager\Models\AssetHolder::withoutTenantScope()
            ->forTenant($tenantId)
            ->where('team_id', $teamId)
            ->where('source', 'graph')
            ->where('is_active', true)
            ->get(['id', 'user_principal_name'])
            ->reject(fn ($holder) => isset($seen[mb_strtolower((string) $holder->user_principal_name)]))
            ->pluck('id');

        if ($stale->isEmpty()) {
            return 0;
        }

        \Platform\AssetManager\Models\AssetHolder::withoutTenantScope()
            ->whereIn('id', $stale)
            ->update(['is_active' => false]);

        Log::info('AssetManager: Träger stillgelegt (nicht mehr im Verzeichnis)', [
            'tenant_id' => $tenantId,
            'count'     => $stale->count(),
        ]);

        return $stale->count();
    }

    /** Unerwarteter Abbruch (Exception, Timeout): ins Import-Protokoll, damit er nicht nur in failed_jobs steht. */
    public function failed(\Throwable $e): void
    {
        try {
            $config = AssetConnectorConfig::find($this->connectorId);
            if ($config && $config->tenant_id) {
                app(ImportRunRecorder::class)->recordFailure(AssetImportRun::SOURCE_DIRECTORY, (int) $config->team_id,
                    (int) $config->tenant_id, 'Verzeichnis-Abgleich abgebrochen: ' . $e->getMessage(), null, false, null);
            }
        } catch (\Throwable) {
            // Protokollieren darf das Fehlschlagen nicht verdecken.
        }
    }
}
