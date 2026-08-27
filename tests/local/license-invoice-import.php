<?php

/**
 * Lizenz-Rechnungsimport: Preisableitung, Zuordnung, Verteilungs-Kaskade und Preisbuch.
 *
 * Prüft die Kette, die aus einer Wiederverkäufer-Rechnung Kosten je Kostenstelle macht:
 *
 *  1. Monatspreis = Listenpreis × (1 − Rabatt), Menge = Bestand zum Periodenende
 *  2. Bindung aus den Rechnungssignalen („- P1Y", Rabatt „12 M 25%")
 *  3. Zuordnung Position → Graph-Lizenz (mehrere Positionen dürfen auf dieselbe zeigen)
 *  4. Kaskade: Funktionskonten ← Monatspreis · Überhang → Sammelstelle · Personen ← Bindungspreis ·
 *     Rest → Pool, und die Mengenbilanz je Zeile muss ohne Toleranz aufgehen
 *  5. Handpflege (bestätigte Bindung, Kündigungsdatum) überlebt einen erneuten Import
 *
 * Die Fixture unter `tests/fixtures/license-invoice-sample.csv` ist **synthetisch**, enthält aber jedes
 * Muster, an dem die echten Rechnungen sich sperrig verhalten: Rabattzeile mit eigener Zeilenart,
 * Teilperioden mit negativer Anzahl, zwei Tarife derselben Lizenz, Positionsnamen mit
 * Zeitraum-Anhang in zwei Sprachfassungen und eine Rabatt-Basis, die Präfix einer anderen Position ist.
 *
 * Aufruf: php tests/local/license-invoice-import.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\LicenseInvoiceImportService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\LicensePriceBook;

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

Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(true);
    $t->text('holder_type_rules')->nullable();
    $t->unsignedBigInteger('license_overflow_cost_center_id')->nullable();
    $t->unsignedBigInteger('license_pool_cost_center_id')->nullable();
    $t->timestamps();
});

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('holder_type')->default('person');
    $t->string('function_system')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});

Schema::create('asset_license_skus', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('sku_id');
    $t->string('sku_part_number');
    $t->string('display_name')->nullable();
    $t->unsignedInteger('purchased_units')->default(0);
    $t->unsignedInteger('consumed_units')->default(0);
    $t->unsignedInteger('available_units')->default(0);
    $t->decimal('unit_price', 10, 2)->nullable();
    $t->string('price_source', 20)->nullable();
    $t->string('price_period', 7)->nullable();
    $t->timestamp('price_updated_at')->nullable();
    $t->timestamp('synced_at')->nullable();
    $t->text('raw_data')->nullable();
    $t->timestamps();
});

Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('sku_id');
    $t->string('sku_part_number')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->timestamp('assigned_at')->nullable();
    $t->decimal('unit_price', 12, 4)->nullable();
    $t->string('price_source', 20)->nullable();
    $t->string('price_period', 7)->nullable();
    $t->unsignedBigInteger('contract_line_id')->nullable();
    $t->text('raw_data')->nullable();
    $t->timestamps();
});

Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id');
    $t->date('period_from');
    $t->date('period_to');
    $t->string('period_label', 7);
    $t->unsignedSmallInteger('period_days');
    $t->string('product')->nullable();
    $t->string('edition');
    $t->string('edition_key');
    $t->decimal('quantity', 12, 2)->default(0);
    $t->decimal('list_price', 12, 4)->nullable();
    $t->decimal('discount_percent', 6, 2)->default(0);
    $t->decimal('unit_price', 12, 4)->default(0);
    $t->decimal('amount_net', 12, 2)->default(0);
    $t->string('currency', 3)->default('EUR');
    $t->string('binding', 8)->default('P1M');
    $t->boolean('binding_confirmed')->default(false);
    $t->date('cancellable_at')->nullable();
    $t->string('sku_id')->nullable();
    $t->string('match_state', 20)->default('unmatched');
    $t->string('match_note')->nullable();
    $t->decimal('assigned_quantity', 12, 2)->default(0);
    $t->decimal('overflow_quantity', 12, 2)->default(0);
    $t->decimal('pool_quantity', 12, 2)->default(0);
    $t->string('import_batch_id')->nullable();
    $t->string('import_hash', 64);
    $t->text('raw_data')->nullable();
    $t->timestamps();
});

Schema::create('asset_license_billing_aliases', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id');
    $t->string('edition_key');
    $t->string('sku_id')->nullable();
    $t->string('note')->nullable();
    $t->unsignedBigInteger('created_by')->nullable();
    $t->timestamps();
});

// ---- Testrahmen ---------------------------------------------------------------------------

/**
 * Eine Bedingung prüfen. Eigene Hilfsfunktion neben dem `check()` des Bootstraps, weil hier fast
 * jede Erwartung eine zusammengesetzte Bedingung ist (Bilanzen, Mengen über mehrere Zeilen) und der
 * Vergleich „erwartet=… ist=…" dafür nichts aussagt. Zählt in dieselben Zähler, damit
 * {@see check_summary()} am Ende das Gesamtergebnis liefert.
 */
