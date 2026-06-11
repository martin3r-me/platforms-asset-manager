<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_license_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('status')->default('started');
            $table->unsignedInteger('skus_synced')->nullable();
            $table->unsignedInteger('assignments_synced')->nullable();
            $table->unsignedInteger('assignments_added')->nullable();
            $table->unsignedInteger('assignments_removed')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_license_sync_logs');
    }
};
