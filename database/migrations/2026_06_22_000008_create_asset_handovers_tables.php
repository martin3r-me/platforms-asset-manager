<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geräteausgaben (Übergabeprotokoll). Kopf-/Positionen-Modell:
 *  - asset_handovers       = ein unterschriebenes Protokoll je Empfänger (deckt mehrere Geräte ab)
 *  - asset_handover_lines  = je ausgegebenem Gerät eine Zeile (Rückgabe/Tausch zeilenweise)
 *
 * Das Protokoll ist die unterschriebene HISTORIE der Ausgabe-/Rückgabe-Zyklen (Intune behält nur den
 * aktuellen Primary-User). Intune-Felder werden nie mutiert; ein device_snapshot je Zeile friert den
 * Gerätestand zum Ausgabezeitpunkt ein, damit das Protokoll den nächsten Sync-Overwrite überlebt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('asset_tenants')->cascadeOnDelete();
            // Empfänger: nullable + nullOnDelete, damit das Protokoll (Historie) das Löschen/Ausscheiden
            // des Mitarbeiters überlebt. App-seitig ist der Empfänger Pflicht.
            $table->foreignId('employee_id')->nullable()->constrained('asset_employees')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('issued_at')->nullable();
            $table->string('signer_name')->nullable();
            $table->longText('signature_data')->nullable(); // base64-PNG der Unterschrift (optional/nachholbar)
            $table->timestamp('signed_at')->nullable();
            $table->text('notes')->nullable();
            // open | partially_returned | returned — aus den Zeilen abgeleitet (recomputeStatus()).
            $table->string('status', 30)->default('open');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'employee_id']);
            $table->index(['team_id', 'status']);
        });

        Schema::create('asset_handover_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handover_id')->constrained('asset_handovers')->cascadeOnDelete();
            // Gerät: cascade ist korrekt — Geräte werden beim Sync nur soft-deleted, hart nur beim
            // Tenant-Purge (dann sollen die Protokolle mit weg).
            $table->foreignId('asset_device_id')->constrained('asset_devices')->cascadeOnDelete();

            $table->json('accessories')->nullable();   // Zubehör-Tags (Ladegerät, Hülle, SIM, …)
            $table->text('notes')->nullable();
            $table->date('returned_at')->nullable();
            $table->string('return_condition')->nullable();
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // {device_name, serial_number, manufacturer, model, user_principal_name, user_display_name}
            // zum Ausgabezeitpunkt — Protokoll bleibt korrekt, auch wenn das Gerät später überschrieben wird.
            $table->json('device_snapshot')->nullable();
            $table->string('status', 20)->default('issued'); // issued | returned

            $table->timestamps();

            $table->index(['handover_id']);
            // "eine offene Ausgabe je Gerät" + "ohne offene Ausgabe"-Badge: schnell über (Gerät, returned_at).
            $table->index(['asset_device_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_handover_lines');
        Schema::dropIfExists('asset_handovers');
    }
};
