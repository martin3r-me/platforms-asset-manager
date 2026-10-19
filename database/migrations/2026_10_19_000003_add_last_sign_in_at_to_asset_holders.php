<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letzte erfolgreiche Anmeldung des Trägers aus Entra (#970) — Basis für den Lizenz-Reclaim
 * („Lizenz, aber seit 90 Tagen keine Anmeldung"). Bleibt leer, solange Graph die Daten nicht liefert
 * (AuditLog.Read.All + Entra ID P1); dann taucht schlicht niemand in der Reclaim-Liste auf.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_holders') && ! Schema::hasColumn('asset_holders', 'last_sign_in_at')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                $table->timestamp('last_sign_in_at')->nullable()->after('synced_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_holders') && Schema::hasColumn('asset_holders', 'last_sign_in_at')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                $table->dropColumn('last_sign_in_at');
            });
        }
    }
};
