<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Zwei Voraussetzungen der Verteilungs-Kaskade.
 *
 * **1. Funktionskonten brauchen ein System.** Der Träger-Typ „Funktionskonto" existiert bereits
 * (`holder_type = 'function'`, ADR 0017) und trägt schon eine Kostenstelle. Was fehlt, ist das
 * zugehörige System: ein Funktionskonto ohne benanntes System ist nicht bewirtschaftbar — niemand
 * weiß, wofür die Lizenz läuft und wen man bei einer Verlängerung fragt. Zusammen mit der
 * Kostenstelle sind das die zwei Pflichtangaben, ohne die die Kaskade ein Funktionskonto nicht
 * bevorzugt behandelt.
 *
 * Das Feld ist bewusst ein Freitext und keine Fremdbeziehung: „das System" ist bei einem Kunden ein
 * Fachverfahren, beim nächsten eine Maschine oder ein Postfach. Eine Katalogtabelle dafür wäre eine
 * Kundenkonvention im Schema.
 *
 * **2. Restmengen brauchen ein Ziel.** Die Kaskade bucht Mengen, die keinem Träger gehören, auf zwei
 * Sammel-Kostenstellen: den Überhang an Monatslizenzen und den unbesetzten Rest. Welche Kostenstellen
 * das sind, ist eine Kundenentscheidung („IT-Gemeinkosten", „Lizenzpool IT" sind Namen *eines*
 * Kunden) und gehört deshalb pro Tenant in die Moduleinstellungen, nicht in den Code — dieselbe
 * Leitplanke, aus der schon `holder_type_rules` konfigurierbar ist.
 *
 * Ohne gesetzte Kostenstellen verteilt die Kaskade weiter, weist die Restmengen aber als „ohne
 * Kostenstelle" aus, statt Beträge stillschweigend zu unterschlagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_holders')) {
            MigrationSchema::addColumn('asset_holders', 'function_system', function (Blueprint $table) {
                $table->string('function_system')->nullable()->after('holder_type');
            });
        }

        if (! Schema::hasTable('asset_team_settings')) {
            return;
        }

        // Ziel für Monats-Seats, die über den Bedarf an Funktionskonten hinausgehen.
        MigrationSchema::addColumn('asset_team_settings', 'license_overflow_cost_center_id', function (Blueprint $table) {
            $table->unsignedBigInteger('license_overflow_cost_center_id')->nullable();
        });

        // Ziel für bezahlte, aber unbesetzte Seats.
        MigrationSchema::addColumn('asset_team_settings', 'license_pool_cost_center_id', function (Blueprint $table) {
            $table->unsignedBigInteger('license_pool_cost_center_id')->nullable();
        });

        if (! Schema::hasTable('asset_cost_centers')) {
            return;
        }

        // nullOnDelete: wird eine als Ziel gesetzte Kostenstelle gelöscht, fällt die Einstellung auf
        // „nicht gesetzt" zurück. Ein FK-Fehler beim Löschen einer Kostenstelle wäre für den
        // Pflegenden nicht nachvollziehbar, ein stiller Verweis auf eine gelöschte Zeile schlimmer.
        Schema::table('asset_team_settings', function (Blueprint $table) {
            foreach (['license_overflow_cost_center_id', 'license_pool_cost_center_id'] as $column) {
                $table->foreign($column)->references('id')->on('asset_cost_centers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_team_settings')) {
            foreach (['license_overflow_cost_center_id', 'license_pool_cost_center_id'] as $column) {
                if (Schema::hasColumn('asset_team_settings', $column)) {
                    MigrationSchema::dropForeignIfExists('asset_team_settings', $column);

                    Schema::table('asset_team_settings', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('asset_holders') && Schema::hasColumn('asset_holders', 'function_system')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                $table->dropColumn('function_system');
            });
        }
    }
};
