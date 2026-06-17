<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `asset_devices.tenant_id` war die (dormante) Azure-Tenant-GUID als String → wird zu
 * `azure_tenant_id`, damit `tenant_id` für den FK auf `asset_tenants` frei wird
 * (modulweit konsistent, vgl. dieselbe Bereinigung am Connector in 000002). Der FK kommt
 * in 000004. Reine Spaltenumbenennung — Laravel 12 kann das nativ (kein doctrine/dbal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'azure_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->renameColumn('azure_tenant_id', 'tenant_id');
        });
    }
};
