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
        public readonly int $teamId
    ) {}

    public function handle(IntuneGraphService $graph, EmployeeService $employees): void
    {
        $config = AssetConnectorConfig::where('team_id', $this->teamId)->first();
        if (!$config || !$config->isConfigured()) {
            Log::warning('AssetManager: Import übersprungen — Connector nicht konfiguriert', ['team_id' => $this->teamId]);
            return;
        }

        $users = $graph->getUsersWithLicenses($config);
        if ($users === null) {
            Log::error('AssetManager: Tenant-User-Import fehlgeschlagen', [
                'team_id' => $this->teamId,
                'error'   => $graph->lastError ?? 'unbekannt',
            ]);
            $config->update(['sync_error' => 'Tenant-User-Import: ' . ($graph->lastError ?? 'unbekannt')]);
            return;
        }

        $count = 0;
        foreach ($users as $u) {
            if (empty($u['userPrincipalName'])) continue;
            $employee = $employees->findOrCreateByUpn(
                $this->teamId,
                $u['userPrincipalName'],
                $u['displayName'] ?? null,
                'graph'
            );
            if ($employee->source === 'derived') {
                $employee->source = 'graph';
            }
            $employee->graph_id  = $u['id'] ?? $employee->graph_id;
            $employee->synced_at = now();
            $employee->save();
            $count++;
        }

        Log::info('AssetManager: Tenant-User-Import abgeschlossen', [
            'team_id' => $this->teamId,
            'count'   => $count,
        ]);
    }
}
