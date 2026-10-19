<?php

/**
 * Vodafone-Rechnungsimport: Mobilfunk bleibt nach Ablauf des Rechnungszeitraums in der Auswertung.
 *
 * Regression zu Bug #930. Der Import schrieb den Abrechnungszeitraum der Rechnung als
 * `valid_from`/`valid_to`; die Kostenauswertungen zählen aber nur `active()->validOn(now())`. Eine
 * Rechnung liegt immer in der Vergangenheit — nach dem Ende ihres Zeitraums fiel der gesamte Mobilfunk
 * still aus Pivot und Dashboard (demo: ab 15.07.2026, rund 3.950 €/Monat).
 *
 * Geprüft wird:
 *  1. Eine importierte Rechnung mit Zeitraum in der Vergangenheit zählt heute voll mit.
 *  2. Der Zeitraum bleibt als `period_label` erhalten.
 *  3. Ein erneuter Import setzt eine alte, falsch gesetzte Gültigkeit am Bestand zurück.
 *  4. Prune und Zuordnung arbeiten weiter wie bisher (abgängige Nummer weg, Träger-Kostenstelle gewinnt).
 *
 * Die Datei wird nicht gelesen: ein Unterklasse liefert die Zeilen direkt, im selben Spaltenschema wie
 * der Export „Rechnungsdaten" (A Typ … J Netto, K D2-Nummer, L Beschreibung, M Kostenstelle).
 *
 * Aufruf: php tests/local/vodafone-invoice-validity.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;

// ---- Schema (nur die Tabellen, die dieser Pfad anfasst) -----------------------------------

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->string('name');
    $t->boolean('is_default')->default(false);
    $t->timestamps();
});

Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('code')->nullable();
    $t->string('name');
    $t->timestamps();
});

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('mobile_phone')->nullable();
    $t->string('business_phone')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});

Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('key');
    $t->string('name');
    $t->integer('sort_order')->default(0);
    $t->string('allocation_level')->default('person');
    $t->string('aggregation_source')->default('cost_line');
    $t->timestamps();
});

Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('name');
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->unsignedBigInteger('vendor_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable();
    $t->unsignedBigInteger('asset_item_id')->nullable();
    $t->string('label');
    $t->decimal('amount', 12, 2);
    $t->string('currency', 3)->default('EUR');
    $t->decimal('fx_rate', 12, 6)->nullable();
    $t->string('frequency');
    $t->decimal('monthly_amount', 12, 2)->default(0);
    $t->string('source')->default('manual');
    $t->string('period_label')->nullable();
    $t->date('valid_from')->nullable();
    $t->date('valid_to')->nullable();
    $t->boolean('active')->default(true);
    $t->string('import_batch_id')->nullable();
    $t->string('import_hash', 64)->nullable();
    $t->text('raw_data')->nullable();
    $t->timestamps();
    $t->softDeletes();
});

// Monatsstand je Rechnung (InvoiceSnapshotService) — der scharfe Import schreibt ihn mit.
Schema::create('asset_invoice_snapshots', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id');
    $t->string('source', 32); $t->string('account', 64)->default(''); $t->string('invoice', 64);
    $t->date('invoice_date')->nullable(); $t->date('booking_month')->nullable(); $t->string('period_label')->nullable();
    $t->decimal('total_net', 12, 2); $t->json('distribution'); $t->unsignedBigInteger('datev_batch_id')->nullable();
    $t->timestamp('captured_at')->nullable(); $t->timestamps();
    $t->unique(['tenant_id', 'source', 'account', 'invoice']);
});

// ---- Testrahmen ---------------------------------------------------------------------------

function ok(string $name, bool $condition, string $detail = ''): void
{
    $GLOBALS['am_checks']['total']++;

    printf("%-62s %s%s\n", $name, $condition ? 'ok  ' : 'FAIL', $detail !== '' ? "  ($detail)" : '');

    if (! $condition) {
        $GLOBALS['am_checks']['failed'][] = $name;
    }
}

/** Importer, der statt einer Datei vorgegebene Zeilen liest. */
final class RowsVodafoneImport extends VodafoneInvoiceImportService
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
        parent::__construct();
    }

    protected function readRows(string $path): array
    {
        return $this->rows;
    }
}

