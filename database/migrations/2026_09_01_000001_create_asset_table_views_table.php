<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gespeicherte Tabellen-Ansicht je (User × Team × Tabelle) — Persistenz hinter der Excel-artigen
 * Anpassung der Auswertungs-Tabellen (Spaltenbreiten, Spaltenreihenfolge, aus-/eingeblendete
 * Spalten und Zeilen, eingeklappte Gruppen). Siehe docs/adr/0020.
 *
 * Bewusst wie `asset_tenant_selections` gebaut: Auto-Increment-ID, kein UuidV7, kein SoftDeletes,
 * kein Activity-Log. Das hier sind **Arbeitsplatz-Präferenzen**, keine fachlichen Daten — eine
 * Historie über Spaltenbreiten wäre nur Rauschen im Audit-Trail.
 *
 * `payload` ist absichtlich ein JSON-Blob statt normalisierter Spalten: der Inhalt ist reine
 * Anzeige-Ergonomie, wird nie gejoint oder gefiltert und soll pro Tabellentyp wachsen dürfen,
 * ohne jedes Mal eine Migration zu erzwingen. Gelesen wird er nur durch
 * TableViewState::fromArray(), das jeden Wert prüft und Unbekanntes verwirft.
 *
 * Kein `tenant_id`: die Ansicht ist eine Eigenschaft des Blicks, nicht der Daten. Beim
 * Tenant-Wechsel soll dieselbe Spaltenbreite gelten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_table_views')) {
            return;
        }

        Schema::create('asset_table_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('table_key', 64);
            $table->json('payload');
            $table->timestamps();

            // Genau eine Ansicht je (User, Team, Tabelle) — der updateOrCreate-Schlüssel.
            $table->unique(['user_id', 'team_id', 'table_key'], 'asset_table_views_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_table_views');
    }
};
