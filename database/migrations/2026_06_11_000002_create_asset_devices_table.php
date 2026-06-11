<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('intune_id')->index();
            $table->string('device_name')->nullable();
            $table->string('user_display_name')->nullable();
            $table->string('user_principal_name')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('os_version')->nullable();
            $table->enum('compliance_state', ['unknown', 'compliant', 'noncompliant', 'conflict', 'error', 'inGracePeriod'])->default('unknown');
            $table->string('management_state')->nullable();
            $table->string('device_type')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_check_in_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'intune_id']);
            $table->index(['team_id', 'compliance_state']);
            $table->index(['team_id', 'operating_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_devices');
    }
};