function ok(string $name, bool $condition, string $detail = ''): void
{
    $GLOBALS['am_checks']['total']++;

    printf("%-62s %s%s\n", $name, $condition ? 'ok  ' : 'FAIL', $detail !== '' ? "  ($detail)" : '');

    if (! $condition) {
        $GLOBALS['am_checks']['failed'][] = $name;
    }
}

/** Zwei Beträge auf Cent-Ebene vergleichen. */
function near(float $a, float $b, float $epsilon = 0.005): bool
{
    return abs($a - $b) < $epsilon;
}

// ---- Ausgangsdaten ------------------------------------------------------------------------

const TEAM   = 1;
const TENANT = 1;

\Illuminate\Support\Facades\DB::table('asset_tenants')
    ->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);

TenantContext::forceTenant(TENANT);

$overflowCc = \Illuminate\Support\Facades\DB::table('asset_cost_centers')
    ->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '9100', 'name' => 'IT-Gemeinkosten']);
$poolCc = \Illuminate\Support\Facades\DB::table('asset_cost_centers')
    ->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '9200', 'name' => 'Lizenzpool IT']);
$fachCc = \Illuminate\Support\Facades\DB::table('asset_cost_centers')
    ->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2599', 'name' => 'Fachabteilung']);

\Illuminate\Support\Facades\DB::table('asset_team_settings')->insert([
    'team_id'                         => TEAM,
    'tenant_id'                       => TENANT,
    'controlling_enabled'             => true,
    'license_overflow_cost_center_id' => $overflowCc,
    'license_pool_cost_center_id'     => $poolCc,
]);

/** Lizenzkatalog — Mengen so, dass die Mengenbestätigung des Matchers greifen kann. */
$skus = [
    ['sku_id' => 'sku-suite',      'part' => 'MUSTER_SUITE_STANDARD',          'name' => 'Muster Suite Standard', 'purchased' => 51, 'consumed' => 49],
    ['sku_id' => 'sku-assist',     'part' => 'Muster_Assistent',               'name' => 'Muster Assistent',      'purchased' => 2,  'consumed' => 2],
    ['sku_id' => 'sku-assist-biz', 'part' => 'MUSTER_ASSISTENT_FOR_BUSINESS',  'name' => null,                    'purchased' => 3,  'consumed' => 3],
    ['sku_id' => 'sku-archiv',     'part' => 'MUSTERARCHIV_ADDON',             'name' => null,                    'purchased' => 5,  'consumed' => 5],
    ['sku_id' => 'sku-flex',       'part' => 'MUSTER_RANDFALL_FLEX',           'name' => 'Muster Randfall Flex',  'purchased' => 2,  'consumed' => 2],
];

foreach ($skus as $sku) {
    AssetLicenseSku::create([
        'team_id'         => TEAM,
        'tenant_id'       => TENANT,
        'sku_id'          => $sku['sku_id'],
        'sku_part_number' => $sku['part'],
        'display_name'    => $sku['name'],
        'purchased_units' => $sku['purchased'],
        'consumed_units'  => $sku['consumed'],
        'available_units' => $sku['purchased'] - $sku['consumed'],
        // Handgepflegter Altpreis: muss beim Import geleert und gemeldet werden.
        'unit_price'      => $sku['sku_id'] === 'sku-suite' ? 25.00 : null,
        'price_source'    => $sku['sku_id'] === 'sku-suite' ? AssetLicenseSku::PRICE_SOURCE_MANUAL : null,
    ]);
}

