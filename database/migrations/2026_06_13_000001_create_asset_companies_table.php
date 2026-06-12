<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('key');                 // Slug, z.B. 'rhein-ruhr'
            $table->string('name');                // Anzeigename, z.B. 'BROICH - RHEIN RUHR'
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();

            $table->unique(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_companies');
    }
};
