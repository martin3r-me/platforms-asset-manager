<?php

namespace Platform\AssetManager\Console\Commands;

use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Illuminate\Console\Command;

class SyncLicensesCommand extends Command
{
    protected $signature = 'asset-manager:sync-licenses {--team= : Nur ein Team synchronisieren}';
    protected $description = 'Synchronisiert Microsoft 365 Lizenz-Daten für alle Teams (oder ein bestimmtes Team)';

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

            SyncLicensesJob::dispatch($config->team_id);
            $this->info("Team {$config->team_id}: Lizenz-Sync-Job dispatched.");
        }
    }
}
