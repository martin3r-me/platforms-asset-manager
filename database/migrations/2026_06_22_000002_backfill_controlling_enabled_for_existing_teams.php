<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bestandsschutz (ADR 0008): Der Default für controlling_enabled ist false. Damit Teams, die das
 * Controlling bereits nutzen (insb. BROICH), nicht plötzlich ihre Auswertungen verlieren, wird das
 * Flag für jedes Team mit vorhandenen Controlling-Daten auf true gesetzt.
 *
 * Indiz für „nutzt Controlling": existierende Kostenpositionen ODER Kostenarten mit einer anderen
 * Quelle als der reinen Hardware-AfA (= manuell gepflegtes/importiertes Kostenmodell). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('asset_team_settings')) {
            return;
        }

        $teamIds = collect();

        if (Schema::hasTable('asset_cost_lines')) {
            $teamIds = $teamIds->merge(
                DB::table('asset_cost_lines')->distinct()->pluck('team_id')
            );
        }

        if (Schema::hasTable('asset_cost_types')) {
            $teamIds = $teamIds->merge(
                DB::table('asset_cost_types')
                    ->where('aggregation_source', '!=', 'hardware_afa')
                    ->distinct()
                    ->pluck('team_id')
            );
        }

        $now = now();

        foreach ($teamIds->filter()->unique() as $teamId) {
            DB::table('asset_team_settings')->updateOrInsert(
                ['team_id' => $teamId],
                ['controlling_enabled' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        // Reine Daten-Migration (kein Schema-Change) — bewusst kein Rollback.
    }
};
