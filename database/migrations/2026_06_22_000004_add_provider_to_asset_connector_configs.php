<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-Seam (ADR 0009): Connector bekommt einen `provider`-Typ. Default 'intune' → Bestandszeilen
 * werden automatisch als Intune-Connector markiert. Die Kardinalität bleibt in diesem Schritt 0..1
 * (`unique(tenant_id)` aus 2026_06_18_000002 unverändert); der Wechsel auf 0..N je Tenant
 * (`unique(tenant_id, provider)`) kommt erst mit der ABM-Anbindung (Schritt 3), wo er real gebraucht
 * wird und über das bestehende MySQL-1553-sichere swapUnique-Muster läuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_connector_configs', 'provider')) {
            Schema::table('asset_connector_configs', function (Blueprint $table) {
                $table->string('provider')->default('intune')->after('tenant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_connector_configs', 'provider')) {
            Schema::table('asset_connector_configs', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }
};
