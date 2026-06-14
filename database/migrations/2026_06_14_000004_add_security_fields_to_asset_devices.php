<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sicherheits-/Health-Felder aus Microsoft Graph (managedDevice). Liegen alle unter der bereits
     * genutzten Application Permission DeviceManagementManagedDevices.Read.All — KEINE neue Berechtigung.
     * Werden vom Sync via mapDevice() befüllt; bis zum nächsten Sync sind sie null ("unbekannt").
     */
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->boolean('is_encrypted')->nullable()->after('location');
            $table->string('enrollment_type')->nullable()->after('is_encrypted');
            $table->unsignedBigInteger('free_storage_bytes')->nullable()->after('enrollment_type');
            $table->unsignedBigInteger('total_storage_bytes')->nullable()->after('free_storage_bytes');
            $table->unsignedBigInteger('physical_memory_bytes')->nullable()->after('total_storage_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropColumn([
                'is_encrypted', 'enrollment_type',
                'free_storage_bytes', 'total_storage_bytes', 'physical_memory_bytes',
            ]);
        });
    }
};
