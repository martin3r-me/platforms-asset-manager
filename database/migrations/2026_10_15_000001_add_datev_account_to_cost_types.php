<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sachkonto an der Kostenart (Discussion #26, DATEV-EXTF-Umbuchung).
 *
 * Die Buchhaltung bucht eine IT-Rechnung komplett auf die Sammel-Kostenstelle und verteilt sie per
 * Buchungsstapel; das Sachkonto bleibt dabei das der Rechnung. Eine Rechnung kann mehrere Sachkonten
 * tragen (Vodafone: Mobilfunk, Telefonie, SaaS), deshalb hängt das Konto an der **Kostenart**, nicht am
 * Kreditor — sonst landete die ganze Rechnung auf einem Konto.
 *
 * Nullable und ohne Vorbelegung: welches Konto zu welcher Kostenart gehört, ist Kontenrahmen des Teams
 * und wird in den Stammdaten gepflegt. Leer heißt „wird nicht per DATEV umgebucht" (z. B. Hardware-AfA).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_cost_types')) {
            return;
        }

        Schema::table('asset_cost_types', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_cost_types', 'datev_account')) {
                $table->string('datev_account', 8)->nullable()->after('allow_negative');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_cost_types')) {
            return;
        }

        Schema::table('asset_cost_types', function (Blueprint $table) {
            if (Schema::hasColumn('asset_cost_types', 'datev_account')) {
                $table->dropColumn('datev_account');
            }
        });
    }
};
