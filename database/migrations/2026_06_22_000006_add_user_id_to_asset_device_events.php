<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle-Audit (Track B 2a): Geräte-Events bekommen einen optionalen Akteur. Sync-getriebene Events
 * (created/owner_changed/…) haben keinen User → nullable + nullOnDelete. Manuelle Änderungen (z. B.
 * Lifecycle-Status über die Geräte-Detailseite) halten so fest, WER sie wann gesetzt hat. Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_device_events', 'user_id')) {
            Schema::table('asset_device_events', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('asset_device_id')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_device_events', 'user_id')) {
            Schema::table('asset_device_events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
