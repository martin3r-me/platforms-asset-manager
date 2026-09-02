<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Weiterberechnung** — was wir einem Kunden für die von uns betreuten Asset-Träger in Rechnung
 * stellen (siehe docs/adr/0021).
 *
 * Zwei Tabellen, weil zwei verschiedene Dinge zu merken sind:
 *
 * `asset_billing_profiles` ist die **Regel**: welcher Kunde, welcher Artikel, und vor allem — wer
 * überhaupt zählt. Die Zählregel steht bewusst hier und nicht als Flag am Träger. Ein `billable`-Häkchen
 * an 152 Trägern wäre eine Kopie von Wissen, das die Lizenzzuweisung schon trägt, und würde bei jedem
 * Ein- und Austritt von Hand nachgepflegt werden müssen. Die Regel wird stattdessen bei jedem Lauf
 * frisch ausgewertet.
 *
 * `asset_billing_runs` ist der **Beleg-Nachweis**: was tatsächlich gezählt und gesendet wurde. Der
 * Grund für den `snapshot` ist die Frage, die drei Monate später kommt — „warum standen da 152?".
 * Ohne eingefrorene Trägerliste ist sie nicht mehr beantwortbar, weil die Lizenzzuweisungen sich
 * seither bewegt haben. Aus demselben Grund werden `unit_price_cents` und `quantity` festgehalten und
 * nicht bei Bedarf neu berechnet.
 *
 * Beträge liegen in **Cent**, nicht als Dezimalzahl. Das ist keine Vorliebe, sondern die Anforderung
 * der easybill-API (`single_price_net` erwartet Integer-Cent); jede Umrechnung an der Schnittstelle
 * wäre eine Gelegenheit, sich um Faktor 100 zu vertun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_billing_profiles')) {
            Schema::create('asset_billing_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

                $table->string('name');

                // Empfänger in easybill. Als blosse ID + Name gehalten, nicht als Fremdschlüssel:
                // easybill ist ein fremdes System, dessen Kundenstamm wir weder spiegeln noch
                // referenziell absichern können. Der Name ist eine Lesehilfe für die Oberflaeche.
                $table->unsignedBigInteger('easybill_customer_id')->nullable();
                $table->string('easybill_customer_name')->nullable();

                // Preisquelle: die SKU eines Commerce-Artikels, per fachlichem Code statt per FK
                // (Commerce ist ein Fremdmodul und darf fehlen — siehe UnitPriceResolver).
                $table->string('commerce_sku', 64)->nullable();

                // Greift, wenn Commerce nicht erreichbar ist oder die SKU dort nicht existiert.
                // Ohne diesen Rueckfall stuende der Rechnungslauf bei jedem Commerce-Ausfall still.
                $table->unsignedInteger('fallback_unit_price_cents')->nullable();

                // licensed | all_holders — wer als abzurechnender User zaehlt.
                $table->string('basis', 32)->default('licensed');

                // Domains, die nie mitzaehlen (typisch: die eigene). Ohne diesen Filter rechnet man
                // die eigenen Mitarbeiter mit, sobald sie im selben Verzeichnis stehen.
                $table->json('exclude_domains')->nullable();

                // Steuersatz der Rechnungsposition. Nullable heisst „nicht mitsenden" — dann greift
                // die Einstellung des easybill-Kunden. Bewusst hier und nicht aus Commerce gezogen:
                // der Artikel fuehrt keinen Satz (`vat_percent` ist dort leer), und ein geratener
                // Satz waere ein Fehler, den erst der Steuerberater findet.
                $table->unsignedTinyInteger('vat_percent')->nullable()->default(19);

                $table->string('currency', 3)->default('EUR');
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['team_id', 'tenant_id', 'active'], 'asset_billing_profiles_scope_index');
            });
        }

        if (! Schema::hasTable('asset_billing_runs')) {
            Schema::create('asset_billing_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();
                $table->foreignId('billing_profile_id')->constrained('asset_billing_profiles')->cascadeOnDelete();

                // Abrechnungsmonat als 'YYYY-MM'. Als Text, weil er fachlich ein Label ist und nicht
                // gerechnet wird — dieselbe Entscheidung wie bei den Vertragszeilen (period_label).
                $table->string('period', 7);

                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedInteger('unit_price_cents')->default(0);
                $table->unsignedBigInteger('total_net_cents')->default(0);
                $table->string('currency', 3)->default('EUR');

                // commerce | fallback — woher der Stueckpreis kam. Steht im Nachweis, weil ein Lauf
                // mit Rueckfallpreis anders zu bewerten ist als einer mit dem gepflegten Artikelpreis.
                $table->string('price_source', 16)->nullable();

                // draft | failed. Es gibt bewusst kein 'sent': Festschreiben und Versenden passieren
                // in easybill, und ein Status, den wir nicht selbst setzen, waere von Anfang an falsch.
                $table->string('status', 16)->default('draft');

                $table->unsignedBigInteger('easybill_document_id')->nullable();

                // Eingefrorene Trägerliste (UPNs) + die Regel, die zu ihr gefuehrt hat.
                $table->json('snapshot')->nullable();

                // Wieviele Träger die Regel ausgeschlossen hat. Bewusst mitgeschrieben, obwohl sie
                // nicht abgerechnet werden: die Zahl ist der einzige Hinweis darauf, dass eine
                // Rechnung zu niedrig sein koennte.
                $table->unsignedInteger('skipped_count')->default(0);

                $table->text('error')->nullable();

                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();

                $table->index(['team_id', 'tenant_id', 'created_at'], 'asset_billing_runs_recent_index');

                // Kein UNIQUE auf (profil, periode): fehlgeschlagene Laeufe muessen sichtbar bleiben
                // und duerfen den zweiten Versuch nicht blockieren. Gegen die versehentliche zweite
                // Rechnung schuetzt stattdessen die Pruefung im BillingRunService, die auf einen
                // bereits gesendeten Beleg (easybill_document_id gesetzt) in dieser Periode sieht.
                $table->index(['billing_profile_id', 'period'], 'asset_billing_runs_period_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_billing_runs');
        Schema::dropIfExists('asset_billing_profiles');
    }
};
