<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1.2 — Inventar an den Tenant binden (siehe docs/adr/0003): jedes Inventar-Objekt bekommt
 * einen tenant_id-FK auf asset_tenants. Vorerst nullable (NOT NULL kommt, wenn die Anlege-Pfade
 * in M2/M3 den Tenant garantieren). Bestand wird auf je einen Default-Tenant des Teams gebackfillt.
 *
 * tenant_id-FK trägt cascadeOnDelete: Tenant löschen entfernt sein Inventar (F10) — Connector
 * trennen/deaktivieren lässt die Daten dagegen unberührt (nur Tenant-Löschen ist destruktiv).
 */
return new class extends Migration
{
    /** Inventar-Tabellen, die an einen Tenant gebunden werden (alle tragen team_id). */
    private array $tables = [
        'asset_devices',
        'asset_user_licenses',
        'asset_license_skus',
        'asset_device_sync_logs',
        'asset_license_sync_logs',
        'asset_device_events',
        'asset_employees',
        'asset_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            // Idempotent: FK-Spalte je Tabelle nur ergänzen, wenn noch nicht vorhanden (übersteht einen
            // Teil-Abbruch dieser 8-Tabellen-Schleife auf MySQL und ist beliebig wiederholbar).
            if (Schema::hasColumn($name, 'tenant_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('team_id')
                    ->constrained('asset_tenants')->cascadeOnDelete();
            });
        }

        // Default-Tenant je Team mit Inventar sicherstellen (idempotent; nutzt den ggf. in 000002
        // bereits angelegten Default-Tenant wieder, legt für Teams ohne Connector einen an).
        $teamIds = collect();
        foreach ($this->tables as $name) {
            $teamIds = $teamIds->merge(DB::table($name)->distinct()->pluck('team_id'));
        }
        $teamIds = $teamIds->filter()->unique();

        $defaultByTeam = [];
        foreach ($teamIds as $teamId) {
            $tenantId = DB::table('asset_tenants')
                ->where('team_id', $teamId)
                ->where('is_default', true)
                ->value('id');

            if (! $tenantId) {
                $tenantId = DB::table('asset_tenants')->insertGetId([
                    'team_id'    => $teamId,
                    'name'       => 'Standard',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $defaultByTeam[$teamId] = $tenantId;
        }

        // Bestand auf den Default-Tenant des jeweiligen Teams backfillen.
        foreach ($this->tables as $name) {
            foreach ($defaultByTeam as $teamId => $tenantId) {
                DB::table($name)
                    ->where('team_id', $teamId)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenantId]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasColumn($name, 'tenant_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
