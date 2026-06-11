<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_user_licenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('sku_id');
            $table->string('sku_part_number')->nullable();
            $table->string('user_principal_name');
            $table->string('display_name')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'user_principal_name']);
            $table->index(['team_id', 'sku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_user_licenses');
    }
};
