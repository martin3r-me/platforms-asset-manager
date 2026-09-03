<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Belegfelder am Abrechnungsprofil, die easybill nicht selbst füllt.
 *
 * Aus dem Vergleich mit den von Hand erstellten Rechnungen: Einleitungstext, Zahlungsbedingung und
 * Kundenadresse zieht easybill aus der Kontovorlage — **Auftragsnummer** und **Zahlungsziel** nicht.
 * Beide standen dort von Hand drin.
 *
 * Nullable und ohne Vorbelegung: leer heißt „nicht mitsenden", und dann gilt weiterhin, was easybill
 * beim Festschreiben selbst einsetzt. Ein geratener Standardwert wäre schlechter als keiner — ein
 * falsches Zahlungsziel fällt erst auf, wenn die Mahnung zu früh kommt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_billing_profiles')) {
            return;
        }

        Schema::table('asset_billing_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_billing_profiles', 'order_number')) {
                $table->string('order_number')->nullable()->after('easybill_customer_name');
            }

            if (! Schema::hasColumn('asset_billing_profiles', 'due_in_days')) {
                $table->unsignedSmallInteger('due_in_days')->nullable()->after('order_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_billing_profiles', function (Blueprint $table) {
            foreach (['order_number', 'due_in_days'] as $column) {
                if (Schema::hasColumn('asset_billing_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
