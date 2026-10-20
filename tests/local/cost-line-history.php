<?php

/**
 * Preis-Historie der Rechnungsimporte (ADR 0029).
 *
 *  1. Jeder scharfe Import hält je Kostenposition den Betrag der Rechnung fest; derselbe Lauf erneut
 *     überschreibt nur seinen Eintrag.
 *  2. Regelpreis = häufigster Betrag; der Ausreißer im letzten Monat (21 € → 4,06 €) wird erkannt.
 *  3. „Historie nachtragen": eine ältere Rechnung geht trotz #948 durch, ändert aber weder die Kosten
 *     noch den Monatsstand — nur der Verlauf wächst, an der heutigen Kostenposition.
 *  4. Eine heute nicht mehr vorhandene Nummer bekommt Verlauf ohne Kostenposition (über den Hash).
 *  5. Probelauf schreibt nichts; ohne Tabelle (Deploy vor der Migration) scheitert kein Import.
 *  6. Dateityp-Erkennung für den Mehrfach-Upload.
 *
 * Aufruf: php tests/local/cost-line-history.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Livewire\Costs\Import;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostLineHistory;
use Platform\AssetManager\Services\CostLineHistoryService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;

// ---- Schema (wie vodafone-invoice-validity.php) --------------------------------------------
Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('code')->nullable(); $t->string('name'); $t->timestamps();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable(); $t->string('mobile_phone')->nullable(); $t->string('business_phone')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key'); $t->string('name');
    $t->integer('sort_order')->default(0); $t->string('allocation_level')->default('person'); $t->string('aggregation_source')->default('cost_line'); $t->timestamps();
});
Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('name'); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->unsignedBigInteger('vendor_id')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable(); $t->unsignedBigInteger('assignee_id')->nullable();
    $t->unsignedBigInteger('asset_item_id')->nullable(); $t->string('label'); $t->decimal('amount', 12, 2); $t->string('currency', 3)->default('EUR');
    $t->decimal('fx_rate', 12, 6)->nullable(); $t->string('frequency'); $t->decimal('monthly_amount', 12, 2)->default(0); $t->string('source')->default('manual');
    $t->string('period_label')->nullable(); $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable(); $t->boolean('active')->default(true);
    $t->string('import_batch_id')->nullable(); $t->string('import_hash', 64)->nullable(); $t->text('raw_data')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_invoice_snapshots', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('source', 32); $t->string('account', 64)->default('');
    $t->string('invoice', 64); $t->date('invoice_date')->nullable(); $t->date('booking_month')->nullable(); $t->string('period_label')->nullable();
    $t->decimal('total_net', 12, 2); $t->json('distribution'); $t->unsignedBigInteger('datev_batch_id')->nullable(); $t->timestamp('captured_at')->nullable();
    $t->timestamps(); $t->unique(['tenant_id', 'source', 'account', 'invoice']);
});

/** Importer, der statt einer Datei vorgegebene Zeilen liest (wie vodafone-invoice-validity.php). */
final class HistoryRowsImport extends VodafoneInvoiceImportService
{
    public function __construct(private array $rows) { parent::__construct(); }
    protected function readRows(string $path): array { return $this->rows; }
}

function bill(string $invoice, string $from, string $to, array $numbers): array
{
    $base = ['B' => '86839593', 'C' => $invoice, 'D' => $to, 'E' => $from, 'F' => $to];
    $rows = [['A' => 'Rechnung'] + $base + ['J' => array_sum($numbers)]];
    foreach ($numbers as $msisdn => $amount) {
        $rows[] = ['A' => 'Rufnummer'] + $base + ['J' => $amount, 'K' => (string) $msisdn, 'M' => ''];
    }

    return $rows;
}

const TEAM   = 1;
const TENANT = 1;
DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);
DB::table('asset_cost_types')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk']);
TenantContext::forceTenant(TENANT);

$import = fn (array $rows, bool $dry = false, bool $historyOnly = false) =>
    (new HistoryRowsImport($rows))->import(TEAM, 'egal', 'test', $dry, TENANT, historyOnly: $historyOnly);

// ---- 5b: ohne Tabelle (Deploy vor der Migration) scheitert kein Import ----------------------
CostLineHistoryService::forgetTableCheck();
$r = $import(bill('JUN', '15.05.2026', '14.06.2026', ['01733185736' => 21.0]));
check('ohne Historientabelle: Import läuft trotzdem', true, $r['reconciles']);

// Tabelle aus der echten Migration anlegen.
(require dirname(__DIR__, 2) . '/database/migrations/2026_10_20_000001_create_asset_cost_line_history_table.php')->up();
CostLineHistoryService::forgetTableCheck();

