<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Träger-Typisierung (siehe docs/adr/0017).
 *
 * `account_type` kannte genau einen Wert (`'function'`) und deckte damit nicht ab, was tatsächlich in
 * der Tabelle steht: Externe, Admin-/Verwaltungsaccounts und Nicht-Menschen als Lizenzträger. Ein
 * KI-Modell liest „Mitarbeiter" wörtlich und zählt jeden Eintrag als Person → falsche Auswertungen.
 *
 * Neu: `holder_type` ∈ {person, function, admin, service, external}. Bestand: `function` bleibt
 * `function`, alles übrige wird `person`. Die alte Spalte bleibt zunächst als Sicherheitsnetz stehen
 * (Lesepfade nutzen ab sofort holder_type) — sie fällt in einer Folge-Migration, wenn der Live-Stand
 * bestätigt ist. Bewusst kein Drop im gleichen Zug: ein Rollback des Deploys soll nicht bedeuten,
 * dass die Typinformation weg ist.
 *
 * Dazu `holder_type_rules` an den Moduleinstellungen: die Klassifizierungsregeln (Präfix-/Muster-Liste)
 * werden **pro Tenant** gepflegt. Eine Regel wie „alles mit `adm-` ist ein Admin" ist eine
 * Kundenkonvention und gehört nicht in den Code (Multi-Tenant-Leitplanke).
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchema::addColumn('asset_employees', 'holder_type', function (Blueprint $t) {
            $t->string('holder_type', 20)->default('person')->after('account_type');
            $t->index('holder_type');
        });

        if (Schema::hasColumn('asset_employees', 'account_type')) {
            DB::table('asset_employees')
                ->where('account_type', 'function')
                ->update(['holder_type' => 'function']);
        }

        MigrationSchema::addColumn('asset_team_settings', 'holder_type_rules', function (Blueprint $t) {
            // [{ "type": "admin", "match": "prefix"|"suffix"|"contains", "value": "adm-", "field": "upn" }, …]
            $t->json('holder_type_rules')->nullable()->after('controlling_enabled');
        });
    }

    public function down(): void
    {
        foreach ([['asset_employees', 'holder_type'], ['asset_team_settings', 'holder_type_rules']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
