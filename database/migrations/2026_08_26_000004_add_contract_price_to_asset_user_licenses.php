<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Jeder Lizenz-Seat trägt den Preis **seiner** Vertragszeile.
 *
 * Die Verteilungs-Kaskade weist jeder Zuweisung eine Vertragszeile zu und schreibt deren Preis hier
 * fest. Damit steht an jedem Seat ein Betrag, der wirklich auf einer Rechnung steht — nachprüfbar
 * über `contract_line_id` bis zur Position und zum Beleg.
 *
 * `contract_line_id` hat eine zweite, wichtigere Aufgabe als die Rückverfolgung: sie macht die
 * Verteilung **stabil**. Graph verrät nicht, welche Person auf welchem Tarif sitzt, die Zuordnung ist
 * also gesetzt. Wäre sie bei jedem Import neu gesetzt, würden Kosten je Kostenstelle ohne fachlichen
 * Anlass springen — eine Abteilung wäre im Juli teuer und im August billig, nur weil ein Seat den
 * Tarif gewechselt hat. Der Importer bevorzugt deshalb die bestehende Zuordnung, solange das
 * Kontingent der Zeile reicht, und verteilt nur den Rest neu.
 *
 * `asset_license_skus.unit_price` bleibt als handgepflegter Preis für Lizenzen, die auf keiner
 * Rechnung stehen (Self-Service- und Gratis-SKUs wie FLOW_FREE oder POWER_BI_STANDARD). Deckt die
 * Rechnung eine SKU ab, gilt dort ausschließlich der Vertragszeilen-Preis — sonst gäbe es zu einer
 * Lizenz zwei Preisquellen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_user_licenses')) {
            return;
        }

        // Vier Nachkommastellen, weil Stückpreise sie brauchen: 2,5586 € für Exchange Online
        // Archiving. Auf zwei gerundet driftet die Summe über 131 Seats um mehrere Euro.
        MigrationSchema::addColumn('asset_user_licenses', 'unit_price', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 4)->nullable()->after('assigned_at');
        });

        MigrationSchema::addColumn('asset_user_licenses', 'price_source', function (Blueprint $table) {
            $table->string('price_source', 20)->nullable()->after('unit_price');
        });

        MigrationSchema::addColumn('asset_user_licenses', 'price_period', function (Blueprint $table) {
            $table->string('price_period', 7)->nullable()->after('price_source');
        });

        MigrationSchema::addColumn('asset_user_licenses', 'contract_line_id', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_line_id')->nullable()->after('price_period');
        });

        MigrationSchema::ensureIndex(
            'asset_user_licenses',
            ['contract_line_id'],
            'asset_user_licenses_contract_line_index',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_user_licenses')) {
            return;
        }

        foreach (['unit_price', 'price_source', 'price_period', 'contract_line_id'] as $column) {
            if (Schema::hasColumn('asset_user_licenses', $column)) {
                Schema::table('asset_user_licenses', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
