<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlauf am Asset-Träger (#897) — nur für die beiden geldwirksamen Felder: Kostenstelle und
 * Abrechnungs-Ausnahme (ADR 0024). Muster wie `asset_device_events`: unveränderliche Zeilen mit
 * Urheber (`user_id`, null = System/Sync).
 *
 * Bewusst kein `LogsActivity` (Modulkonvention) und kein Verlauf für den Träger-Typ: an der Frage
 * „wer hat das gesetzt" hängt beim Typ kein Geld, das Flag `holder_type_pinned` (#896) sagt, ob ein
 * Mensch ihn festgelegt hat.
 *
 * `holder_id` ohne Fremdschlüssel-Zwang: Träger werden beim Zusammenführen von Dubletten gelöscht
 * ({@see \Platform\AssetManager\Services\HolderMergeService} hängt die Zeilen vorher um), und der
 * Team-Reset löscht die Tabelle ausdrücklich mit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_holder_events')) {
            return;
        }

        Schema::create('asset_holder_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('asset_tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('holder_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // cost_center_changed | billing_excluded | billing_reason_changed | billing_included
            $table->string('event_type', 40);
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->string('description');
            $table->timestamps();

            $table->index(['team_id', 'holder_id'], 'asset_holder_events_holder_index');
            $table->index(['tenant_id', 'event_type'], 'asset_holder_events_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_holder_events');
    }
};
