<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Macht (team_id, import_hash) eindeutig — härtet die Import-Idempotenz (Phase-A-Upsert + Prune) per
 * DB-Constraint: ein paralleler Lost-Update-Race kann keine stille Dublette mehr erzeugen, sondern läuft
 * in eine abfangbare Constraint-Verletzung (die Import-Transaktion rollt dann zurück).
 *
 * Kein WHERE/partieller Index nötig (MySQL kennt keinen): MySQL/Postgres/SQLite behandeln NULL in einem
 * UNIQUE-Index als distinkt — manuelle Cost-Lines (import_hash IS NULL) dürfen also weiterhin beliebig oft
 * vorkommen, die Eindeutigkeit greift nur für gesetzte import_hash. Bestehende Dubletten werden vorher
 * bereinigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Duplikate (gleiche team_id+import_hash, hash gesetzt) bereinigen — inkl. soft-deleted Zeilen
        // (DB::table sieht alle), sonst schlägt die Index-Erstellung fehl. Jüngste Zeile (MAX id) behalten.
        $dupes = DB::table('asset_cost_lines')
            ->whereNotNull('import_hash')
            ->select('team_id', 'import_hash', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('team_id', 'import_hash')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($dupes as $d) {
            DB::table('asset_cost_lines')
                ->where('team_id', $d->team_id)
                ->where('import_hash', $d->import_hash)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }

        Schema::table('asset_cost_lines', function (Blueprint $table) {
            $table->unique(['team_id', 'import_hash'], 'asset_cost_lines_team_import_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('asset_cost_lines', function (Blueprint $table) {
            $table->dropUnique('asset_cost_lines_team_import_hash_unique');
        });
    }
};
