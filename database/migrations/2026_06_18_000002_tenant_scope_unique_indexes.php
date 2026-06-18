<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitäts-/Eindeutigkeits-Achse der Inventar-Tabellen von Team auf Tenant umstellen (M2, siehe
 * docs/adr/0003): dieselbe intune_id / UPN / SKU darf je Tenant getrennt existieren. Voraussetzung:
 * tenant_id ist auf allen Zeilen gesetzt (M1-Backfill 2026_06_17_*).
 *
 * IDEMPOTENT + MySQL-SICHER (vgl. 2026_06_17_000002): Ein team_id-führender Unique-Index stützt auf MySQL
 * den team_id-Foreign-Key. Lässt eine Tabelle KEINEN anderen Index mit team_id als führender Spalte übrig
 * (z. B. asset_license_skus, asset_connector_configs), schlägt das Droppen mit Fehler 1553 fehl. swapUnique()
 * legt daher zuvor einen einfachen Index auf die FK-Spalte als Stütze an — aber nur, wenn nötig.
 */
return new class extends Migration
{
    /**
     * Ersetzt einen Unique-Index durch einen anderen — MySQL-sicher (FK-Stütze) + idempotent.
     * $fkColumn = führende Spalte des zu DROPPENDEN Index (= die FK-Spalte, die er stützt).
     */
    private function swapUnique(string $table, string $dropName, string $addName, array $addCols, string $fkColumn): void
    {
        $indexes = collect(Schema::getIndexes($table));

        if ($indexes->contains(fn ($i) => $i['name'] === $dropName)) {
            // FK-Stütze sicherstellen, falls der zu droppende Index der einzige mit $fkColumn als führender
            // Spalte ist (sonst Fehler 1553 auf MySQL).
            $hasFkCover = $indexes->contains(
                fn ($i) => $i['name'] !== $dropName && (($i['columns'][0] ?? null) === $fkColumn)
            );
            if (! $hasFkCover) {
                Schema::table($table, fn (Blueprint $t) => $t->index($fkColumn));
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($dropName));
        }

        if (! $indexes->contains(fn ($i) => $i['name'] === $addName)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($addCols, $addName));
        }
    }

    private function setUnique(string $table, array $columns): void
    {
        $indexes = collect(Schema::getIndexes($table));
        if (! $indexes->contains(fn ($i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === $columns)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($columns));
        }
    }

    private function dropUniqueByColumns(string $table, array $columns): void
    {
        $indexes = collect(Schema::getIndexes($table));
        if ($indexes->contains(fn ($i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === $columns)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($columns));
        }
    }

    public function up(): void
    {
        $this->swapUnique('asset_devices',
            'asset_devices_team_id_intune_id_unique',
            'asset_devices_tenant_id_intune_id_unique', ['tenant_id', 'intune_id'], 'team_id');

        $this->swapUnique('asset_user_licenses',
            'asset_user_licenses_team_upn_sku_unique',
            'asset_user_licenses_tenant_upn_sku_unique', ['tenant_id', 'user_principal_name', 'sku_id'], 'team_id');

        $this->swapUnique('asset_license_skus',
            'asset_license_skus_team_id_sku_id_unique',
            'asset_license_skus_tenant_id_sku_id_unique', ['tenant_id', 'sku_id'], 'team_id');

        $this->swapUnique('asset_employees',
            'asset_employees_team_id_user_principal_name_unique',
            'asset_employees_tenant_id_user_principal_name_unique', ['tenant_id', 'user_principal_name'], 'team_id');

        // 0..1 Connector je Tenant (tenant_id nullable; NULLs gelten als distinct → unkritisch).
        $this->setUnique('asset_connector_configs', ['tenant_id']);
    }

    public function down(): void
    {
        // Connector-Unique zuerst lösen.
        $this->dropUniqueByColumns('asset_connector_configs', ['tenant_id']);

        // Tenant-Uniques zurück auf Team-Scope — beim Droppen des tenant-führenden Index ist tenant_id
        // die abzustützende FK-Spalte.
        $this->swapUnique('asset_devices',
            'asset_devices_tenant_id_intune_id_unique',
            'asset_devices_team_id_intune_id_unique', ['team_id', 'intune_id'], 'tenant_id');

        $this->swapUnique('asset_user_licenses',
            'asset_user_licenses_tenant_upn_sku_unique',
            'asset_user_licenses_team_upn_sku_unique', ['team_id', 'user_principal_name', 'sku_id'], 'tenant_id');

        $this->swapUnique('asset_license_skus',
            'asset_license_skus_tenant_id_sku_id_unique',
            'asset_license_skus_team_id_sku_id_unique', ['team_id', 'sku_id'], 'tenant_id');

        $this->swapUnique('asset_employees',
            'asset_employees_tenant_id_user_principal_name_unique',
            'asset_employees_team_id_user_principal_name_unique', ['team_id', 'user_principal_name'], 'tenant_id');
    }
};
