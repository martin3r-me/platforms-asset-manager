<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent-Lebenszyklus des Connectors (M2, manueller Consent — siehe docs/adr/0003):
 * `consent_confirmed_at` markiert den Moment, in dem „Anbindung prüfen" erstmals erfolgreich war
 * (Token + Graph-Zugriff erreichbar). Davor gilt der Connector als „Consent ausstehend".
 *
 * Bestehende Connectoren mit nachweislich erfolgtem Sync (last_sync_at gesetzt) gelten direkt als
 * bestätigt — sie sind ja erkennbar konsentiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: Spalte nur ergänzen, wenn noch nicht vorhanden.
        if (! Schema::hasColumn('asset_connector_configs', 'consent_confirmed_at')) {
            Schema::table('asset_connector_configs', function (Blueprint $table) {
                $table->timestamp('consent_confirmed_at')->nullable()->after('sync_error');
            });
        }

        // Backfill: schon einmal erfolgreich gesyncte Connectoren als bestätigt markieren (idempotent).
        \Illuminate\Support\Facades\DB::table('asset_connector_configs')
            ->whereNotNull('last_sync_at')
            ->whereNull('consent_confirmed_at')
            ->update(['consent_confirmed_at' => \Illuminate\Support\Facades\DB::raw('last_sync_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_connector_configs', 'consent_confirmed_at')) {
            Schema::table('asset_connector_configs', function (Blueprint $table) {
                $table->dropColumn('consent_confirmed_at');
            });
        }
    }
};
