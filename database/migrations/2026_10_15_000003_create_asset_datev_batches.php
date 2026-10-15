<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * DATEV-Stapel-Protokoll (Baustein 4, Discussion #26): jeder erzeugte EXTF-Buchungsstapel mit seiner
 * Datei und den Monatsständen, die er umbucht.
 *
 * **Schutz gegen Doppel-Umbuchung:** beim Erzeugen bekommt jeder enthaltene Monatsstand
 * (`asset_invoice_snapshots.datev_batch_id`) die ID des Stapels. Ein gesperrter Stand fällt aus jedem
 * weiteren Stapel heraus und wird von keinem Re-Import mehr verändert. Verwerfen (mit Grund) gibt die
 * Stände wieder frei; die Zeile und ihre Datei bleiben stehen — was einmal erzeugt wurde, ist nachvollziehbar.
 *
 * **Die Datei liegt in der Tabelle**, Base64-kodiert: sie ist Windows-1252 (Umlaute in Buchungstexten), was
 * eine utf8mb4-Textspalte nicht verlustfrei hält, und mit wenigen Kilobyte zu klein für eigene Ablage.
 * `file_sha256` läuft über die Bytes, wie sie heruntergeladen und in DATEV importiert werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_datev_batches')) {
            Schema::create('asset_datev_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

                $table->date('booking_month');
                // DATEV-Kopf: Mandant und Berater des Stapels (Foodpol 10002 nie im 530er-Stapel).
                $table->string('client', 5);
                $table->string('consultant', 7);
                $table->string('label', 30);

                $table->string('file_name');
                $table->longText('file_base64');
                $table->string('file_sha256', 64);

                $table->unsignedInteger('bookings_count');
                // Summe der Quellzeilen (Beträge der Blöcke) in Cent.
                $table->bigInteger('total_cents');
                // Blöcke, Monatsstände, Befunde zum Zeitpunkt der Erzeugung.
                $table->json('summary');

                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'tenant_id', 'booking_month'], 'asset_datev_batches_month_index');
            });
        }

        if (Schema::hasTable('asset_invoice_snapshots') && Schema::hasColumn('asset_invoice_snapshots', 'datev_batch_id')) {
            MigrationSchema::dropForeignIfExists('asset_invoice_snapshots', 'datev_batch_id');
            // Ein gelöschter Stapel (nur über Team/Tenant-Kaskade) gibt seine Stände frei.
            Schema::table('asset_invoice_snapshots', function (Blueprint $table) {
                $table->foreign('datev_batch_id')->references('id')->on('asset_datev_batches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_invoice_snapshots')) {
            MigrationSchema::dropForeignIfExists('asset_invoice_snapshots', 'datev_batch_id');
        }

        Schema::dropIfExists('asset_datev_batches');
    }
};
