<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6/M11 — asset_user_licenses.team_id war als einzige team-gescopte Tabelle ein roher
 * unsignedBigInteger OHNE Foreign-Key (Orphan-Risiko + Inkonsistenz zu allen anderen Tabellen,
 * die team_id mit constrained('teams')->cascadeOnDelete() tragen). Hier idempotent nachgezogen.
 *
 * MySQL-sicher: team_id ist führende Spalte zweier bestehender Indizes
 * (team_id+user_principal_name, team_id+sku_id) → der FK findet eine Stütze, kein Fehler 1553.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_user_licenses') || ! Schema::hasTable('teams')) {
            return;
        }

        // Waisen zuerst bereinigen — sonst scheitert das Anlegen des FK (team_id zeigt auf ein
        // gelöschtes/nicht existentes Team). Solche Zeilen wären über das (team-gescopte) UI/Tooling
        // ohnehin unerreichbar.
        DB::table('asset_user_licenses')
            ->whereNotIn('team_id', fn ($q) => $q->select('id')->from('teams'))
            ->delete();

        if ($this->hasTeamFk()) {
            return;
        }

        Schema::table('asset_user_licenses', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_user_licenses') || ! $this->hasTeamFk()) {
            return;
        }

        Schema::table('asset_user_licenses', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });
    }

    private function hasTeamFk(): bool
    {
        return collect(Schema::getForeignKeys('asset_user_licenses'))
            ->contains(fn ($fk) => in_array('team_id', $fk['columns'] ?? [], true));
    }
};
