<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitäts-/Eindeutigkeits-Achse der Inventar-Tabellen von Team auf Tenant umstellen (M2, siehe
 * docs/adr/0003): innerhalb eines Teams kann es künftig mehrere Tenants geben, und dieselbe intune_id /
 * UPN / SKU darf je Tenant getrennt existieren. Voraussetzung: tenant_id ist auf allen Zeilen gesetzt
 * (M1-Backfill 2026_06_17_*).
 *
 * Zusätzlich: 0..1 Connector je Tenant → unique(tenant_id) auf asset_connector_configs.
 *
 * Hinweis (Plattform-Falle): Index-Swaps sind auf SQLite/Postgres die heikle Stelle — live ist MySQL,
 * lokal via --path-Smoke-Test auf SQLite verifiziert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'intune_id']);
            $table->unique(['tenant_id', 'intune_id']);
        });

        Schema::table('asset_user_licenses', function (Blueprint $table) {
            $table->dropUnique('asset_user_licenses_team_upn_sku_unique');
            $table->unique(['tenant_id', 'user_principal_name', 'sku_id'], 'asset_user_licenses_tenant_upn_sku_unique');
        });

        Schema::table('asset_license_skus', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'sku_id']);
            $table->unique(['tenant_id', 'sku_id']);
        });

        Schema::table('asset_employees', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'user_principal_name']);
            $table->unique(['tenant_id', 'user_principal_name']);
        });

        Schema::table('asset_connector_configs', function (Blueprint $table) {
            // 0..1 Connector je Tenant (tenant_id ist nullable; NULLs gelten als distinct → unkritisch).
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'intune_id']);
            $table->unique(['team_id', 'intune_id']);
        });

        Schema::table('asset_user_licenses', function (Blueprint $table) {
            $table->dropUnique('asset_user_licenses_tenant_upn_sku_unique');
            $table->unique(['team_id', 'user_principal_name', 'sku_id'], 'asset_user_licenses_team_upn_sku_unique');
        });

        Schema::table('asset_license_skus', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sku_id']);
            $table->unique(['team_id', 'sku_id']);
        });

        Schema::table('asset_employees', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_principal_name']);
            $table->unique(['team_id', 'user_principal_name']);
        });

        Schema::table('asset_connector_configs', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']);
        });
    }
};
