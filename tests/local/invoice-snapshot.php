<?php

/**
 * Monatsstand je Rechnung (Discussion #26): der scharfe Rechnungsimport schreibt Rechnungsbetrag,
 * Buchungsmonat und Verteilung auf Kostenart × KST fest ({@see \Platform\AssetManager\Services\InvoiceSnapshotService}).
 *
 * Durchgespielt am Mobilfunk-Import, weil der den einfachsten Rechnungsbezug hat:
 *  1. Probelauf schreibt nichts, scharfer Lauf genau einen Stand.
 *  2. Buchungsmonat = Leistungsmonat (Ende des Abrechnungszeitraums), nicht das Rechnungsdatum;
 *     Verteilung je KST mit Code.
 *  3. Derselbe Beleg erneut importiert ersetzt den Stand, legt keinen zweiten an.
 *  4. Die nächste Rechnung legt einen neuen Stand an — der alte bleibt (Historie).
 *  5. Nachträglich gesetzte KST: Neu-Festschreiben geht nur für den aktuellen Stand des Kontos.
 *  6. Ein gebuchter Stand bleibt unverändert, der Import meldet es.
 *  7. Ohne Leistungszeitraum kein Buchungsmonat, dafür ein Befund.
 *  8. Nach Rundungsausgleich ist die Verteilung = Rechnungsnetto.
 *
 * Aufruf: php tests/local/invoice-snapshot.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Services\InvoiceSnapshotService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;

// ---- Schema --------------------------------------------------------------------------------

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('code')->nullable(); $t->string('name'); $t->timestamps();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->string('display_name')->nullable();
    $t->string('mobile_phone')->nullable(); $t->string('business_phone')->nullable();
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
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable(); $t->unsignedBigInteger('vendor_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable(); $t->unsignedBigInteger('assignee_id')->nullable(); $t->unsignedBigInteger('asset_item_id')->nullable();
    $t->string('label'); $t->decimal('amount', 12, 2); $t->string('currency', 3)->default('EUR'); $t->decimal('fx_rate', 12, 6)->nullable();
    $t->string('frequency'); $t->decimal('monthly_amount', 12, 2)->default(0); $t->string('source')->default('manual');
    $t->string('period_label')->nullable(); $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable();
    $t->boolean('active')->default(true); $t->string('import_batch_id')->nullable(); $t->string('import_hash', 64)->nullable();
    $t->text('raw_data')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_invoice_snapshots', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id');
    $t->string('source', 32); $t->string('account', 64)->default(''); $t->string('invoice', 64);
    $t->date('invoice_date')->nullable(); $t->date('booking_month')->nullable(); $t->string('period_label')->nullable();
    $t->decimal('total_net', 12, 2); $t->json('distribution'); $t->unsignedBigInteger('datev_batch_id')->nullable();
    $t->timestamp('captured_at')->nullable(); $t->timestamps();
    $t->unique(['tenant_id', 'source', 'account', 'invoice']);
});

// ---- Testrahmen ----------------------------------------------------------------------------

final class RowsImport extends VodafoneInvoiceImportService
{
    public function __construct(private array $rows)
    {
        parent::__construct();
    }

    protected function readRows(string $path): array
    {
        return $this->rows;
    }
}

/** Rechnung im Spaltenschema des Exports; Kontoposten auf KST 1520. */
function bill(string $invoice, ?string $date, array $numbers, array $items = [], ?float $header = null, ?array $period = ['15.08.2026', '14.09.2026']): array
{
    $base = ['B' => '86839593', 'C' => $invoice, 'D' => $date ?? '', 'E' => $period[0] ?? '', 'F' => $period[1] ?? ''];
    $rows = [['A' => 'Rechnung'] + $base + ['J' => $header ?? round(array_sum($numbers) + array_sum($items), 2)]];
    foreach ($numbers as $msisdn => $amount) {
        $rows[] = ['A' => 'Rufnummer'] + $base + ['J' => $amount, 'K' => (string) $msisdn, 'M' => '1520 - IT'];
    }
    foreach ($items as $label => $amount) {
        $rows[] = ['A' => 'Rechnungsposten'] + $base + ['J' => $amount, 'L' => $label, 'M' => '1520 - IT'];
    }

    return $rows;
}

function import(array $rows, bool $dryRun = false): array
{
    return (new RowsImport($rows))->import(TEAM, 'egal', 'test', $dryRun, TENANT);
}

function snap(string $invoice): ?AssetInvoiceSnapshot
{
    return AssetInvoiceSnapshot::withoutTenantScope()->where('invoice', $invoice)->first();
}

/** Verteilung kompakt: „mobilfunk 1520 21.00 (3)". */
function dist(?AssetInvoiceSnapshot $s): array
{
    return array_map(fn ($r) => sprintf('%s %s %.2f (%d)', $r['cost_type_key'], $r['cost_center_code'] ?? '—', $r['amount'], $r['lines']), (array) $s?->distribution);
}

// ---- Ausgangsdaten -------------------------------------------------------------------------

const TEAM   = 1;
const TENANT = 1;

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$cc8090 = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '8090', 'name' => 'Kita']);
$cc1520 = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1520', 'name' => 'IT']);
$cc2000 = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2000', 'name' => 'Event']);
DB::table('asset_cost_types')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk']);
$holder = DB::table('asset_holders')->insertGetId([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'display_name' => 'Test Person', 'mobile_phone' => '+49 163 8588153', 'cost_center_id' => $cc8090,
]);

$sep = bill('SEP-1', '18.09.2026', ['01638588153' => 46.0, '015202316833' => 21.0], ['Vodafone-CorporateVPN' => 50.0, 'Rabatt OfficeVPN' => -50.0]);

