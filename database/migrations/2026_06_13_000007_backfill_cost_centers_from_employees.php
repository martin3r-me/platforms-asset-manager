<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\CostBootstrapService;

return new class extends Migration
{
    /**
     * Seedet Stammdaten (Gesellschaften, Kreditoren, Kostenarten) je Team und backfillt
     * Kostenstellen aus den vorhandenen Employee-Strings. Idempotent.
     */
    public function up(): void
    {
        if (
            !Schema::hasTable('asset_employees') ||
            !Schema::hasTable('asset_cost_centers') ||
            !Schema::hasTable('asset_cost_types')
        ) {
            return;
        }

        // Teams, die das Modul bereits genutzt haben
        $teamIds = collect();
        foreach (['asset_employees', 'asset_devices', 'asset_connector_configs'] as $tbl) {
            if (Schema::hasTable($tbl)) {
                $teamIds = $teamIds->merge(DB::table($tbl)->distinct()->pluck('team_id'));
            }
        }
        $teamIds = $teamIds->filter()->unique();

        if ($teamIds->isEmpty()) {
            return;
        }

        $service = new CostBootstrapService();

        foreach ($teamIds as $teamId) {
            $service->seedForTeam((int) $teamId);
            $stats = $service->backfillCostCenters((int) $teamId);

            if (!empty($stats['unmapped'])) {
                Log::info('[asset-manager] Kostenstellen ohne Gesellschaft-Zuordnung', [
                    'team_id' => $teamId,
                    'codes'   => $stats['unmapped'],
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nicht reversibel — Stammdaten/Kostenstellen bleiben erhalten
    }
};
