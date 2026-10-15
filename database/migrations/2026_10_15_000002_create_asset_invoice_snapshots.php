<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monatsstand je Rechnung (Discussion #26, DATEV-Umbuchung, Entscheidung Max 2026-10-15).
 *
 * Die Kostenpositionen halten je Rechnungskonto nur die **zuletzt importierte** Rechnung — jeder Import
 * zieht den Bestand auf seinen Stand. Ein Buchungsstapel je Monat braucht aber den Stand *dieses* Monats,
 * auch rückwirkend und auch dann, wenn später eine Kostenstelle umgehängt wurde. Deshalb schreibt jeder
 * scharfe Rechnungsimport hier fest: Rechnungsbetrag, Buchungsmonat und die Verteilung auf
 * Kostenart × Kostenstelle, wie sie nach dem Import galt.
 *
 * `distribution` ist JSON statt eigener Zeilentabelle: ein Monatsstand wird immer als Ganzes geschrieben
 * und gelesen, nie zeilenweise geändert. Kostenart-Schlüssel und KST-Code stehen mit drin, damit der Stand
 * lesbar bleibt, wenn Stammdaten später umbenannt oder gelöscht werden.
 *
 * `datev_batch_id` ist für das Stapel-Protokoll (Baustein 4) reserviert: ein Monatsstand, der schon in
 * einem Stapel gebucht ist, wird von keinem späteren Import mehr verändert — sonst würde doppelt bzw.
 * abweichend umgebucht.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_invoice_snapshots')) {
            return;
        }

        Schema::create('asset_invoice_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

            // Herkunft = `source` der Kostenpositionen (vodafone_invoice, vodafone_fixednet, license_invoice).
            $table->string('source', 32);
            $table->string('account', 64)->default('');
            $table->string('invoice', 64);

            $table->date('invoice_date')->nullable();
            // Monatserster des Monats, in dem die Rechnung gebucht ist; null = nicht bestimmbar (Befund).
            $table->date('booking_month')->nullable();
            $table->string('period_label')->nullable();

            // Rechnungsnetto laut Beleg — der Betrag, den die Umbuchung verteilt.
            $table->decimal('total_net', 12, 2);
            // list<{cost_type_id, cost_type_key, cost_center_id, cost_center_code, amount, lines}>
            $table->json('distribution');

            $table->unsignedBigInteger('datev_batch_id')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'account', 'invoice'], 'asset_invoice_snapshots_identity_unique');
            $table->index(['team_id', 'tenant_id', 'booking_month'], 'asset_invoice_snapshots_month_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_invoice_snapshots');
    }
};
