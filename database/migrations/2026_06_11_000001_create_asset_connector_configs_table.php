<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_connector_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->text('client_id')->nullable();
            $table->text('tenant_id')->nullable();
            $table->text('object_id')->nullable();
            $table->text('key_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->enum('sync_status', ['idle', 'running', 'success', 'error'])->default('idle');
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->unique('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_connector_configs');
    }
};
