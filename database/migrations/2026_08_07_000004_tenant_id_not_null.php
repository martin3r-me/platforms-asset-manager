<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Tenant-Welle 4/5 — `tenant_id` DB-seitig erzwingen (siehe docs/adr/0016).
 *
 * Damit ist „kein Datensatz ohne Tenant" eine Datenbank-Zusage und nicht nur Applikationslogik: der
 * Global Scope aus S2 kann sich darauf verlassen, dass jede Zeile einen Tenant hat — eine Zeile mit
 * NULL wäre sonst für jede tenant-gefilterte Query unsichtbar und würde still verschwinden.
 *
 * BEWUSSTE AUSNAHMEN (bleiben nullable):
 *
 *  - `asset_device_sync_logs`, `asset_license_sync_logs` — tragen `tenant_id` per **nullOnDelete**
 *    (2026_06_20_000002, ADR 0003): sie sollen als Betriebs-/Nachweis-Historie ein Tenant-Löschen
 *    ÜBERLEBEN (tenant_id = NULL). NOT NULL würde diese Entscheidung umkehren und die Migration
 *    scheitern lassen, sobald ein Tenant je gelöscht wurde. Sie enthalten keine PII.
 *  - `asset_connector_configs` — trägt heute ein `unique(tenant_id)`, das NULLs als distinct behandelt.
 *    Ein Backfill mehrerer connector-loser Zeilen desselben Teams auf denselben Default-Tenant liefe in
 *    genau diesen Unique. Die Connector-Uniques werden ohnehin mit ADR 0009 (max. einer je Provider)
 *    neu geschnitten — dort gehört die Verschärfung hin, nicht hierher.
 */
return new class extends Migration
{
    /** Tabellen, deren tenant_id ab jetzt Pflicht ist. */
    private array $tables = [
        // Inventar (tenant_id seit 2026_06_17_000004, bisher nullable)
        'asset_devices',
        'asset_user_licenses',
        'asset_license_skus',
        'asset_device_events',
        'asset_employees',
        'asset_items',
        'asset_handovers',
        // Kostenmodell + Verknüpfungen (tenant_id seit Welle 1/5 dieser Reihe)
        'asset_cost_centers',
        'asset_cost_types',
        'asset_vendors',
        'asset_cost_lines',
        'asset_assignments',
        'asset_handover_lines',
        'asset_team_settings',
    ];

    public function up(): void
    {
        $this->backfillStragglers();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            MigrationSchema::tenantColumnNotNull($table);
        }
    }

    /**
     * Letztes Netz vor der Verschärfung: Zeilen, die die Backfills der Vorwellen nicht erreicht haben,
     * landen im Default-Tenant ihres Teams. Betrifft Zeilen, die zwischen den Migrationen entstanden
     * sind, sowie Tabellen ohne `team_id`, deren Elterndatensatz selbst noch keinen Tenant hatte.
     */
    private function backfillStragglers(): void
    {
        // Erneut erben — die Elterndatensätze haben inzwischen garantiert einen Tenant.
        MigrationSchema::inheritTenantFrom('asset_assignments', 'employee_id', 'asset_employees');
        MigrationSchema::inheritTenantFrom('asset_assignments', 'asset_item_id', 'asset_items');
        MigrationSchema::inheritTenantFrom(
            'asset_assignments', 'assignable_id', 'asset_devices', 'assignable_type', 'device'
        );
        MigrationSchema::inheritTenantFrom('asset_handover_lines', 'handover_id', 'asset_handovers');
        MigrationSchema::inheritTenantFrom('asset_handover_lines', 'asset_device_id', 'asset_devices');

        // Tabellen mit team_id: Default-Tenant des Teams.
        $withTeam = array_values(array_filter(
            $this->tables,
            fn ($table) => Schema::hasTable($table) && Schema::hasColumn($table, 'team_id'),
        ));

        $defaultByTeam = MigrationSchema::defaultTenantIdsByTeam(
            MigrationSchema::teamIdsAcross($withTeam)
        );

        foreach ($withTeam as $table) {
            foreach ($defaultByTeam as $teamId => $tenantId) {
                $touched = DB::table($table)
                    ->where('team_id', $teamId)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenantId]);

                if ($touched > 0) {
                    Log::info('[asset-manager] Tenant-Welle: Nachzügler gebackfillt', [
                        'table' => $table, 'team_id' => $teamId, 'rows' => $touched,
                    ]);
                }
            }
        }

        // Waisen in Tabellen OHNE team_id sind nicht automatisch auflösbar (kein Team ableitbar).
        // Sie hart zu löschen wäre Datenverlust, sie stehen zu lassen bricht die Verschärfung —
        // deshalb bewusst laut: tenantColumnNotNull() wirft mit Tabellenname und Anzahl.
        foreach (['asset_assignments', 'asset_handover_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $orphans = DB::table($table)->whereNull('tenant_id')->count();
            if ($orphans > 0) {
                Log::warning('[asset-manager] Tenant-Welle: Waisen ohne ableitbaren Tenant', [
                    'table' => $table, 'rows' => $orphans,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, fn (Blueprint $t) =>
                $t->unsignedBigInteger('tenant_id')->nullable()->change());
        }
    }
};