/** Zwei vollständige Funktionskonten (System + Kostenstelle) und eines ohne System. */
$holders = [
    ['upn' => 'svc-archiv@test.de',  'type' => AssetHolder::TYPE_FUNCTION, 'system' => 'Archivsystem', 'cc' => $fachCc],
    ['upn' => 'svc-scan@test.de',    'type' => AssetHolder::TYPE_FUNCTION, 'system' => 'Scanstation',  'cc' => $fachCc],
    ['upn' => 'svc-unklar@test.de',  'type' => AssetHolder::TYPE_FUNCTION, 'system' => null,           'cc' => $fachCc],
];

foreach ($holders as $holder) {
    AssetHolder::create([
        'team_id'             => TEAM,
        'tenant_id'           => TENANT,
        'user_principal_name' => $holder['upn'],
        'display_name'        => $holder['upn'],
        'holder_type'         => $holder['type'],
        'function_system'     => $holder['system'],
        'cost_center_id'      => $holder['cc'],
    ]);
}

/** Suite: 2 vollständige Funktionskonten + 1 unvollständiges + 46 Personen = 49 Zuweisungen. */
$suiteUpns = array_merge(
    ['svc-archiv@test.de', 'svc-scan@test.de', 'svc-unklar@test.de'],
    array_map(fn ($i) => sprintf('person%03d@test.de', $i), range(1, 46)),
);

foreach ($suiteUpns as $upn) {
    AssetUserLicense::create([
        'team_id'             => TEAM,
        'tenant_id'           => TENANT,
        'sku_id'              => 'sku-suite',
        'sku_part_number'     => 'MUSTER_SUITE_STANDARD',
        'user_principal_name' => $upn,
    ]);
}

foreach ([['sku-assist', 2], ['sku-assist-biz', 3], ['sku-archiv', 5], ['sku-flex', 2]] as [$skuId, $count]) {
    for ($i = 1; $i <= $count; $i++) {
        AssetUserLicense::create([
            'team_id'             => TEAM,
            'tenant_id'           => TENANT,
            'sku_id'              => $skuId,
            'user_principal_name' => sprintf('%s-user%02d@test.de', $skuId, $i),
        ]);
    }
}

// ---- 1. Vorschau darf nicht schreiben ------------------------------------------------------

$service = new LicenseInvoiceImportService();
$fixture = dirname(__DIR__) . '/fixtures/license-invoice-sample.csv';

$preview = $service->import(TEAM, $fixture, 'test-preview', true, TENANT);

ok('Vorschau schreibt keine Vertragszeilen', AssetLicenseContractLine::count() === 0);
ok('Vorschau ist als Probelauf markiert', $preview['dry_run'] === true);

// ---- 2. Datei-Prüfungen --------------------------------------------------------------------

ok('Abrechnungsmonat erkannt', $preview['period']['label'] === '2026-07', $preview['period']['label']);
ok('Summe rechnet gegen Gesamtnetto auf', $preview['checks']['reconciles'] === true,
    sprintf('%.2f vs %.2f', $preview['checks']['positions_sum_net'], (float) $preview['checks']['invoice_total_net']));
ok('Jeder Monatspreis passt zum Rechnungsbetrag', $preview['checks']['prices_match'] === true,
    json_encode($preview['checks']['price_mismatches'], JSON_UNESCAPED_UNICODE));
ok('Keine unzugeordneten Rabattzeilen', $preview['checks']['unassigned_discounts'] === []);

// Sechs Positionen: die beiden Copilot-Teilperioden mit Zeitraum-Anhang in zwei Sprachfassungen
// müssen zu EINER Position verschmelzen, sonst wären es sieben.
ok('Positionen verdichtet (Teilperioden verschmolzen)', $preview['positions'] === 6,
    (string) $preview['positions']);

// ---- 3. Echter Import ----------------------------------------------------------------------

$result = $service->import(TEAM, $fixture, 'test-import', false, TENANT);
LicensePriceBook::flush();

ok('Sechs Vertragszeilen geschrieben', AssetLicenseContractLine::count() === 6,
    (string) AssetLicenseContractLine::count());
ok('Alle Positionen einer Lizenz zugeordnet', $result['unmatched'] === [],
    json_encode(array_column($result['unmatched'], 'edition'), JSON_UNESCAPED_UNICODE));

$byKey = AssetLicenseContractLine::get()->keyBy('edition_key');

