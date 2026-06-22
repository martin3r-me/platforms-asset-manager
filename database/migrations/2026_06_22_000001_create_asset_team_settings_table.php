<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul-Einstellungen je Team (siehe ADR 0008).
 *
 * Erste Einstellung: controlling_enabled — schaltet die Kosten-/Controlling-Schicht
 * (Auswertungen, Stammdaten, Kosten-Import) pro Team an/aus. Default false: ein neues Team
 * startet schlank (nur IT-Asset-/Lifecycle-Kern). Eine Folge-Migration setzt das Flag für
 * Teams mit bereits vorhandenen Controlling-Daten auf true (Bestandsschutz).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_team_settings')) {
            return;
        }

        Schema::create('asset_team_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->boolean('controlling_enabled')->default(false);
            $table->timestamps();

            // Genau eine Einstellungs-Zeile je Team — updateOrCreate-Schlüssel.
            $table->unique('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_team_settings');
    }
};
