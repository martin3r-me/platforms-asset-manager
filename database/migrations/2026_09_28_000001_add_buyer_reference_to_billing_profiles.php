<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Käuferreferenz am Abrechnungsprofil — in der E-Rechnung (ZUGFeRD/EN 16931) das Feld BT-10.
 *
 * Anders als Auftragsnummer und Zahlungsziel füllt easybill dieses Feld **selbst**: aus dem
 * Kundenstamm, und zwar auf jeder Rechnung an diesen Kunden. Das ist für die meisten Rechnungen
 * richtig und soll so bleiben. Das Profilfeld ist deshalb nur für die Abweichung da — eine Rechnung
 * aus dem Asset Manager, die beim Kunden unter einer anderen Referenz gebucht wird als der Rest.
 *
 * Nullable und ohne Vorbelegung: leer heißt „nicht mitsenden", und dann gilt der Kundenstandard. Den
 * Kundenstamm umzustellen wäre keine Lösung — er gilt für alle anderen Rechnungen an denselben Kunden mit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_billing_profiles')) {
            return;
        }

        Schema::table('asset_billing_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_billing_profiles', 'buyer_reference')) {
                $table->string('buyer_reference')->nullable()->after('order_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_billing_profiles')) {
            return;
        }

        Schema::table('asset_billing_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('asset_billing_profiles', 'buyer_reference')) {
                $table->dropColumn('buyer_reference');
            }
        });
    }
};