/** Eine Rechnung im Spaltenschema des Exports. */
function invoice(string $from, string $to, array $numbers, array $items = []): array
{
    $net  = array_sum($numbers) + array_sum($items);
    $base = ['B' => '86839593', 'C' => '1225', 'D' => $to, 'E' => $from, 'F' => $to];
    $rows = [['A' => 'Rechnung'] + $base + ['J' => $net]];
    foreach ($numbers as $msisdn => $amount) {
        $rows[] = ['A' => 'Rufnummer'] + $base + ['J' => $amount, 'K' => (string) $msisdn, 'M' => ''];
    }
    foreach ($items as $label => $amount) {
        $rows[] = ['A' => 'Rechnungsposten'] + $base + ['J' => $amount, 'L' => $label, 'M' => ''];
    }

    return $rows;
}

/** Was die Auswertungen sehen: aktive, heute gültige Mobilfunk-Zeilen. */
function countedToday(): float
{
    return round((float) AssetCostLine::active()->validOn(now())
        ->where('source', VodafoneInvoiceImportService::SOURCE)
        ->sum('monthly_amount'), 2);
}

// ---- Ausgangsdaten ------------------------------------------------------------------------

const TEAM   = 1;
const TENANT = 1;

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$ccPerson = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '8090', 'name' => 'Kita']);
DB::table('asset_cost_types')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk']);
$holder = DB::table('asset_holders')->insertGetId([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'display_name' => 'Test Person',
    'mobile_phone' => '+49 163 8588153', 'cost_center_id' => $ccPerson,
]);

// ---- 1 + 2: Rechnung mit Zeitraum in der Vergangenheit ------------------------------------

$july = invoice('15.06.2026', '14.07.2026', ['01638588153' => 46.0, '01738580129' => 21.0], ['Vodafone-CorporateVPN' => 50.0, 'Rabatt OfficeVPN' => -50.0]);
$res  = (new RowsVodafoneImport($july))->import(TEAM, 'egal', 'test-juli', false, TENANT);

ok('Rechnung geht auf (reconciles)', $res['reconciles'] === true);
ok('Juli-Rechnung zählt heute voll mit (67,00 €)', countedToday() === 67.0, 'ist ' . countedToday());
ok('keine Gültigkeit aus dem Rechnungszeitraum',
    AssetCostLine::where('source', 'vodafone_invoice')->where(fn ($q) => $q->whereNotNull('valid_from')->orWhereNotNull('valid_to'))->count() === 0);
ok('Zeitraum bleibt als period_label erhalten',
    AssetCostLine::where('label', 'like', 'Mobilfunk%')->first()?->period_label === '15.06.2026–14.07.2026');

$line = AssetCostLine::where('raw_data', 'like', '%4916385881%')->first();
ok('Nummer mit Träger: Träger + dessen Kostenstelle', $line?->assignee_id === $holder && $line?->cost_center_id === $ccPerson);

// ---- 3: Altbestand mit falscher Gültigkeit wird beim nächsten Import repariert ------------

AssetCostLine::where('source', 'vodafone_invoice')->each(fn ($l) => $l->update(['valid_from' => '2026-06-15', 'valid_to' => '2026-07-14']));
ok('Vorbedingung: Altbestand fällt heute heraus', countedToday() === 0.0);

$sept = invoice('15.08.2026', '14.09.2026', ['01638588153' => 46.0], ['Vodafone-CorporateVPN' => 50.0, 'Rabatt OfficeVPN' => -50.0]);
$res  = (new RowsVodafoneImport($sept))->import(TEAM, 'egal', 'test-sept', false, TENANT);

ok('nach neuem Import zählt der Bestand wieder (46,00 €)', countedToday() === 46.0, 'ist ' . countedToday());

// ---- 4: Prune unverändert ----------------------------------------------------------------

ok('abgängige Nummer wurde entfernt (Prune)', $res['pruned_rows'] === 1, 'pruned ' . $res['pruned_rows']);
ok('Zeile behält ihre ID über Monate (Hash ohne Betrag)', AssetCostLine::find($line->id) !== null);

// ---- 5: „Rechnungsdaten CSV" aus dem Portal (Windows-1252, Semikolon, leere Spalte M) -----

