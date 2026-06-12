<?php

namespace Platform\AssetManager\Console\Commands;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Services\EmployeeService;
use Illuminate\Console\Command;

class BackfillEmployeesCommand extends Command
{
    protected $signature   = 'asset-manager:backfill-employees {--team= : Nur ein bestimmtes Team}';
    protected $description = 'Legt Employees aus existierenden UPNs in asset_devices und asset_user_licenses an';

    public function handle(EmployeeService $service): void
    {
        $teamId = $this->option('team');

        if ($teamId) {
            $created = $service->backfillForTeam((int) $teamId);
            $this->info("Team {$teamId}: {$created} neue Employees angelegt.");
            return;
        }

        $configs = AssetConnectorConfig::all();
        foreach ($configs as $config) {
            $created = $service->backfillForTeam($config->team_id);
            $this->info("Team {$config->team_id}: {$created} neue Employees angelegt.");
        }
    }
}
