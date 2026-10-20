<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preis-Historie der Kostenpositionen aus Rechnungsimporten (ADR 0029).
 *
 * Ein Rechnungsimport aktualisiert seine Kostenposition jeden Monat in place (Hash = Identität, nicht
 * Betrag) — der Vormonatsbetrag war danach weg. Hier bleibt je Kostenposition und Rechnung ein Eintrag.
 *
 * Gruppiert wird über `import_hash` (die stabile Identität der Zeile: Quelle, Konto, Rufnummer bzw.
 * Posten), nicht über `cost_line_id`: der Prune löscht abgängige Nummern hart, ihr Verlauf soll bleiben
 * (`cost_line_id` wird dann null). Ein Lauf derselben Rechnung überschreibt seinen Eintrag (Unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_cost_line_history')) {
            return;
        }

        Schema::create('asset_cost_line_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();
            $table->foreignId('cost_line_id')->nullable()->constrained('asset_cost_lines')->nullOnDelete();
            $table->string('import_hash', 40);
            $table->string('source', 32);
            $table->string('account', 64)->default('');
            $table->string('invoice', 64);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->date('booking_month')->nullable();
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('cost_type_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'import_hash', 'invoice'], 'asset_cost_line_history_unique');
            $table->index(['tenant_id', 'import_hash', 'booking_month'], 'asset_cost_line_history_line_index');
            $table->index(['tenant_id', 'assignee_id'], 'asset_cost_line_history_assignee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_cost_line_history');
    }
};
