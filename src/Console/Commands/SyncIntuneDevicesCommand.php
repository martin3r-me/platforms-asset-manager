<?php

namespace Platform\AssetManager\Console\Commands;

use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Illuminate\Console\Command;

class SyncIntuneDevicesCommand extends Command
{
    protected $signature = 'asset-manager:sync-intune {--team= : Nur ein bestimmtes Team synchronisieren}';
    protected $description = 'Synchronisiert Intune-Gerätedaten für alle Teams (oder ein bestimmtes Team)';

    public function handle(): void
    {
        $teamId = $this->option('team');

        $query = AssetConnectorConfig::where('enabled', true);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $configs = $query->get();

        if ($configs->isEmpty()) {
            $this->info('Keine aktiven Connectors gefunden.');
            return;
        }

        foreach ($configs as $config) {
            if (!$config->isConfigured()) {
                $this->warn("Team {$config->team_id}: Connector nicht vollständig konfiguriert, übersprungen.");
                continue;
            }

            SyncIntuneDevicesJob::dispatch($config->team_id);
            $this->info("Team {$config->team_id}: Sync-Job dispatched.");
        }
    }
}
