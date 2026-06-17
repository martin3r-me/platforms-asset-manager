<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant = vom Team verwalteter Kundenkontext (siehe CONTEXT.md / docs/adr/0003).
 * Eigenes, durables Modell — die optionale Microsoft-Anbindung (Connector) hängt sich daran;
 * ein Tenant ohne Connector ist ein reiner Manuell-Kunde. Inventar bezieht sich künftig per
 * tenant_id hierauf (folgt in den nächsten Schritten). Auto-increment-IDs (Modul-Konvention,
 * kein UuidV7). Tenant-Löschen = Cascade auf abhängige Daten (daher kein SoftDelete hier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            // Genau ein Default-Tenant je Team (Backfill-Ziel). Eindeutigkeit wird in der
            // Anwendungslogik gehalten — kein DB-Partial-Index (Cross-DB-Portabilität).
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_tenants');
    }
};