// Kopfzeile wörtlich wie im Portal-Export; Daten synthetisch.
$csv = implode("\r\n", [
    'Typ;Rechnungskonto;Rechnungs-Nummer;Rechnungs-Datum;Abrechnungszeitraum von;Abrechnungszeitraum bis;Brutto-Betrag Euro;Ust.-Satz %;Ust.-Betrag Euro;Netto-Betrag Euro;D2-Nummer;Beschreibung;Kostenstelle',
    'Rechnung;86839593;1225;18.09.2026;15.08.2026;14.09.2026;1.309,00;19;209,00;1.100,00;;;',
    'Rufnummer;86839593;1225;18.09.2026;15.08.2026;14.09.2026;;19;;46,0000;01638588153;;',
    'Rufnummer;86839593;1225;18.09.2026;15.08.2026;14.09.2026;;19;;1.004,0000;015202316833;;',
    'Rechnungsposten;86839593;1225;18.09.2026;15.08.2026;14.09.2026;;19;;50,0000;;Vodafone-CorporateVPN – Grundpreis;',
]);
$csvPath = tempnam(sys_get_temp_dir(), 'vf');
file_put_contents($csvPath, mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

// Vorbelegte Kostenstelle für eine Nummer ohne Träger und für den Kontoposten (wie auf demo aus dem
// früheren .xlsx-Export) — der CSV-Lauf darf sie nicht leeren.
$ccPool = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1500', 'name' => 'IT']);
(new RowsVodafoneImport(invoice('15.07.2026', '14.08.2026', ['01638588153' => 46.0, '015202316833' => 21.0], ['Vodafone-CorporateVPN – Grundpreis' => 50.0])))
    ->import(TEAM, 'egal', 'test-aug', false, TENANT);
AssetCostLine::where('source', 'vodafone_invoice')->whereNull('assignee_id')->each(fn ($l) => $l->update(['cost_center_id' => $ccPool]));

$res = (new VodafoneInvoiceImportService())->import(TEAM, $csvPath, 'test-csv', false, TENANT);
@unlink($csvPath);

ok('CSV: Rechnung erkannt, 2 Nummern + 1 Posten', $res['stats']['numbers'] === 2 && $res['stats']['account_items'] === 1,
    $res['stats']['numbers'] . ' / ' . $res['stats']['account_items']);
ok('CSV: deutsches Zahlenformat inkl. Tausenderpunkt', $res['sum_net'] === 1100.0, 'sum ' . $res['sum_net']);
ok('CSV: Summe geht auf den Kopf auf', $res['reconciles'] === true);
ok('CSV: Zeitraum aus dd.mm.yyyy', $res['period']['from']?->format('Y-m-d') === '2026-08-15');
ok('CSV: Umlaut/Gedankenstrich aus Windows-1252 korrekt',
    AssetCostLine::where('label', 'Vodafone-CorporateVPN – Grundpreis')->exists());
ok('CSV: Nummer mit Träger bekommt dessen Kostenstelle',
    AssetCostLine::where('assignee_id', $holder)->value('cost_center_id') === $ccPerson);
ok('CSV: Nummer ohne Träger behält ihre Kostenstelle (Spalte M leer)',
    AssetCostLine::where('source', 'vodafone_invoice')->whereNull('assignee_id')->where('raw_data', 'like', '%4915202316833%')->value('cost_center_id') === $ccPool);
ok('CSV: Kontoposten behält seine Kostenstelle',
    AssetCostLine::where('label', 'Vodafone-CorporateVPN – Grundpreis')->value('cost_center_id') === $ccPool);
ok('CSV: neuer Betrag übernommen (1.004,00 €)',
    (float) AssetCostLine::where('raw_data', 'like', '%4915202316833%')->value('amount') === 1004.0);

// ---- 6: Rundungsausgleich — gebucht wird exakt das Rechnungsnetto -------------------------

// Vodafone liefert Netto mit vier Nachkommastellen; jede Zeile auf den Cent gerundet ergibt hier
// 35,00 € statt 35,02 € Rechnungsnetto (September 2026: 4.008,50 statt 4.008,52).
$vodafone = fn () => AssetCostLine::where('source', 'vodafone_invoice');
$oct = invoice('15.09.2026', '14.10.2026',
    ['01638588153' => 10.0049, '01738580129' => 10.0049, '015202316833' => 10.0049],
    ['Vodafone-CorporateVPN' => 5.0049]);
$oct[0]['J'] = 35.02; // Rechnungskopf, wie im Export auf den Cent

$res = (new RowsVodafoneImport($oct))->import(TEAM, 'egal', 'test-okt', true, TENANT);
ok('Rundung: Probelauf meldet den Ausgleich (+0,02 €)', ($res['rounding'] ?? null) === 0.02, 'ist ' . var_export($res['rounding'] ?? null, true));

$res = (new RowsVodafoneImport($oct))->import(TEAM, 'egal', 'test-okt', false, TENANT);
ok('Rundung: Σ Zeilen = Rechnungsnetto (35,02 €)', round((float) $vodafone()->sum('amount'), 2) === 35.02,
    'ist ' . round((float) $vodafone()->sum('amount'), 2));
ok('Rundung: Ausgleich auf dem Kontoposten, nicht auf einer Person',
    (float) $vodafone()->where('label', 'Vodafone-CorporateVPN')->value('amount') === 5.02
    && (float) $vodafone()->where('assignee_id', $holder)->value('amount') === 10.0);
ok('Rundung: keine offene Abweichung', array_key_exists('rounding_unbalanced', $res) && $res['rounding_unbalanced'] === null);

(new RowsVodafoneImport($oct))->import(TEAM, 'egal', 'test-okt-2', false, TENANT);
ok('Rundung: zweiter Lauf gleicht nicht doppelt aus', round((float) $vodafone()->sum('amount'), 2) === 35.02,
    'ist ' . round((float) $vodafone()->sum('amount'), 2));

// Ohne Kontoposten trägt die erste Zeile den Rest.
$nov = invoice('15.10.2026', '14.11.2026', ['01638588153' => 10.0049, '01738580129' => 10.0049]);
$nov[0]['J'] = 20.01;
(new RowsVodafoneImport($nov))->import(TEAM, 'egal', 'test-nov', false, TENANT);
ok('Rundung: ohne Kontoposten geht der Rest auf die erste Zeile',
    (float) $vodafone()->where('assignee_id', $holder)->value('amount') === 10.01
    && round((float) $vodafone()->sum('amount'), 2) === 20.01);

// Mehr als ½ Cent je Zeile ist keine Rundung mehr — dann Befund, kein stiller Ausgleich.
$dec = invoice('15.11.2026', '14.12.2026', ['01638588153' => 10.0, '01738580129' => 10.0]);
$dec[0]['J'] = 20.05;
$res = (new RowsVodafoneImport($dec))->import(TEAM, 'egal', 'test-dez', false, TENANT);
ok('Rundung: echte Abweichung wird nicht ausgeglichen',
    ($res['rounding'] ?? null) === 0.0 && round((float) $vodafone()->sum('amount'), 2) === 20.0);
ok('Rundung: echte Abweichung wird gemeldet (0,05 €)', ($res['rounding_unbalanced'] ?? null) === 0.05,
    'ist ' . var_export($res['rounding_unbalanced'] ?? null, true));


// ---- 7: Zwei Rechnungskonten, gleich benannte Posten, ältere Rechnung (#942, #967, #948) ---

/** Rechnung mit eigenem Konto und eigener Nummer. */
function invoiceFor(string $account, string $nr, string $from, string $to, array $numbers, array $items = []): array
{
    $rows = invoice($from, $to, $numbers);
    foreach ($rows as &$row) {
        $row['B'] = $account;
        $row['C'] = $nr;
    }
    unset($row);
    $base = ['B' => $account, 'C' => $nr, 'D' => $to, 'E' => $from, 'F' => $to];
    foreach ($items as [$label, $amount]) {
        $rows[] = ['A' => 'Rechnungsposten'] + $base + ['J' => $amount, 'L' => $label, 'M' => ''];
        $rows[0]['J'] += $amount;
    }

    return $rows;
}

$byAccount = fn (string $account) => AssetCostLine::where('source', 'vodafone_invoice')
    ->where('raw_data', 'like', '%"rechnungskonto":"' . $account . '"%');

// Konto A (bisheriges, Altzeilen) und ein zweites Mobilfunk-Konto B im selben Tenant.
(new RowsVodafoneImport(invoiceFor('99999999', 'B-1', '15.11.2026', '14.12.2026', ['01701112233' => 30.0], [['Rabatt', -5.0]])))
    ->import(TEAM, 'egal', 'test-b', false, TENANT);
$aBefore = $byAccount('86839593')->count();
ok('Konto B: Zeilen angelegt', $byAccount('99999999')->count() === 2, 'ist ' . $byAccount('99999999')->count());
ok('Konto B löscht keine Zeilen von Konto A (#942)', $aBefore > 0, 'A ' . $aBefore);

$aIds = $byAccount('86839593')->pluck('id')->sort()->values()->all();
(new RowsVodafoneImport(invoiceFor('86839593', 'A-2', '15.12.2026', '14.01.2027', ['01638588153' => 10.0, '01738580129' => 10.0])))
    ->import(TEAM, 'egal', 'test-a2', false, TENANT);
ok('Konto A: Altzeilen behalten ihre ID (Hash übernommen)',
    count(array_intersect($aIds, $byAccount('86839593')->pluck('id')->all())) === 2);
ok('Konto A löscht keine Zeilen von Konto B (#942)', $byAccount('99999999')->count() === 2);

// Gleich benannte Posten auf derselben Rechnung (#967).
$res = (new RowsVodafoneImport(invoiceFor('99999999', 'B-2', '15.12.2026', '14.01.2027', ['01701112233' => 30.0], [['Rabatt', -5.0], ['Rabatt', -7.0]])))
    ->import(TEAM, 'egal', 'test-b2', false, TENANT);
ok('Zwei Posten „Rabatt“: beide Zeilen da (#967)', $byAccount('99999999')->where('label', 'Rabatt')->count() === 2);
ok('Zwei Posten „Rabatt“: Summe −12,00 €', round((float) $byAccount('99999999')->where('label', 'Rabatt')->sum('amount'), 2) === -12.0);
ok('Zwei Posten „Rabatt“: Rechnung geht auf', $res['reconciles'] === true);

// Ältere Rechnung desselben Kontos (#948).
$before = round((float) $byAccount('99999999')->sum('amount'), 2);
$error  = null;
try {
    (new RowsVodafoneImport(invoiceFor('99999999', 'B-0', '15.10.2026', '14.11.2026', ['01701112233' => 99.0])))
        ->import(TEAM, 'egal', 'test-b0', false, TENANT);
} catch (\RuntimeException $e) {
    $error = $e->getMessage();
}
ok('Ältere Rechnung wird abgelehnt (#948)', $error !== null && str_contains($error, 'älter'), (string) $error);
ok('Ältere Rechnung: Kostenstand unverändert', round((float) $byAccount('99999999')->sum('amount'), 2) === $before);

// Dieselbe Rechnung noch einmal (Korrektur) bleibt möglich.
$res = (new RowsVodafoneImport(invoiceFor('99999999', 'B-2', '15.12.2026', '14.01.2027', ['01701112233' => 31.0], [['Rabatt', -5.0], ['Rabatt', -7.0]])))
    ->import(TEAM, 'egal', 'test-b2-korr', false, TENANT);
ok('Gleiche Rechnung erneut: erlaubt', $res['reconciles'] === true);



// Zeile aus einem Lauf vor #942 (Hash ohne Konto) wird übernommen statt neu angelegt + gelöscht.
$legacyId = AssetCostLine::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'label' => 'Mobilfunk alt', 'amount' => 1, 'frequency' => 'monthly',
    'source' => 'vodafone_invoice', 'cost_center_id' => $ccPool,
    'import_hash' => sha1(implode('|', ['vodafone_invoice', TEAM, TENANT, 'mobilfunk', '+491799998877'])),
    'raw_data' => ['rechnungskonto' => '86839593', 'rechnung' => 'A-2'],
])->id;
(new RowsVodafoneImport(invoiceFor('86839593', 'A-3', '15.01.2027', '14.02.2027', ['01638588153' => 10.0, '01799998877' => 12.0])))
    ->import(TEAM, 'egal', 'test-a3', false, TENANT);
$legacy = AssetCostLine::find($legacyId);
ok('Altzeile (Hash ohne Konto) behält ID', $legacy !== null);
ok('Altzeile: Betrag aktualisiert', (float) $legacy?->amount === 12.0);
ok('Altzeile: im UI gepflegte Kostenstelle bleibt', $legacy?->cost_center_id === $ccPool);
ok('Altzeile: keine Dublette', AssetCostLine::where('raw_data', 'like', '%491799998877%')->count() === 1);

check_summary();
