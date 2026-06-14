<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pro-Gerät-Verlauf: Besitzer-, Compliance- und OS-Wechsel werden beim Sync als unveränderliche
     * Events geschrieben (Snapshot-Diff in SyncIntuneDevicesJob). Bleibt erhalten, wenn ein Gerät
     * soft-deleted wird (Intune-Reconcile), und gibt der Detailseite eine Timeline.
     */
    public function up(): void
    {
        Schema::create('asset_device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('asset_device_id')->constrained('asset_devices')->cascadeOnDelete();
            $table->string('event_type');            // created|owner_changed|compliance_changed|os_changed
            $table->string('description');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'asset_device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_device_events');
    }
};