/** Erwartung je Position: Preis, Menge, Bindung, Lizenz. */
$expected = [
    'muster suite standard'                              => [20.00, 11.0, 'P1M', 'sku-suite'],
    'nce muster suite standard 12 (rahmen standalone)'   => [15.00, 40.0, 'P1Y', 'sku-suite'],
    'muster assistent'                                   => [9.00,  2.0,  'P1Y', 'sku-assist'],
    'muster assistent business (standalone add-on)'      => [8.00,  3.0,  'P1M', 'sku-assist-biz'],
    'muster archiv ablage - addon'                        => [5.00,  5.0,  'P1M', 'sku-archiv'],
    'muster randfall flex'                               => [5.00,  2.0,  'P1M', 'sku-flex'],
];

foreach ($expected as $key => [$price, $quantity, $binding, $skuId]) {
    $line = $byKey[$key] ?? null;

    if ($line === null) {
        ok("Position vorhanden: $key", false);
        continue;
    }

    ok("Preis $key", near((float) $line->unit_price, $price), sprintf('%.4f erwartet %.2f', $line->unit_price, $price));
    ok("Menge $key", near((float) $line->quantity, $quantity), sprintf('%.2f erwartet %.2f', $line->quantity, $quantity));
    ok("Bindung $key", $line->binding === $binding, $line->binding);
    ok("Lizenz $key", $line->sku_id === $skuId, (string) $line->sku_id);
    ok("Mengenbilanz $key", $line->quantityBalances(),
        sprintf('%.2f + %.2f + %.2f vs %.2f', $line->assigned_quantity, $line->overflow_quantity, $line->pool_quantity, $line->quantity));
}

// Zwei Tarife derselben Lizenz — der Kern des Ganzen.
ok('Zwei Tarife auf derselben Lizenz',
    AssetLicenseContractLine::where('sku_id', 'sku-suite')->count() === 2);
ok('Bindung unbestätigt nach Import',
    AssetLicenseContractLine::where('binding_confirmed', true)->count() === 0);

// ---- 4. Handgepflegter SKU-Preis wurde abgelöst und gemeldet -------------------------------

$suite = AssetLicenseSku::where('sku_id', 'sku-suite')->first();

ok('Handpreis geleert', $suite->unit_price === null, (string) $suite->unit_price);
ok('Preisquelle auf Vertragszeile', $suite->isContractPriced(), (string) $suite->price_source);
ok('Alter Handpreis gemeldet',
    collect($result['price_moves'])->firstWhere('sku', 'MUSTER_SUITE_STANDARD')['previous_price'] === 25.0,
    json_encode($result['price_moves']));

// ---- 5. Kaskade ----------------------------------------------------------------------------

$monthly = $byKey['muster suite standard'];
$yearly  = $byKey['nce muster suite standard 12 (rahmen standalone)'];

// Schritt 1: die ZWEI vollständigen Funktionskonten bekommen den Monatspreis, das unvollständige nicht.
$functionSeats = AssetUserLicense::whereIn('user_principal_name', ['svc-archiv@test.de', 'svc-scan@test.de'])
    ->where('sku_id', 'sku-suite')->get();

ok('Funktionskonten auf Monatspreis-Zeile',
    $functionSeats->every(fn ($s) => $s->contract_line_id === $monthly->id));
ok('Funktionskonten tragen den Monatspreis',
    $functionSeats->every(fn ($s) => near((float) $s->unit_price, 20.00)));

$incomplete = AssetUserLicense::where('user_principal_name', 'svc-unklar@test.de')
    ->where('sku_id', 'sku-suite')->first();

ok('Unvollständiges Funktionskonto ohne Monatspreis-Vorrang',
    $incomplete->contract_line_id !== $monthly->id);
// Ohne System oder Kostenstelle greift der Vorrang aus Schritt 1 nicht: das Konto reiht sich bei den
// Personen ein und konkurriert nach Kennung mit ihnen. Hier reicht das Jahres-Kontingent (40) nicht
// für 47 Bewerber, und „svc-unklar" steht alphabetisch hinter allen „person…" — es geht leer aus.
// Sichtbar wird das über die Meldung unten, nicht über einen stillen Nullbetrag.
ok('Unvollständiges Funktionskonto konkurriert wie eine Person',
    $incomplete->unit_price === null || near((float) $incomplete->unit_price, 15.00),
    $incomplete->unit_price === null ? 'leer ausgegangen' : (string) $incomplete->unit_price);

$suiteStats = collect($result['allocation']['skus'])->firstWhere('sku_id', 'sku-suite');

ok('Unvollständige Funktionskonten gemeldet',
    $suiteStats['incomplete_function_accounts'] === 1, (string) $suiteStats['incomplete_function_accounts']);

