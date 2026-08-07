<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Tenant-Welle 1/5 — `tenant_id` auf die bisher rein team-weiten Tabellen (siehe docs/adr/0016).
 *
 * Kehrt die ADR-0003-Entscheidung um, das Kostenmodell aus dem Multi-Tenant-Scope auszuklammern:
 * nichts wird mehr zwischen Tenants geteilt. Bewusst gebündelt mit den Folge-Migrationen derselben
 * Welle, damit `asset_cost_centers` nicht über drei Releases hinweg dreimal angefasst wird.
 *
 * Backfill in zwei Klassen:
 *  - Tabellen MIT `team_id` → Default-Tenant des Teams (Muster aus 2026_06_17_000004).
 *  - Tabellen OHNE `team_id` (`asset_assignments`, `asset_handover_lines`) → Tenant vom verknüpften
 *    Datensatz erben, weil es dort kein Team gibt, aus dem man ihn ableiten könnte.
 *
 * Ausdrücklich NICHT einbezogen:
 *  - `asset_categories` — globaler Typ-Katalog ohne Kundenbezug (kein team_id, key global unique).
 *  - `asset_device_models` — team-weiter Hardware-Katalog; nur seine Kosten werden tenant-scoped
 *    (Welle 3/5).
 */
return new class extends Migration
{
    /** Tabellen mit team_id → Backfill über den Default-Tenant des Teams. */
    private array $teamScoped = [
        'asset_cost_centers',
        'asset_cost_types',
        'asset_vendors',
        'asset_cost_lines',
        'asset_team_settings',
    ];

    public function up(): void
    {
        foreach ($this->teamScoped as $table) {
            MigrationSchema::addTenantColumn($table);
        }

        // asset_assignments: kein team_id (hängt an Item/Gerät + Träger) → tenant_id nach der id.
        MigrationSchema::addTenantColumn('asset_assignments', 'id');
        // asset_handover_lines: kein team_id (hängt am Handover-Kopf) → tenant_id nach handover_id.
        MigrationSchema::addTenantColumn('asset_handover_lines', 'handover_id');

        $this->backfillTeamScoped();
        $this->backfillAssignments();
        $this->backfillHandoverLines();
    }

    /** Bestand je Tabelle auf den Default-Tenant des jeweiligen Teams setzen. */
    private function backfillTeamScoped(): void
    {
        $defaultByTeam = MigrationSchema::defaultTenantIdsByTeam(
            MigrationSchema::teamIdsAcross($this->teamScoped)
        );

        foreach ($this->teamScoped as $table) {
            foreach ($defaultByTeam as $teamId => $tenantId) {
                DB::table($table)
                    ->where('team_id', $teamId)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenantId]);
            }
        }
    }

    /**
     * Zuordnungen erben den Tenant vom Träger; fehlt der (nullable seit dem polymorphen Umbau),
     * vom zugeordneten Item bzw. Gerät. Zeilen, für die alle drei fehlen, bleiben NULL und fliegen in
     * Welle 4/5 als expliziter Fehler auf, statt still ungescoped zu bleiben.
     */
    private function backfillAssignments(): void
    {
        MigrationSchema::inheritTenantFrom('asset_assignments', 'employee_id', 'asset_employees');
        MigrationSchema::inheritTenantFrom('asset_assignments', 'asset_item_id', 'asset_items');
        // Geräte-Zeilen (assignable_type='device') ohne Item: Tenant über das Gerät.
        MigrationSchema::inheritTenantFrom(
            'asset_assignments', 'assignable_id', 'asset_devices', 'assignable_type', 'device'
        );
    }

    /** Übergabe-Positionen erben den Tenant vom Protokoll-Kopf, sonst vom ausgegebenen Gerät. */
    private function backfillHandoverLines(): void
    {
        MigrationSchema::inheritTenantFrom('asset_handover_lines', 'handover_id', 'asset_handovers');
        MigrationSchema::inheritTenantFrom('asset_handover_lines', 'asset_device_id', 'asset_devices');
    }

    public function down(): void
    {
        $tables = array_merge($this->teamScoped, ['asset_assignments', 'asset_handover_lines']);

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            MigrationSchema::dropForeignIfExists($table, 'tenant_id');
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('tenant_id'));
        }
    }
};
