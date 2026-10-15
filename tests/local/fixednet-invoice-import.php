<?php

/**
 * Festnetz-/RingCentral-Import ({@see \Platform\AssetManager\Services\VodafoneFixedNetImportService}).
 *
 * Nachgestellt ist die echte Ausgangslage auf demo im Kleinen: Excel-Altzeilen für RingCentral
 * (Lizenz + Rabatt) und eine Internetleitung, die im Inventar noch keine Anschlusskennung trägt;
 * eine Benutzerliste mit Plätzen mit/ohne Träger, einem Träger ohne Kostenstelle und einem nicht
 * aktivierten Platz; zwei Rechnungskonten in getrennten Dateien.
 *
 * Aufruf: php tests/local/fixednet-invoice-import.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneFixedNetImportService;

// ---- Schema -------------------------------------------------------------------------------

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('code')->nullable(); $t->string('name'); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('telephony_cost_center_id')->nullable(); $t->timestamps();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->string('display_name')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable(); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key'); $t->string('name');
    $t->unsignedBigInteger('vendor_default_id')->nullable(); $t->string('allocation_level')->default('cost_center');
    $t->string('aggregation_source')->default('cost_line'); $t->boolean('allow_negative')->default(false); $t->timestamps();
});
Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('name'); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_categories', function (Blueprint $t) {
    $t->id(); $t->string('key'); $t->string('name'); $t->string('icon')->nullable(); $t->boolean('is_synced')->default(false); $t->integer('sort_order')->default(0); $t->timestamps();
});
Schema::create('asset_items', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('category_id');
    $t->string('source')->default('manual'); $t->string('external_id')->nullable(); $t->string('name'); $t->string('status')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable(); $t->text('raw_data')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_telephony_seats', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('provider')->default('ringcentral');
    $t->string('external_id'); $t->string('email')->nullable(); $t->string('display_name')->nullable(); $t->string('extension')->nullable();
    $t->string('direct_number')->nullable(); $t->string('device')->nullable(); $t->string('status')->nullable();
    $t->unsignedBigInteger('holder_id')->nullable(); $t->string('match')->nullable(); $t->timestamp('imported_at')->nullable(); $t->text('raw_data')->nullable(); $t->timestamps();
});
Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable(); $t->unsignedBigInteger('vendor_id')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable(); $t->unsignedBigInteger('asset_item_id')->nullable(); $t->string('label');
    $t->decimal('amount', 12, 2); $t->string('currency', 3)->default('EUR'); $t->decimal('fx_rate', 12, 6)->nullable(); $t->string('frequency');
    $t->decimal('monthly_amount', 12, 2)->default(0); $t->string('source')->default('manual'); $t->string('period_label')->nullable();
    $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable(); $t->boolean('active')->default(true);
    $t->string('import_batch_id')->nullable(); $t->string('import_hash', 64)->nullable(); $t->text('raw_data')->nullable(); $t->timestamps(); $t->softDeletes();
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

// ---- Fixture-Bau (Kopfzeile wörtlich aus dem Portal-Export) -------------------------------

const HEADER = 'Dokument Nummer;Rechnungskonto;Kundennummer zur Rechnung;Ihre Referenz;EBOS ID;Rechnungs-Nr.;Rechnungsdatum;Währung;Abrechnungszeitraum von;Abrechnungszeitraum bis;Abrechnungsmonat;Zahlungsziel;Zahlungsziel;Name Absender;Titel Absender;Vorname Absender;Vorname 2 Absender;Nachname Absender;Strasse Absender;Strasse 2 Absender;Postfach Absender;Ort Absender;PLZ Absender;Land Absender;Kundenebene;Lokations-Kundennummer;Referenz zur Lokations-Kundennummer;Positions-Nr Kostentyp;Kostenart ID;Kostenart;Produkt ID;Produktname;Tarif-ID;Tarifname;Produktnutzung ID;Produktnutzung Beschreibung;Rechnungsposition-Nr;Dienstenutzung ID;Dienstenutzung Beschreibung;Anzahl;Anschlusskennung;Position Netto;Position Umsatzsteuersatz;Position Umsatzsteuerpflichtig Netto;Zusatzinformation;Berechnung ab;Berechnung bis;Rabattierfähiger Betrag;Tarifzone ID;Tarifzone Beschreibung;Dauer;Einheit;Tarifzeit ID;Tarifzeit Beschreibung;Gesamtbetrag Netto;Gesamtbetrag Brutto;Gesamt Umsatzsteuerbetrag;Rechnungsbetrag Netto;Rechnung Umsatzsteuersatz;Gesamt MwSt. Betrag;Gesamt Rechnungsbetrag Netto';

function invoiceCsv(string $account, array $rows, string $total): string
{
    $cols = explode(';', HEADER);
    $lines = [HEADER];
    foreach ($rows as $values) {
        $v = $values + ['Rechnungskonto' => $account, 'Rechnungs-Nr.' => '9' . $account, 'Rechnungsdatum' => '02-10-2026',
            'Abrechnungszeitraum von' => '26-08-2026', 'Abrechnungszeitraum bis' => '25-09-2026', 'Abrechnungsmonat' => '202609',
            'Gesamt Rechnungsbetrag Netto' => $total];
        $line = []; $seen = [];
        foreach ($cols as $c) { $line[] = isset($seen[$c]) ? '' : ($v[$c] ?? ''); $seen[$c] = true; }
        $lines[] = implode(';', $line);
    }
    $path = tempnam(sys_get_temp_dir(), 'fni');
    file_put_contents($path, mb_convert_encoding(implode("\r\n", $lines), 'Windows-1252', 'UTF-8'));

    return $path;
}

$M = 'MONATLICHE LEISTUNGEN'; $UC = 'Vodafone Business UC mit RingCentral'; $NET = 'Vodafone Business Internet Connect';
$R = fn (string $tarif, string $amount, string $product = 'Vodafone Business UC Rabatt') => ['Kostenart' => 'RABATT', 'Produktname' => $product, 'Tarifname' => $tarif, 'Dienstenutzung Beschreibung' => 'Rabatt Service', 'Anschlusskennung' => '000091000823R-x', 'Position Netto' => $amount];

// Konto A: Internet 152 · Lizenzen 10 × 20 − 136 = 64 (6,40/Platz) · Anlage 120 − 36 = 84 · Verbindungen 10 − 2 = 8 → 308
$accountA = fn () => invoiceCsv('0100000001', [
    ['Kostenart' => $M, 'Produktname' => $NET, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'leitung1', 'Position Netto' => '100,000000'],
    ['Kostenart' => $M, 'Produktname' => $NET, 'Dienstenutzung Beschreibung' => '1000 Mbit/s', 'Anschlusskennung' => 'leitung1', 'Position Netto' => '52,000000'],
    ['Kostenart' => $M, 'Produktname' => $UC, 'Dienstenutzung Beschreibung' => 'Premium', 'Anschlusskennung' => '4920', 'Position Netto' => '200,000000', 'Dauer' => '10,000000', 'Einheit' => 'LIZ'],
    ['Kostenart' => $M, 'Produktname' => $UC, 'Dienstenutzung Beschreibung' => 'IP Anlagenanschluss RingCentral', 'Anschlusskennung' => '4920', 'Position Netto' => '120,000000', 'Dauer' => '10,000000', 'Einheit' => 'KNL'],
    $R('68% Lizenzen', '-136,000000'), $R('20% Anschluss', '-24,000000'), $R('10% Rabatt Kombi-Vorteil', '-12,000000'),
    $R('20% Rabatt National Mobilfunk', '-2,000000', 'Red Business'),
    ['Kostenart' => 'VOLUMENBEZOGENE LEISTUNGEN', 'Produktname' => $UC, 'Dienstenutzung Beschreibung' => 'IP Anlagenanschluss RingCentral', 'Anschlusskennung' => '4921', 'Position Netto' => '10,000000'],
], '308,000000');

// Konto B: nur eine Internetleitung
$accountB = fn () => invoiceCsv('0200000002', [
    ['Kostenart' => $M, 'Produktname' => $NET, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'leitung2', 'Position Netto' => '369,000000'],
], '369,000000');

// ---- Ausgangsdaten ------------------------------------------------------------------------

const TEAM = 1; const TENANT = 1;
DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'T', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$cc = fn (string $code) => DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => $code, 'name' => $code]);
$cc1520 = $cc('1520'); $cc2599 = $cc('2599'); $cc2890 = $cc('2890');
DB::table('asset_team_settings')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'telephony_cost_center_id' => $cc1520]);

$type = fn (string $key, string $level) => DB::table('asset_cost_types')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => $key, 'name' => $key, 'allocation_level' => $level]);
$tInternet = $type('internet', 'cost_center'); $tTel = $type('telefonie', 'cost_center');
$tRc = $type('vf_lizenz_rc', 'person'); $tRcRab = $type('vf_lizenz_rc_rab', 'person');

$holder = fn (string $name, ?int $center) => DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'display_name' => $name, 'cost_center_id' => $center]);
$anna = $holder('Anna', $cc2599); $ben = $holder('Ben', $cc2599); $ohneKst = $holder('Funktion IT', null);

$seat = fn (string $id, ?int $h, string $status = 'Aktiviert') => DB::table('asset_telephony_seats')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'provider' => 'ringcentral', 'external_id' => $id, 'holder_id' => $h, 'status' => $status, 'display_name' => $id]);
$seat('s1', $anna); $seat('s2', $ben, 'Nicht aktiviert'); $seat('s3', $ohneKst); $seat('s4', null);

$catId = DB::table('asset_categories')->insertGetId(['key' => 'internet', 'name' => 'Internet']);
$halle = DB::table('asset_items')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => $catId, 'name' => 'Halle 18']);
$gladbach = DB::table('asset_items')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => $catId, 'name' => 'Mönchengladbach']);

$excel = fn (array $v) => AssetCostLine::create($v + ['team_id' => TEAM, 'tenant_id' => TENANT, 'frequency' => 'monthly', 'source' => 'excel_import', 'active' => true]);
$excel(['cost_type_id' => $tInternet, 'asset_item_id' => $halle, 'cost_center_id' => $cc1520, 'label' => 'Halle 18', 'amount' => 937.72]);
$excel(['cost_type_id' => $tInternet, 'asset_item_id' => $gladbach, 'cost_center_id' => $cc2890, 'label' => 'Mönchengladbach', 'amount' => 369]);
$excel(['cost_type_id' => $tRc, 'assignee_id' => $anna, 'cost_center_id' => $cc2599, 'label' => 'VF Lizenz RC', 'amount' => 17.95]);
$excel(['cost_type_id' => $tRcRab, 'assignee_id' => $anna, 'cost_center_id' => $cc2599, 'label' => 'VF Lizenz RC Rabatt', 'amount' => -12.21]);

$fixednet = fn () => AssetCostLine::where('source', 'vodafone_fixednet');
$service  = new VodafoneFixedNetImportService();

// ---- Probelauf ----------------------------------------------------------------------------

$file = $accountA();
$dry = $service->import(TEAM, $file, 'test', true, TENANT, ['leitung1' => $halle]);
check('Probelauf schreibt nichts', 0, $fixednet()->count());
check('Probelauf lässt Excel-Zeilen stehen', 4, AssetCostLine::where('source', 'excel_import')->count());
check('Probelauf: Summe geht auf', true, $dry['reconciles']);
check('Probelauf: kein Monatsstand', 0, AssetInvoiceSnapshot::withoutTenantScope()->count());

// ---- Scharfer Lauf Konto A ----------------------------------------------------------------

$r = $service->import(TEAM, $file, 'test', false, TENANT, ['leitung1' => $halle]);
$sum = round((float) $fixednet()->sum('amount'), 2);
check('Σ gebuchte Zeilen = Rechnungsnetto', 308.0, $sum);
check('Stückpreis (200 − 136) / 10', 6.4, $r['telephony']['unit_net']);
check('3 Plätze mit Träger → 3 Personenzeilen', 3, $fixednet()->whereNotNull('assignee_id')->count());
check('Personenzeile auf Träger-KST', $cc2599, $fixednet()->where('assignee_id', $anna)->value('cost_center_id'));
check('Träger ohne KST → Sammelstelle', $cc1520, $fixednet()->where('assignee_id', $ohneKst)->value('cost_center_id'));
check('Pool = 10 − 3 Plätze', 7, $r['telephony']['pool_seats']);
check('Pool-Betrag fängt den Rest', 44.8, (float) $fixednet()->where('label', 'like', 'RingCentral-Lizenzen ohne Person%')->value('amount'));
check('Pool auf Kostenart telefonie (Ebene KST, kein Plausibilitätsbefund)', $tTel, $fixednet()->where('label', 'like', 'RingCentral-Lizenzen ohne Person%')->value('cost_type_id'));
check('Personenzeilen auf vf_lizenz_rc', $tRc, $fixednet()->where('assignee_id', $anna)->value('cost_type_id'));
check('Anlage 84 € auf Sammelstelle', [84.0, $cc1520], [(float) $fixednet()->where('label', 'like', '%Anlagenanschluss%')->value('amount'), $fixednet()->where('label', 'like', '%Anlagenanschluss%')->value('cost_center_id')]);
check('Verbindungen 8 € auf Sammelstelle', 8.0, (float) $fixednet()->where('label', 'RingCentral Verbindungen')->value('amount'));
check('Internet an der zugeordneten Leitung', [152.0, $halle], [(float) $fixednet()->where('cost_type_id', $tInternet)->value('amount'), $fixednet()->where('cost_type_id', $tInternet)->value('asset_item_id')]);
check('Internet übernimmt die KST der Excel-Zeile', $cc1520, $fixednet()->where('cost_type_id', $tInternet)->value('cost_center_id'));
check('Leitung trägt jetzt die Anschlusskennung', 'leitung1', AssetItem::find($halle)->external_id);
check('Excel abgelöst: Halle 18 + RC-Lizenz + RC-Rabatt', 3, $r['removed_excel']);
check('Gladbach-Excel-Zeile bleibt (anderes Konto, noch nicht importiert)', 1, AssetCostLine::where('source', 'excel_import')->count());
check('Keine Gültigkeit aus dem Rechnungszeitraum (Bug #930)', 0, $fixednet()->where(fn ($q) => $q->whereNotNull('valid_from')->orWhereNotNull('valid_to'))->count());
check('Zeitraum als period_label', '26.08.2026–25.09.2026', $fixednet()->value('period_label'));
check('Befund: nicht aktivierter Platz', true, (bool) array_filter($r['findings'], fn ($f) => str_contains($f, 'nicht aktiviert')));
check('Befund: Träger ohne KST', true, (bool) array_filter($r['findings'], fn ($f) => str_contains($f, 'ohne Kostenstelle')));

// Monatsstand für die DATEV-Umbuchung (Discussion #26).
$snap = AssetInvoiceSnapshot::withoutTenantScope()->where('source', 'vodafone_fixednet')->first();
check('Monatsstand: Buchungsmonat = Monat des Rechnungsdatums', '2026-10-01', $snap?->booking_month?->toDateString());
check('Monatsstand: Rechnungsnetto', 308.0, (float) $snap?->total_net);
check('Monatsstand: Verteilung = Rechnungsnetto', 308.0, round(array_sum(array_column((array) $snap?->distribution, 'amount')), 2));
check('Monatsstand: Kostenarten der Rechnung', ['internet', 'telefonie', 'vf_lizenz_rc'],
    array_values(array_unique(array_column((array) $snap?->distribution, 'cost_type_key'))));
check('Monatsstand: im Ergebnis', ['2026-10', []], [$r['snapshot']['month'] ?? null, $r['snapshot']['findings'] ?? null]);

// ---- Zweiter Lauf derselben Datei: idempotent ---------------------------------------------

$ids = $fixednet()->orderBy('id')->pluck('id')->all();
$r2 = $service->import(TEAM, $file, 'test', false, TENANT);
check('Wiederholung: gleiche Zeilen-IDs (Leitung jetzt über die Kennung gefunden)', $ids, $fixednet()->orderBy('id')->pluck('id')->all());
check('Wiederholung: nichts entfernt', [0, 0], [$r2['pruned_rows'], $r2['removed_excel']]);
@unlink($file);

// ---- Konto B räumt Konto A nicht ab --------------------------------------------------------

$fileB = $accountB();
$rb = $service->import(TEAM, $fileB, 'test', false, TENANT, ['leitung2' => $gladbach]);
@unlink($fileB);
check('Konto B prunt nicht Konto A', 0, $rb['pruned_rows']);
check('Konto A unverändert', 308.0, round((float) $fixednet()->where('raw_data', 'like', '%0100000001%')->sum('amount'), 2));
check('Gladbach: 369 € auf der Excel-KST 2890', [369.0, $cc2890], [(float) $fixednet()->where('asset_item_id', $gladbach)->value('amount'), $fixednet()->where('asset_item_id', $gladbach)->value('cost_center_id')]);
check('Alle Excel-Zeilen abgelöst', 0, AssetCostLine::where('source', 'excel_import')->count());

// ---- Platz entfällt in der Liste → Personenzeile wird beim nächsten Lauf entfernt ----------

DB::table('asset_telephony_seats')->where('external_id', 's2')->delete();
$file = $accountA();
$r3 = $service->import(TEAM, $file, 'test', false, TENANT);
@unlink($file);
check('Entfallener Platz: Zeile entfernt', 1, $r3['pruned_rows']);
check('… und sein Betrag landet im Pool (8 Plätze)', 51.2, (float) $fixednet()->where('label', 'like', 'RingCentral-Lizenzen ohne Person%')->value('amount'));
check('Summe bleibt die Rechnung', 308.0, round((float) $fixednet()->where('raw_data', 'like', '%0100000001%')->sum('amount'), 2));

// ---- Unbekannte Leitung ohne Zuordnung -----------------------------------------------------

$fileC = invoiceCsv('0300000003', [
    ['Kostenart' => $M, 'Produktname' => $NET, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'neu9', 'Position Netto' => '50,000000'],
], '50,000000');
$rc = $service->import(TEAM, $fileC, 'test', true, TENANT);
@unlink($fileC);
check('Unbekannte Leitung wird gemeldet', ['neu9'], $rc['unknown_lines']);

// ---- Rundung: gebucht wird exakt das Rechnungsnetto -----------------------------------------
// Positionen mit Bruchteilen von Cent (wie −1.208,394 € echt): Blöcke einzeln gerundet ergäben 307,99 €,
// die Rechnung sagt 308,00 € — der Cent geht auf den Pool.

$fileR = invoiceCsv('0400000004', [
    ['Kostenart' => $M, 'Produktname' => $NET, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'leitung4', 'Position Netto' => '152,000000'],
    ['Kostenart' => $M, 'Produktname' => $UC, 'Dienstenutzung Beschreibung' => 'Premium', 'Anschlusskennung' => '4940', 'Position Netto' => '200,000000', 'Dauer' => '10,000000', 'Einheit' => 'LIZ'],
    ['Kostenart' => $M, 'Produktname' => $UC, 'Dienstenutzung Beschreibung' => 'IP Anlagenanschluss RingCentral', 'Anschlusskennung' => '4940', 'Position Netto' => '120,000000', 'Dauer' => '10,000000', 'Einheit' => 'KNL'],
    $R('68% Lizenzen', '-135,996000'), $R('20% Anschluss', '-36,006000'),
    ['Kostenart' => 'VOLUMENBEZOGENE LEISTUNGEN', 'Produktname' => $UC, 'Dienstenutzung Beschreibung' => 'Gespräche', 'Anschlusskennung' => '4941', 'Position Netto' => '8,000000'],
], '308,000000');
$rr = $service->import(TEAM, $fileR, 'test', false, TENANT, ['leitung4' => $gladbach]);
@unlink($fileR);
check('Rundung: Ausgleich +0,01 € gemeldet', 0.01, $rr['rounding']);
check('Rundung: Σ Zeilen des Kontos = Rechnungsnetto', 308.0, round((float) $fixednet()->where('raw_data', 'like', '%0400000004%')->sum('amount'), 2));
check('Rundung: Vorschau zeigt den ausgeglichenen Pool', (float) $fixednet()->where('raw_data', 'like', '%0400000004%')->where('label', 'like', 'RingCentral-Lizenzen ohne Person%')->value('amount'), $rr['telephony']['pool_amount']);

// ---- Lizenzkonto wird abgewiesen ----------------------------------------------------------

$fileS = invoiceCsv('1057494449', [
    ['Kostenart' => $M, 'Produktname' => 'Vodafone - Software as a Service', 'Dienstenutzung Beschreibung' => 'Microsoft 365 Business Premium', 'Anschlusskennung' => 'SaaS1', 'Position Netto' => '100,000000'],
], '100,000000');
try {
    $service->import(TEAM, $fileS, 'test', true, TENANT);
    check('SaaS abgewiesen', 'Exception', 'keine');
} catch (\RuntimeException $e) {
    check('SaaS abgewiesen mit Hinweis auf Lizenz-Abrechnung', true, str_contains($e->getMessage(), 'Lizenz-Abrechnung'));
}
@unlink($fileS);

// ---- Umbuchungsbasis je Rechnung (Discussion #26) -----------------------------------------

$docs = collect((new \Platform\AssetManager\Services\CostReallocationService())->documents(TEAM, 'vodafone_fixednet'))->keyBy('account');
$a    = $docs['0100000001'];
check('Umbuchungsbasis: ein Beleg je Konto', ['0100000001', '0200000002', '0400000004'], $docs->keys()->sort()->values()->all());
check('Umbuchungsbasis: Summe = Rechnungsnetto', 308.0, $a['total']);
check('Umbuchungsbasis: Summe der Zeilen = Belegsumme', 308.0, round(array_sum(array_column($a['rows'], 'amount')), 2));
$sammel = collect($a['rows'])->where('cost_center_id', $cc1520)->where('cost_type', 'telefonie')->first();
check('Umbuchungsbasis: Telefonie auf der Sammelstelle (Anlage 84 + Verb. 8 + Pool)', round(84 + 8 + 51.2, 2), $sammel['amount']);
check('Umbuchungsbasis: Personen auf ihrer KST verdichtet', 6.4, collect($a['rows'])->where('cost_center_id', $cc2599)->where('cost_type', 'vf_lizenz_rc')->first()['amount']);
check('Umbuchungsbasis: Filter Konto', 1, count((new \Platform\AssetManager\Services\CostReallocationService())->documents(TEAM, null, '0200000002')));

check_summary();
