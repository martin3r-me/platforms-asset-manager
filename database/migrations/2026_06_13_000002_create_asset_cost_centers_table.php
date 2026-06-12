<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('code');                // Kostenstellen-Nummer, z.B. '2599' (auch 'EFP')
            $table->string('name')->nullable();    // Klartext-Bezeichnung
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'code']);
            $table->index(['team_id', 'company_id']);

            $table->foreign('company_id')->references('id')->on('asset_companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_cost_centers');
    }
};
