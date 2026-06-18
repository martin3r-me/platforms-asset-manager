<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3 — Aktiver Tenant je User (Tenant-Selektor, siehe CONTEXT.md / docs/adr/0003).
 *
 * Durable, pro (User × Team) gespeicherte Auswahl, welcher Tenant in den Inventar-Sichten
 * aktiv ist. Dashboard und Kosten-/Stammdaten ignorieren diese Auswahl (team-weit). Die
 * Auswahl ist ein Arbeitsfilter, keine Zugriffsgrenze.
 *
 * selected_tenant_id ist nullable + nullOnDelete: wird der Tenant gelöscht, bleibt die Zeile
 * bestehen und der TenantContext löst beim nächsten Lesen neu auf (Default → erster → null).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_tenant_selections')) {
            return;
        }

        Schema::create('asset_tenant_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('selected_tenant_id')->nullable()
                ->constrained('asset_tenants')->nullOnDelete();
            $table->timestamps();

            // Genau eine Auswahl je (User, Team) — updateOrCreate-Schlüssel.
            $table->unique(['user_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_tenant_selections');
    }
};
