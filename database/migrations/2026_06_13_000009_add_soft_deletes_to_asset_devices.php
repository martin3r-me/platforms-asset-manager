<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SoftDeletes für asset_devices. Geräte tragen jetzt Kosten-Overrides (monthly_cost, cost_type_id,
     * cost_center_id …). Der Intune-Sync entfernt verschwundene Geräte — als Hard-Delete gingen diese
     * manuell gepflegten Overrides verloren, wenn ein Gerät auch nur einen Sync lang fehlt. Soft-Delete
     * bewahrt die Zeile; taucht das Gerät wieder auf, restored der Sync sie samt Overrides.
     */
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
