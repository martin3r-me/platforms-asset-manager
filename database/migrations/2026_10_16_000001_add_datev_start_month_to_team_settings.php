<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Umstellungsmonat der DATEV-Umbuchung je Tenant (Discussion #26, docs/adr/0027).
 *
 * Bis zur Umstellung verteilt die Buchhaltung die IT-Rechnungen selbst je Kostenstelle (Hendric,
 * 2026-10-16: Vodafone September noch vorverteilt in DATEV KOST). Ein Stapel für einen dieser Monate
 * buchte doppelt. `datev_start_month` ist der erste Leistungsmonat, den der Asset Manager umbucht;
 * Monatsstände davor gelten als „vorverteilt" — nie offen, nie im Stapel. null = keine Grenze.
 *
 * **Erstbefüllung:** Oktober 2026 für Tenants mit gepflegtem Stapelkopf (Mandant gesetzt) — der
 * vorgeschlagene Umstellungsmonat; vorsichtig gewählt, weil ein zu früher Monat doppelt bucht, ein zu
 * später nur eine Umbuchung zurückhält. Änderbar in den Einstellungen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_team_settings')) {
            return;
        }

        MigrationSchema::addColumn('asset_team_settings', 'datev_start_month', fn (Blueprint $t) => $t->date('datev_start_month')->nullable());

        if (Schema::hasColumn('asset_team_settings', 'datev_client')) {
            DB::table('asset_team_settings')
                ->whereNotNull('datev_client')
                ->whereNull('datev_start_month')
                ->update(['datev_start_month' => '2026-10-01']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_team_settings') && Schema::hasColumn('asset_team_settings', 'datev_start_month')) {
            Schema::table('asset_team_settings', fn (Blueprint $t) => $t->dropColumn('datev_start_month'));
        }
    }
};
