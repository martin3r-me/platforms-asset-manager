<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Herkunft des Lizenz-Stückpreises.
 *
 * Bis jetzt gab es genau eine Quelle für `unit_price`: die Hand. Der Graph-Sync fasst das Feld
 * bewusst nie an. Mit dem Rechnungsimport kommt eine zweite, belegte Quelle hinzu — und damit die
 * Frage, die man einem nackten Betrag nicht mehr ansehen kann: steht da ein Schätzwert von vor
 * einem Jahr oder der Preis aus der Juli-Rechnung?
 *
 * `price_source` beantwortet das, `price_period` sagt aus welchem Abrechnungsmonat. Beides ist
 * Anzeige- und Nachweisinformation; gerechnet wird weiter nur mit `unit_price`, damit die
 * Kostenaufteilung unverändert bleibt.
 *
 * Backfill: alles, was heute einen Preis trägt, ist per Definition handgepflegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_license_skus')) {
            return;
        }

        MigrationSchema::addColumn('asset_license_skus', 'price_source', function (Blueprint $table) {
            $table->string('price_source', 20)->nullable()->after('unit_price');
        });

        MigrationSchema::addColumn('asset_license_skus', 'price_period', function (Blueprint $table) {
            // Abrechnungsmonat als 'YYYY-MM' — genau die Auflösung, in der abgerechnet wird.
            $table->string('price_period', 7)->nullable()->after('price_source');
        });

        MigrationSchema::addColumn('asset_license_skus', 'price_updated_at', function (Blueprint $table) {
            $table->timestamp('price_updated_at')->nullable()->after('price_period');
        });

        DB::table('asset_license_skus')
            ->whereNotNull('unit_price')
            ->whereNull('price_source')
            ->update(['price_source' => 'manual']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_license_skus')) {
            return;
        }

        foreach (['price_source', 'price_period', 'price_updated_at'] as $column) {
            if (Schema::hasColumn('asset_license_skus', $column)) {
                Schema::table('asset_license_skus', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