// Schritt 2: 11 Monats-Seats minus 2 Funktionskonten = 9 auf die Überhang-Kostenstelle.
ok('Überhang: 9 Monats-Seats', near((float) $monthly->overflow_quantity, 9.0),
    (string) $monthly->overflow_quantity);
ok('Überhang-Betrag 180,00', near($suiteStats['overflow_amount'], 180.00),
    (string) $suiteStats['overflow_amount']);

// Schritt 4: 40 Jahres-Seats an Personen (und das unvollständige Funktionskonto).
ok('Jahres-Seats vollständig vergeben', near((float) $yearly->assigned_quantity, 40.0),
    (string) $yearly->assigned_quantity);
ok('Kein Pool bei der Suite', near((float) $yearly->pool_quantity, 0.0));

// 49 Zuweisungen, 42 bezahlt (2 Monats + 40 Jahres) → 7 ohne Bezahlung.
ok('Sieben Seats ohne Bezahlung gemeldet', $suiteStats['unpaid_seats'] === 7,
    (string) $suiteStats['unpaid_seats']);
ok('Unbezahlte Seats haben keinen Preis',
    AssetUserLicense::where('sku_id', 'sku-suite')->whereNull('unit_price')->count() === 7);

// Ohne Funktionskonten geht eine reine Monatspreis-Lizenz komplett in den Überhang.
$archiv = $byKey['muster archiv ablage - addon'];
ok('Monatslizenz ohne Funktionskonten → voller Überhang',
    near((float) $archiv->overflow_quantity, 5.0) && near((float) $archiv->assigned_quantity, 0.0),
    sprintf('%.2f / %.2f', $archiv->overflow_quantity, $archiv->assigned_quantity));

// ---- 6. Summen ------------------------------------------------------------------------------

$totals = $result['allocation']['totals'];

// Monatsvolle Rechnung: 11×20 + 40×15 + 2×9 + 3×8 + 5×5 + 2×5 = 897,00
ok('Rechnungsbetrag im Monatserst-Snapshot', near((float) $totals['invoice_amount'], 897.00),
    (string) $totals['invoice_amount']);
ok('Seats + Überhang + Pool = Rechnungsbetrag',
    near($totals['seat_amount'] + $totals['overflow_amount'] + $totals['pool_amount'], 897.00),
    sprintf('%.2f + %.2f + %.2f', $totals['seat_amount'], $totals['overflow_amount'], $totals['pool_amount']));
ok('Mengenbilanz über alle Zeilen', $result['allocation']['balanced'] === true);

// Die Differenz zur Datei (882,16) ist die bewusste Folge des Snapshots — untermonatliche Zugänge
// werden voll berechnet, die Datei rechnet sie zeitanteilig.
ok('Snapshot-Differenz beziffert', near((float) $result['checks']['snapshot_difference'], 14.84),
    (string) $result['checks']['snapshot_difference']);

// ---- 7. Preisbuch ---------------------------------------------------------------------------

$book = LicensePriceBook::for(TEAM);

ok('Preisbuch kennt den Monat', $book->period() === '2026-07', (string) $book->period());
ok('Preisbuch: Suite-Kosten = 820,00', near($book->monthlyCostForSku('sku-suite'), 820.00),
    (string) $book->monthlyCostForSku('sku-suite'));
ok('Preisbuch: Preisspanne 15,00–20,00',
    $book->priceRangeForSku('sku-suite') === ['min' => 15.0, 'max' => 20.0, 'count' => 2],
    json_encode($book->priceRangeForSku('sku-suite')));

$overflowRows = $book->unallocatedRows()->where('kind', 'overflow');

ok('Überhang trägt die konfigurierte Kostenstelle',
    $overflowRows->every(fn ($r) => $r['cost_center_id'] === $overflowCc));
ok('Überhang gesamt 5×5 + 2×5 + 3×8 + 9×20 = 239,00',
    near((float) $overflowRows->sum('amount'), 239.00), (string) $overflowRows->sum('amount'));

// ---- 8. Erneuter Import: idempotent, Handpflege bleibt --------------------------------------

$yearly->update(['binding_confirmed' => true, 'binding' => 'P1Y', 'cancellable_at' => '2027-06-30']);
$monthly->update(['binding_confirmed' => true, 'binding' => 'P1Y']); // absichtlich abweichend gesetzt

$service->import(TEAM, $fixture, 'test-rerun', false, TENANT);
LicensePriceBook::flush();