// ---- 1 + 2: Probelauf / scharfer Lauf ------------------------------------------------------

$r = import($sep, dryRun: true);
check('Probelauf: kein Monatsstand', 0, AssetInvoiceSnapshot::withoutTenantScope()->count());
check('Probelauf: Ergebnis zeigt den Monat trotzdem', '2026-09', $r['snapshot']['month'] ?? null);

$r = import($sep);
$s = snap('SEP-1');
check('Scharf: genau ein Monatsstand', 1, AssetInvoiceSnapshot::withoutTenantScope()->count());
check('Buchungsmonat = Leistungsmonat (Zeitraum endet 14.09.)', '2026-09-01', $s?->booking_month?->toDateString());
check('Rechnungsdatum festgehalten', '2026-09-18', $s?->invoice_date?->toDateString());
check('Rechnungskonto + Quelle', ['86839593', 'vodafone_invoice'], [$s?->account, $s?->source]);
check('Rechnungsnetto', 67.0, (float) $s?->total_net);
check('Verteilung je Kostenart × KST', ['mobilfunk 1520 21.00 (3)', 'mobilfunk 8090 46.00 (1)'], dist($s));
check('Ergebnis: Monat + Zeilen', ['2026-09', 2, []], [$r['snapshot']['month'] ?? null, $r['snapshot']['rows'] ?? null, $r['snapshot']['findings'] ?? null]);

// ---- 3: derselbe Beleg erneut — ersetzt, kein zweiter Stand --------------------------------

DB::table('asset_holders')->where('id', $holder)->update(['cost_center_id' => $cc2000]);
import($sep);
check('Erneuter Import: weiterhin ein Stand', 1, AssetInvoiceSnapshot::withoutTenantScope()->count());
check('Erneuter Import: neue KST übernommen', ['mobilfunk 1520 21.00 (3)', 'mobilfunk 2000 46.00 (1)'], dist(snap('SEP-1')));

// ---- 4: nächste Rechnung — neuer Stand, alter bleibt ---------------------------------------

$oct = bill('OKT-1', '19.10.2026', ['01638588153' => 48.5], ['Vodafone-CorporateVPN' => 50.0, 'Rabatt OfficeVPN' => -50.0], period: ['15.09.2026', '14.10.2026']);
import($oct);
check('Oktober: zweiter Stand', 2, AssetInvoiceSnapshot::withoutTenantScope()->count());
check('Oktober: Buchungsmonat', '2026-10-01', snap('OKT-1')?->booking_month?->toDateString());
check('September bleibt wie gebucht (Historie)', ['mobilfunk 1520 21.00 (3)', 'mobilfunk 2000 46.00 (1)'], dist(snap('SEP-1')));

// ---- 5: Neu festschreiben nach KST-Korrektur ----------------------------------------------

$service = new InvoiceSnapshotService();
DB::table('asset_cost_lines')->where('assignee_id', $holder)->update(['cost_center_id' => $cc8090]);

$re = $service->recapture(snap('OKT-1'));
check('Neu festschreiben: aktueller Stand übernimmt die KST', ['mobilfunk 1520 0.00 (2)', 'mobilfunk 8090 48.50 (1)'], dist(snap('OKT-1')));
check('Neu festschreiben: ohne Befund', [], $re['findings']);

$re = $service->recapture(snap('SEP-1'));
check('Alte Rechnung: nicht neu festschreibbar', true, str_contains($re['findings'][0] ?? '', 'nicht mehr der aktuelle Stand'));
check('Alte Rechnung: Stand unverändert', ['mobilfunk 1520 21.00 (3)', 'mobilfunk 2000 46.00 (1)'], dist(snap('SEP-1')));

// ---- 6: gebuchter Stand bleibt -------------------------------------------------------------

snap('OKT-1')->update(['datev_batch_id' => 7]);
$r = import(bill('OKT-1', '19.10.2026', ['01638588153' => 99.0], period: ['15.09.2026', '14.10.2026']));
check('Gebucht: Stand unverändert', 48.5, (float) snap('OKT-1')->total_net);
check('Gebucht: Import meldet es', true, str_contains($r['snapshot']['findings'][0] ?? '', 'bereits per DATEV umgebucht'));

// ---- 7: ohne Leistungszeitraum -------------------------------------------------------------

$r = import(bill('NOV-1', '18.11.2026', ['01638588153' => 40.0], period: null));
check('Ohne Leistungszeitraum: Stand ohne Buchungsmonat', null, snap('NOV-1')?->booking_month);
check('Ohne Leistungszeitraum: Befund', true, str_contains($r['snapshot']['findings'][0] ?? '', 'ohne Leistungszeitraum'));

// ---- 8: Rundungsausgleich → Verteilung = Rechnungsnetto ------------------------------------

// Rechnung erst im Januar gestellt — gebucht wird trotzdem im Leistungsmonat Dezember.
import(bill('DEZ-1', '05.01.2027', ['01638588153' => 10.0049, '015202316833' => 10.0049], ['Vodafone-CorporateVPN' => 5.0049], header: 25.02, period: ['15.11.2026', '14.12.2026']));
$s = snap('DEZ-1');
check('Rechnung im Folgemonat: Buchungsmonat bleibt der Leistungsmonat', '2026-12-01', $s?->booking_month?->toDateString());
check('Rundung: Rechnungsnetto 25,02', 25.02, (float) $s?->total_net);
check('Rundung: Verteilung summiert auf Rechnungsnetto', 25.02, round(array_sum(array_column((array) $s?->distribution, 'amount')), 2));

check_summary();
