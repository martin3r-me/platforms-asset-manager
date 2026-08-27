<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Vertragszeile** — eine Lizenzmenge zu einem Preis mit einer Bindung.
 *
 * Sie ist der Träger des Lizenzpreises, nicht die SKU. Grund: eine Rechnung enthält regelmäßig
 * **mehrere Tarif-Varianten derselben SKU**. Business Premium wird im Juli 2026 als 118 Seats aus dem
 * Jahresvertrag zu 15,14 € **und** 15 Seats im Monatstarif zu 22,87 € abgerechnet — zusammen 133,
 * exakt der Bestand in Graph. Ein Wert je SKU könnte das nur mitteln, und ein Durchschnitt von
 * 16,01 € stünde auf keiner Rechnung: niemand könnte ihn gegen einen Beleg prüfen. Jede Vertragszeile
 * behält deshalb ihren echten Monatspreis; verteilt werden die **Mengen** auf die Lizenzträger.
 *
 * ## Preis
 *
 * `unit_price` = `list_price` × (1 − `discount_percent`/100) — beides steht direkt in der Rechnung
 * (Spalte „Listenpreis/Rabatt", einmal als Betrag in EUR, einmal als Satz in % auf einer eigenen
 * Zeile). Kein zeitanteiliges Rechnen: Mengenänderungen mitten im Monat werden über den Bestand zum
 * Stichtag abgebildet, nicht über Seat-Tage. Der Rabatt ist im Preis verrechnet und wird **nicht**
 * als Gutschriftsposition geführt.
 *
 * ## Menge
 *
 * `quantity` ist der Bestand zum **Ende** des Abrechnungszeitraums, also zum Monatsersten des
 * Folgemonats — die Summe der Mengen aller Rechnungszeilen dieser Position, deren Zeitraum dort
 * endet. Bewusst dieses Ende und nicht der Beginn: Graph liefert immer den *aktuellen* Bestand, und
 * nur gegen den Stand am Periodenende ist die harte Mengenprüfung überhaupt erfüllbar. Im Juli 2026
 * trifft sie damit acht von elf Positionen exakt; die drei Abweichungen sind echte Befunde
 * (bezahlte Seats ohne Lizenz im Tenant, zugewiesene Seats ohne Bezahlung).
 *
 * ## Bindung
 *
 * `binding` (P1M/P1Y) steuert die Verteilungs-Kaskade und ist zusätzlich Flexibilitätsinformation für
 * Verlängerungsentscheidungen — **kein** Kostenattribut, der Preis steht unabhängig davon fest. Die
 * Rechnung nennt die Bindung nicht als Feld; sie wird aus zwei Signalen vorbelegt („- P1Y" im
 * Positionsnamen, Rabattbezeichnung „12 M 30%") und bleibt bis zur Bestätigung als unbestätigt
 * markiert. `cancellable_at` steht in keiner Rechnung und wird ausschließlich gepflegt.
 *
 * ## Restmengen
 *
 * Was die Kaskade keinem Träger zuordnen kann, bleibt an der Vertragszeile stehen:
 * `overflow_quantity` (Monats-Seats über den Bedarf an Funktionskonten hinaus) und `pool_quantity`
 * (unbesetzter Rest). Beide werden in der Kostenaufteilung auf je eine konfigurierte Sammel-
 * Kostenstelle gebucht — nicht auf willkürlich gewählte Personen. Ein gefülltes `overflow_quantity`
 * ist die Kennzahl „zu viele Monatslizenzen, auf Jahresbindung umstellbar".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_license_contract_lines')) {
            return;
        }

        Schema::create('asset_license_contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

            // Abrechnungsmonat aus den Zeiträumen der Rechnung, nicht aus dem Dateinamen.
            $table->date('period_from');
            $table->date('period_to');
            $table->string('period_label', 7);            // 'YYYY-MM'
            $table->unsignedSmallInteger('period_days');

            $table->string('product')->nullable();        // Spalte „Produkt" — z. B. „Microsoft 365"
            $table->string('edition');                    // Positionsname, wie er auf der Rechnung steht
            $table->string('edition_key');                // normalisierter Vergleichsschlüssel (Alias-Anker)

            $table->decimal('quantity', 12, 2)->default(0);        // Bestand zum Periodenende
            $table->decimal('list_price', 12, 4)->nullable();      // Listenpreis vor Rabatt
            $table->decimal('discount_percent', 6, 2)->default(0); // Satz laut Rabattzeile
            $table->decimal('unit_price', 12, 4)->default(0);      // list_price × (1 − discount)
            $table->decimal('amount_net', 12, 2)->default(0);      // Rechnungsbetrag — Basis der Gegenprobe
            $table->string('currency', 3)->default('EUR');

            // Bindung: Flexibilitätsinformation, kein Kostenattribut.
            $table->string('binding', 8)->default('P1M');
            $table->boolean('binding_confirmed')->default(false);
            $table->date('cancellable_at')->nullable();

            // Graph-SKU-GUID (nicht die lokale ID) — dieselbe Kopplung wie asset_user_licenses.sku_id.
            // Mehrere Vertragszeilen dürfen auf dieselbe SKU zeigen: das sind die Tarif-Varianten.
            $table->string('sku_id')->nullable();
            $table->string('match_state', 20)->default('unmatched'); // auto|alias|manual|unmatched|ignored
            $table->string('match_note')->nullable();

            // Von der Kaskade gefüllt: was keinem Träger zugeordnet wurde.
            $table->decimal('assigned_quantity', 12, 2)->default(0);
            $table->decimal('overflow_quantity', 12, 2)->default(0);
            $table->decimal('pool_quantity', 12, 2)->default(0);

            $table->string('import_batch_id')->nullable();
            $table->string('import_hash', 64);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            // Identität: Tenant + Abrechnungsmonat + Position. Ein erneuter Import desselben Monats
            // aktualisiert, statt zu verdoppeln.
            $table->unique('import_hash', 'asset_license_contract_lines_hash_unique');
            $table->index(['team_id', 'tenant_id', 'period_label'], 'asset_license_contract_lines_period_index');
            $table->index(['team_id', 'tenant_id', 'sku_id'], 'asset_license_contract_lines_sku_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_license_contract_lines');
    }
};
