<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Import-Lauf** — die Historie dessen, was ein Import getan hat.
 *
 * Vorher gab es einen „Import-Log", der etwas anderes war: eine Liste der Kostenpositionen mit
 * `source='excel_import'`. Das hatte drei Schwächen. Er kannte **nur den Excel-Import** (Vodafone- und
 * Lizenz-Rechnung tauchten nie auf), er zeigte den **Endzustand statt des Vorgangs** (was ein Lauf
 * geändert, ersetzt oder verworfen hat, war daran nicht ablesbar), und **Probeläufe** hinterließen
 * naturgemäß gar keine Spur, obwohl genau deren Zahlen die Entscheidung tragen, ob man den Import
 * scharf laufen lässt.
 *
 * Vor allem aber war das Ergebnis eines Laufs bisher **flüchtig**: es lebte in einer
 * Livewire-Eigenschaft und war nach dem nächsten Seitenaufruf verloren. Wer nachträglich wissen
 * wollte, ob die Rechnungssumme aufgegangen ist oder welche Position keiner Lizenz zugeordnet wurde,
 * musste die Datei erneut hochladen.
 *
 * Eine Zeile hier ist ein Lauf, mit seiner vollständigen Statistik in `summary`. Bewusst als JSON und
 * nicht als Spalten: die drei Quellen liefern verschiedene Kennzahlen (Rufnummern, Sheets,
 * Vertragszeilen), und jede neue Quelle würde sonst eine Migration erzwingen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_import_runs')) {
            return;
        }

        Schema::create('asset_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

            // excel | vodafone | license_invoice
            $table->string('source', 32);
            $table->string('file_name')->nullable();
            $table->string('batch_id')->nullable();

            // Probeläufe werden mitgeschrieben — ihre Zahlen sind die Entscheidungsgrundlage.
            $table->boolean('dry_run')->default(false);

            // ok | warning | error — `warning` heißt: gelaufen, aber mit Befund (Summe weicht ab,
            // Positionen ohne Zuordnung, Mengenbilanz offen). Ein Lauf ohne diesen Zwischenzustand
            // wäre entweder grün oder rot, und beides träfe die Lage nicht.
            $table->string('status', 16)->default('ok');
            $table->string('headline')->nullable();   // Kurzfassung für die Liste
            $table->text('error')->nullable();

            $table->json('summary')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'tenant_id', 'created_at'], 'asset_import_runs_recent_index');
            $table->index(['team_id', 'tenant_id', 'source'], 'asset_import_runs_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_import_runs');
    }
};