ok('Kein Verdoppeln bei erneutem Import', AssetLicenseContractLine::count() === 6,
    (string) AssetLicenseContractLine::count());

$monthlyAfter = AssetLicenseContractLine::find($monthly->id);
$yearlyAfter  = AssetLicenseContractLine::find($yearly->id);

ok('Bestätigte Bindung wird nicht überschrieben', $monthlyAfter->binding === 'P1Y',
    $monthlyAfter->binding);
ok('Kündigungsdatum bleibt erhalten',
    $yearlyAfter->cancellable_at?->format('Y-m-d') === '2027-06-30',
    (string) $yearlyAfter->cancellable_at);

// Mit beiden Zeilen auf Jahresbindung gibt es keine Monatspreis-Zeile mehr: die Funktionskonten
// fallen in Schritt 3 und der Überhang verschwindet — die Bilanz muss weiter aufgehen.
ok('Bilanz hält nach geänderter Bindung',
    AssetLicenseContractLine::get()->every(fn ($l) => $l->quantityBalances()));
ok('Kein Überhang mehr bei der Suite',
    near((float) AssetLicenseContractLine::find($monthly->id)->overflow_quantity, 0.0),
    (string) AssetLicenseContractLine::find($monthly->id)->overflow_quantity);

// ---- 9. Handzuordnung lernt einen Alias ----------------------------------------------------

$flex = AssetLicenseContractLine::where('edition_key', 'muster randfall flex')->first();
$service->assignSku(TEAM, $flex->id, null, TENANT);

$flexAfter = AssetLicenseContractLine::find($flex->id);

ok('Handausschluss gesetzt',
    $flexAfter->match_state === AssetLicenseContractLine::MATCH_IGNORED, $flexAfter->match_state);
ok('Alias gelernt',
    \Illuminate\Support\Facades\DB::table('asset_license_billing_aliases')
        ->where('edition_key', 'muster randfall flex')->whereNull('sku_id')->exists());

$service->import(TEAM, $fixture, 'test-after-alias', false, TENANT);

ok('Ausgeschlossene Position bleibt ausgeschlossen',
    AssetLicenseContractLine::where('edition_key', 'muster randfall flex')->first()->match_state
        === AssetLicenseContractLine::MATCH_IGNORED);

// ---- Ergebnis -------------------------------------------------------------------------------

// ---- 10. Ein neues Funktionskonto verschiebt Mengen ----------------------------------------

// „Muster Archiv Ablage" ist eine reine Monatspreis-Zeile mit 5 Seats, alle auf Personen — also
// vollständig im Überhang. Wird einer der Träger zum Funktionskonto (mit System und Kostenstelle),
// muss Schritt 1 der Kaskade greifen und genau ein Seat auf den Träger wandern. Genau das passiert
// im UI beim Umstellen des Träger-Typs; ohne erneute Verteilung bliebe die Kostenaufteilung stehen.
$archivSeat = AssetUserLicense::where('sku_id', 'sku-archiv')->orderBy('user_principal_name')->first();

AssetHolder::create([
    'team_id'             => TEAM,
    'tenant_id'           => TENANT,
    'user_principal_name' => $archivSeat->user_principal_name,
    'display_name'        => 'Archiv-Dienst',
    'holder_type'         => AssetHolder::TYPE_FUNCTION,
    'function_system'     => 'Archivsystem',
    'cost_center_id'      => $fachCc,
]);

(new \Platform\AssetManager\Services\LicenseSeatAllocator())
    ->allocateForPeriod(TEAM, TENANT, '2026-07', false);

LicensePriceBook::flush();

$archivAfter = AssetLicenseContractLine::where('edition_key', 'muster archiv ablage - addon')->first();

ok('Neues Funktionskonto erhält den Monatspreis',
    near((float) AssetUserLicense::find($archivSeat->id)->unit_price, 5.00),
    (string) AssetUserLicense::find($archivSeat->id)->unit_price);
ok('Überhang sinkt von 5 auf 4', near((float) $archivAfter->overflow_quantity, 4.0),
    (string) $archivAfter->overflow_quantity);
ok('Mengenbilanz hält nach Typänderung', $archivAfter->quantityBalances(),
    sprintf('%.2f + %.2f + %.2f vs %.2f', $archivAfter->assigned_quantity,
        $archivAfter->overflow_quantity, $archivAfter->pool_quantity, $archivAfter->quantity));

check_summary();
