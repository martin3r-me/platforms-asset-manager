<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Bezugsebene der Kostenart: an WAS hängen die Kosten — an einer Person oder an einer Kostenstelle?
 *
 * Vorher gab es dafür `is_per_employee`, ein Ja/Nein-Häkchen, das **nichts erzwang**: man konnte eine
 * „personenbezogene" Kostenart ohne Träger buchen und eine kostenstellenbezogene mit Träger, ohne dass
 * irgendwas widersprach. Entsprechend war das Flag im Bestand teils falsch gesetzt (ChatGPT und
 * MobileIron standen auf personenbezogen, obwohl beide team- bzw. kostenstellenweit abgerechnet werden)
 * — und eine Plausibilitätsprüfung, die darauf aufsetzte, schlug an der falschen Stelle an.
 *
 * `allocation_level` ist ab jetzt die einzige Wahrheit; `is_per_employee` fällt weg und wird im Modell
 * als abgeleiteter Wert nachgebildet, damit bestehende MCP-Antworten ihr Feld behalten.
 */
return new class extends Migration
{
    private const LEVEL_PERSON      = 'person';
    private const LEVEL_COST_CENTER = 'cost_center';

    public function up(): void
    {
        if (! Schema::hasTable('asset_cost_types')) {
            return;
        }

        MigrationSchema::addColumn('asset_cost_types', 'allocation_level', function (Blueprint $table) {
            // String statt enum: eine dritte Ebene (z. B. 'asset' für Internet/Drucker) ist denkbar,
            // und ein enum zu erweitern ist auf MySQL ein Tabellen-Rewrite.
            $table->string('allocation_level', 20)
                ->default(self::LEVEL_COST_CENTER)
                ->after('is_per_employee');
        });

        // Backfill aus dem alten Flag — der Bestand bleibt inhaltlich, wie er war. Die inhaltlichen
        // Korrekturen (ChatGPT, MobileIron) sind fachliche Entscheidungen und gehören NICHT in eine
        // Migration: sie verschieben Beträge in der Auswertung und müssen nachvollziehbar sein.
        if (Schema::hasColumn('asset_cost_types', 'is_per_employee')) {
            DB::table('asset_cost_types')->where('is_per_employee', true)
                ->update(['allocation_level' => self::LEVEL_PERSON]);
            DB::table('asset_cost_types')->where('is_per_employee', false)
                ->update(['allocation_level' => self::LEVEL_COST_CENTER]);

            Schema::table('asset_cost_types', function (Blueprint $table) {
                $table->dropColumn('is_per_employee');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_cost_types')) {
            return;
        }

        MigrationSchema::addColumn('asset_cost_types', 'is_per_employee', function (Blueprint $table) {
            $table->boolean('is_per_employee')->default(false)->after('frequency_default');
        });

        if (Schema::hasColumn('asset_cost_types', 'allocation_level')) {
            DB::table('asset_cost_types')
                ->where('allocation_level', self::LEVEL_PERSON)
                ->update(['is_per_employee' => true]);

            Schema::table('asset_cost_types', function (Blueprint $table) {
                $table->dropColumn('allocation_level');
            });
        }
    }
};
