<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Services\EmployeeService;
use Platform\AssetManager\Services\IntuneGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportTenantUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            self::dispatch($config->id);
            $count++;
        }
        return $count;
    }

    public function handle(IntuneGraphService $graph, EmployeeService $employees): void
    {
        $config = AssetConnectorConfig::where('id', $this->connectorId)
            ->where('enabled', true)
            ->first();
        if (!$config || !$config->isConfigured() || !$config->tenant_id) {
            Log::warning('AssetManager: Import übersprungen — Connector nicht konfiguriert/ohne Tenant', ['connector_id' => $this->connectorId]);
            return;
        }

        $teamId   = $config->team_id;
        $tenantId = $config->tenant_id;

        Log::info('AssetManager: Tenant-User-Import gestartet', ['connector_id' => $this->connectorId, 'tenant_id' => $tenantId]);

        $users = $graph->getUsersWithLicenses($config);
        if ($users === null) {
            Log::error('AssetManager: Tenant-User-Import fehlgeschlagen', [
                'connector_id' => $this->connectorId,
                'error'        => $graph->lastError ?? 'unbekannt',
            ]);
            $config->update(['sync_error' => 'Tenant-User-Import: ' . ($graph->lastError ?? 'unbekannt')]);
            return;
        }

        $count = 0;
        foreach ($users as $u) {
            if (empty($u['userPrincipalName'])) continue;
            $employee = $employees->findOrCreateByUpn(
                $teamId,
                $tenantId,
                $u['userPrincipalName'],
                $u['displayName'] ?? null,
                'graph'
            );
            if ($employee->source === 'derived') {
                $employee->source = 'graph';
            }

            // Entra ist führend für HR-Stammdaten: nicht-leere Graph-Werte überschreiben Abteilung/Position
            // bei JEDEM Sync (ADR 0014). Leere Graph-Werte lassen den Bestand unangetastet, damit ein
            // lückenhaft gepflegtes Entra keine gepflegten Daten löscht.
            if (! empty($u['department'])) {
                $employee->department = $u['department'];
            }
            if (! empty($u['jobTitle'])) {
                $employee->job_title = $u['jobTitle'];
            }

            // Rufnummern aus Entra — nur solange NICHT manuell übersteuert (phone_overridden, ADR 0014).
            // businessPhones ist ein Array; wir übernehmen die erste Nummer. Leere Werte überschreiben nichts.
            if (! $employee->phone_overridden) {
                if (! empty($u['mobilePhone'])) {
                    $employee->mobile_phone = $u['mobilePhone'];
                }
                if (! empty($u['businessPhones'][0])) {
                    $employee->business_phone = $u['businessPhones'][0];
                }
            }

            $employee->graph_id  = $u['id'] ?? $employee->graph_id;
            $employee->synced_at = now();
            $employee->save();
            $count++;
        }

        Log::info('AssetManager: Tenant-User-Import abgeschlossen', [
            'connector_id' => $this->connectorId,
            'tenant_id'    => $tenantId,
            'count'        => $count,
        ]);
    }
}
