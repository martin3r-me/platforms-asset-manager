<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-Seam (ADR 0009): Ein physisches Gerät kann von mehreren Quellen bekannt sein —
 * Intune (MDM/Zustand) und perspektivisch Apple Business Manager (Eigentum/Beschaffung). Diese
 * Kind-Tabelle hält je Gerät × Provider eine Quell-Referenz (external_id, gemeldete Serial,
 * last_seen_at). Sie ist die Grundlage für die serial-basierte ABM-Anbindung und den späteren
 * Drift-Befund „besessen, aber nicht verwaltet". Reine Infrastruktur, additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_device_sources')) {
            return;
        }

        Schema::create('asset_device_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();
            $table->foreignId('asset_device_id')->constrained('asset_devices')->cascadeOnDelete();
            $table->string('provider')->default('intune');
            $table->string('external_id')->nullable(); // Provider-id (für Intune die aktuelle intune_id)
            $table->string('serial_number')->nullable(); // Serial, wie von dieser Quelle gemeldet
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Eine Quell-Zeile je Gerät je Provider (Upsert-Schlüssel).
            $table->unique(['asset_device_id', 'provider']);
            // Drift-/Bestands-Auswertung je Tenant und Provider (z. B. ABM-Bestand ∖ MDM-Bestand).
            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_device_sources');
    }
};
