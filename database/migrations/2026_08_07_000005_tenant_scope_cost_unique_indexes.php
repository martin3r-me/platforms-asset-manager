<?php

use Illuminate\Database\Migrations\Migration;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Tenant-Welle 5/5 — Eindeutigkeits-Achse der Kostentabellen von Team auf Tenant (siehe docs/adr/0016).
 *
 * Fortsetzung von 2026_06_18_000002, das dasselbe fürs Inventar getan hat. Ohne diesen Schritt könnten
 * zwei Tenants desselben Teams nicht dieselbe Kostenstellen-Nummer, Kostenart oder denselben Kreditor
 * führen — und genau das ist der Normalfall: „2599" und „Vodafone" gibt es bei jedem Kunden.
 *
 * MySQL-1553-sicher über MigrationSchema::swapUnique (Plain-Index als FK-Stütze, bevor der stützende
 * Unique fällt). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchema::swapUniqueByColumns('asset_cost_centers',
            ['team_id', 'code'],
            'asset_cost_centers_tenant_id_code_unique', ['tenant_id', 'code'], 'team_id');

        MigrationSchema::swapUniqueByColumns('asset_cost_types',
            ['team_id', 'key'],
            'asset_cost_types_tenant_id_key_unique', ['tenant_id', 'key'], 'team_id');

        MigrationSchema::swapUniqueByColumns('asset_vendors',
            ['team_id', 'name'],
            'asset_vendors_tenant_id_name_unique', ['tenant_id', 'name'], 'team_id');

        // Import-Idempotenz (2026_06_13_000011) wird tenant-scoped: derselbe Excel-Import darf für
        // zwei Tenants dieselbe Zeile erzeugen. NULL bleibt distinct → manuelle Cost-Lines unberührt.
        MigrationSchema::swapUnique('asset_cost_lines',
            'asset_cost_lines_team_import_hash_unique',
            'asset_cost_lines_tenant_import_hash_unique', ['tenant_id', 'import_hash'], 'team_id');

        // Moduleinstellungen: eine Zeile je Team UND Tenant (vorher: eine je Team).
        MigrationSchema::swapUniqueByColumns('asset_team_settings',
            ['team_id'],
            'asset_team_settings_team_tenant_unique', ['team_id', 'tenant_id'], 'team_id');
    }

    public function down(): void
    {
        MigrationSchema::swapUniqueByColumns('asset_team_settings',
            ['team_id', 'tenant_id'],
            'asset_team_settings_team_id_unique', ['team_id'], 'tenant_id');

        MigrationSchema::swapUnique('asset_cost_lines',
            'asset_cost_lines_tenant_import_hash_unique',
            'asset_cost_lines_team_import_hash_unique', ['team_id', 'import_hash'], 'tenant_id');

        MigrationSchema::swapUniqueByColumns('asset_vendors',
            ['tenant_id', 'name'],
            'asset_vendors_team_id_name_unique', ['team_id', 'name'], 'tenant_id');

        MigrationSchema::swapUniqueByColumns('asset_cost_types',
            ['tenant_id', 'key'],
            'asset_cost_types_team_id_key_unique', ['team_id', 'key'], 'tenant_id');

        MigrationSchema::swapUniqueByColumns('asset_cost_centers',
            ['tenant_id', 'code'],
            'asset_cost_centers_team_id_code_unique', ['team_id', 'code'], 'tenant_id');
    }
};
