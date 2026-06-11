<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_device_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->enum('status', ['started', 'success', 'error'])->default('started');
            $table->integer('devices_synced')->nullable();
            $table->integer('devices_added')->nullable();
            $table->integer('devices_updated')->nullable();
            $table->integer('devices_removed')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->index(['team_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_device_sync_logs');
    }
};
