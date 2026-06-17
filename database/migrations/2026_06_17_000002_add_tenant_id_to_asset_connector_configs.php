<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connector vom Team an den Tenant umhängen (siehe docs/adr/0003):
 * - bisher genau 1 Connector je Team (unique team_id) → künftig 0..1 je Tenant
 * - team_id bleibt erhalten (Scoping + bestehender Sync-Code unverändert)
 *
 * Namens-Bereinigung: die bestehende Spalte `tenant_id` war die (verschlüsselte) Azure-Tenant-GUID
 * → wird zu `azure_tenant_id`. Der Name `tenant_id` wird damit für den FK auf `asset_tenants` frei
 * (modulweit künftig konsistent: tenant_id = unser Tenant, azure_tenant_id = Microsoft-Verzeichnis).
 *
 * Datenmigration: für jedes Team mit bestehendem Connector wird ein neutral benannter
 * Default-Tenant ("Standard", umbenennbar) angelegt und der Connector daran gehängt.
 * tenant_id bleibt vorerst nullable — NOT NULL kommt, wenn die UI (M2) das garantiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Azure-GUID-Spalte umbenennen, damit `tenant_id` frei wird.
        Schema::table('asset_connector_configs', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'azure_tenant_id');
        });

        // 2) Tenant-FK ergänzen und die 1:1-Team-Bindung lösen.
        Schema::table('asset_connector_configs', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('team_id')
                ->constrained('asset_tenants')->cascadeOnDelete();
            $table->dropUnique(['team_id']);
        });

        // 3) Bestehende Connectoren auf je einen Default-Tenant ihres Teams zeigen lassen.
        foreach (DB::table('asset_connector_configs')->get() as $connector) {
            $tenantId = DB::table('asset_tenants')
                ->where('team_id', $connector->team_id)
                ->where('is_default', true)
                ->value('id');

            if (! $tenantId) {
                $tenantId = DB::table('asset_tenants')->insertGetId([
                    'team_id'    => $connector->team_id,
                    'name'       => 'Standard',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('asset_connector_configs')
                ->where('id', $connector->id)
                ->update(['tenant_id' => $tenantId]);
        }
    }

    public function down(): void
    {
        Schema::table('asset_connector_configs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique('team_id');
        });

        Schema::table('asset_connector_configs', function (Blueprint $table) {
            $table->renameColumn('azure_tenant_id', 'tenant_id');
        });
    }
};