// ---- 1: scharfe Importe Juli–September ------------------------------------------------------
$import(bill('JUL', '15.06.2026', '14.07.2026', ['01733185736' => 21.0, '01638588153' => 46.0]));
$import(bill('AUG', '15.07.2026', '14.08.2026', ['01733185736' => 21.0, '01638588153' => 46.0]));
$import(bill('SEP', '15.08.2026', '14.09.2026', ['01733185736' => 4.0645, '01638588153' => 46.0]));
$line = AssetCostLine::where('label', 'like', '%01733185736%')->first();

check('drei Rechnungen → drei Einträge je Nummer', 3, AssetCostLineHistory::where('import_hash', $line->import_hash)->count());
check('Eintrag trägt die Kostenposition', [$line->id], AssetCostLineHistory::where('import_hash', $line->import_hash)->distinct()->pluck('cost_line_id')->all());
check('Leistungsmonat = Monat des Zeitraumendes', '2026-09-01', AssetCostLineHistory::where('invoice', 'SEP')->value('booking_month')?->toDateString());

$import(bill('SEP', '15.08.2026', '14.09.2026', ['01733185736' => 4.0645, '01638588153' => 46.0]));
check('dieselbe Rechnung erneut: kein zusätzlicher Eintrag', 3, AssetCostLineHistory::where('import_hash', $line->import_hash)->count());

// ---- 2: Regelpreis ---------------------------------------------------------------------------
$svc     = new CostLineHistoryService();
$summary = CostLineHistoryService::summarize($svc->forHashes(TENANT, [$line->import_hash], 24)[$line->import_hash]);
check('Regelpreis = häufigster Betrag', 21.0, $summary['regular']);
check('letzter Betrag', 4.06, $summary['latest']);
check('Abweichung erkannt', true, $summary['deviates']);
check('unter drei Monaten kein Regelpreis', null, CostLineHistoryService::summarize(array_slice(iterator_to_array($svc->forHashes(TENANT, [$line->import_hash], 24)[$line->import_hash]), 0, 2))['regular']);

// ---- 3: Historie nachtragen mit älterer Rechnung --------------------------------------------
$snapshotsBefore = DB::table('asset_invoice_snapshots')->count();
$r = $import(bill('MAI', '15.04.2026', '14.05.2026', ['01733185736' => 21.0, '01511111111' => 9.99]), historyOnly: true);
check('ältere Rechnung: als Historie erlaubt', [true, 2], [$r['history_only'] ?? false, $r['history_rows'] ?? 0]);
check('aktueller Betrag unverändert (4,06 €)', 4.06, (float) $line->fresh()->amount);
check('kein neuer Monatsstand', $snapshotsBefore, DB::table('asset_invoice_snapshots')->count());
check('keine neue Kostenposition für die alte Nummer', 0, AssetCostLine::where('label', 'like', '%01511111111%')->count());
check('Verlauf hängt an der heutigen Kostenposition', $line->id, AssetCostLineHistory::where('invoice', 'MAI')->where('import_hash', $line->import_hash)->value('cost_line_id'));

// ---- 4: Nummer, die es heute nicht mehr gibt -------------------------------------------------
$gone = AssetCostLineHistory::where('invoice', 'MAI')->where('import_hash', '!=', $line->import_hash)->first();
check('weggefallene Nummer: Verlauf ohne Kostenposition', [true, null], [$gone !== null, $gone?->cost_line_id]);

// ---- 5: Probelauf ----------------------------------------------------------------------------
$before = AssetCostLineHistory::count();
$r = $import(bill('OKT', '15.09.2026', '14.10.2026', ['01733185736' => 21.0, '01638588153' => 46.0]), dry: true);
check('Probelauf: nennt die Einträge, schreibt keine', [2, $before], [$r['history_rows'], AssetCostLineHistory::count()]);

// ---- 6: Dateityp-Erkennung -------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/am-hist-' . uniqid();
file_put_contents($tmp . '-m.csv', "Typ;Rechnungskonto;Rechnungs-Nummer\nRechnung;1;2\n");
file_put_contents($tmp . '-f.csv', "Dokument Nummer;Rechnungskonto;Kundennummer zur Rechnung\n");
file_put_contents($tmp . '-x.csv', "Dokument;Rechnungskonto;Rechnungsnummer\n");
check('Erkennung Mobilfunk-CSV', 'mobile', Import::historyFileType($tmp . '-m.csv', 'csv'));
check('Erkennung Festnetz-CSV', 'fixednet', Import::historyFileType($tmp . '-f.csv', 'csv'));
check('Erkennung xlsx = Mobilfunk', 'mobile', Import::historyFileType($tmp . '-m.csv', 'xlsx'));
check('Mobilfunk-„Rechnung.csv" wird abgelehnt', null, Import::historyFileType($tmp . '-x.csv', 'csv'));
array_map('unlink', glob($tmp . '-*'));

check_summary();
