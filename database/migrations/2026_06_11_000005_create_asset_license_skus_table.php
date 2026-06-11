<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_license_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('sku_id');
            $table->string('sku_part_number');
            $table->string('display_name')->nullable();
            $table->unsignedInteger('purchased_units')->default(0);
            $table->unsignedInteger('consumed_units')->default(0);
            $table->unsignedInteger('available_units')->default(0);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'sku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_license_skus');
    }
};
