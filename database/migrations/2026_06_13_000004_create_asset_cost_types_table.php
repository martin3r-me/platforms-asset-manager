<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_cost_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('key');                 // stabiler Schlüssel, z.B. 'mobilfunk'
            $table->string('name');                // Anzeigename / Excel-Spaltenkopf
            $table->unsignedInteger('sort_order')->default(100);
            $table->unsignedBigInteger('vendor_default_id')->nullable();
            $table->string('system_default')->nullable();        // HGK|Moss
            $table->string('frequency_default')->default('monthly'); // monthly|quarterly|yearly|once
            $table->boolean('is_per_employee')->default(false);
            $table->string('aggregation_source')->default('cost_line'); // cost_line|hardware_afa|ms_license
            $table->boolean('allow_negative')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'key']);

            $table->foreign('vendor_default_id')->references('id')->on('asset_vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_cost_types');
    }
};
