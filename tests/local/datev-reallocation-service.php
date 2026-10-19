<?php

/**
 * DATEV-Umbuchung eines Monats aus den Monatsständen (Baustein 2, Discussion #26):
 * {@see \Platform\AssetManager\Services\DatevReallocationService}.
 *
 * Geprüft wird die Block-Bildung vor dem Rechner:
 *  1. Nur Monatsstände des Buchungsmonats, die noch nicht gebucht sind.
 *  2. Je Rechnung wird der Rechnungsbetrag nach den Anteilen ihrer Verteilung aufgeteilt (Largest
 *     Remainder) — auch Kostenarten ohne Sachkonto zählen dabei mit, werden aber nicht umgebucht.
 *  3. Ein Block je Sachkonto × Kreditor; Leistung = Kostenart mit dem größten Anteil.
 *  4. Befunde: Kostenart ohne Sachkonto, Monatsstand ohne Buchungsmonat, Anteil ohne KST (blockiert).
 *  5. Der Stapel geht durch den EXTF-Writer.
 *
 * Aufruf: php tests/local/datev-reallocation-service.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Services\DatevReallocationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\DatevExtfWriter;

// ---- Schema --------------------------------------------------------------------------------

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key'); $t->string('name');
    $t->string('aggregation_source')->default('cost_line'); $t->string('datev_account', 8)->nullable(); $t->unsignedBigInteger('vendor_default_id')->nullable(); $t->timestamps();
});
Schema::create('asset_invoice_snapshots', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id');
    $t->string('source', 32); $t->string('account', 64)->default(''); $t->string('invoice', 64);
    $t->date('invoice_date')->nullable(); $t->date('booking_month')->nullable(); $t->string('period_label')->nullable();
    $t->decimal('total_net', 12, 2); $t->json('distribution'); $t->unsignedBigInteger('datev_batch_id')->nullable();
    $t->timestamp('captured_at')->nullable(); $t->timestamps();
    $t->unique(['tenant_id', 'source', 'account', 'invoice']);
});

// ---- Ausgangsdaten -------------------------------------------------------------------------

const TEAM   = 1;
const TENANT = 1;

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$type = fn (string $key, string $name, ?string $account) => DB::table('asset_cost_types')->insertGetId(
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => $key, 'name' => $name, 'datev_account' => $account]);

$tMobil = $type('mobilfunk', 'Mobilfunk', '680510');
$tTel   = $type('telefonie', 'Telefonie', '680500');
$tRc    = $type('vf_lizenz_rc', 'VF Lizenz RC', '680500');
$tNet   = $type('internet', 'Internet', '681000');
$tOhne  = $type('sonstiges', 'Sonstiges', null);

/** Monatsstand direkt anlegen: Verteilung als [Kostenart-ID, KST-Code, Betrag]. */
function snapshot(string $source, string $invoice, ?string $month, float $total, array $rows, ?int $batch = null): AssetInvoiceSnapshot
{
    $keys = DB::table('asset_cost_types')->pluck('key', 'id');

    return AssetInvoiceSnapshot::withoutTenantScope()->create([
        'team_id' => TEAM, 'tenant_id' => TENANT, 'source' => $source, 'account' => 'K1', 'invoice' => $invoice,
        'booking_month' => $month, 'total_net' => $total, 'datev_batch_id' => $batch,
        'distribution' => array_map(fn ($r) => [
            'cost_type_id' => $r[0], 'cost_type_key' => $keys[$r[0]], 'cost_center_id' => null,
            'cost_center_code' => $r[1], 'amount' => $r[2], 'lines' => 1,
        ], $rows),
    ]);
}

/** Kurzform: „H 10002 1520 680510". */
function rows(array $bookings): array
{
    return array_map(fn ($b) => "{$b['side']} {$b['amount_cents']} {$b['cost_center']} {$b['account']}", $bookings);
}

// Mobilfunk: Rechnung 100,02 €, Verteilung kennt 100,00 € (Rundungs-Cent).
$mobil = snapshot('vodafone_invoice', 'M-SEP', '2026-09-01', 100.02, [[$tMobil, '1520', 30.0], [$tMobil, '8090', 70.0]]);
// Festnetz: drei Kostenarten mit Sachkonto + eine ohne.
$fest = snapshot('vodafone_fixednet', 'F-SEP', '2026-09-01', 318.0, [
    [$tNet, '1520', 152.0], [$tTel, '1520', 92.0], [$tRc, '2599', 64.0], [$tOhne, '1520', 10.0],
]);
// Separate Rechnung komplett auf einer KST (Internet Mönchengladbach) — bucht die Buchhaltung direkt.
snapshot('vodafone_fixednet', 'F-GL', '2026-09-01', 369.0, [[$tNet, '2890', 369.0], [$tNet, '1520', 0.0]]);
snapshot('vodafone_invoice', 'M-OKT', '2026-10-01', 50.0, [[$tMobil, '1520', 30.0], [$tMobil, '2000', 20.0]]); // anderer Monat
$booked = snapshot('vodafone_invoice', 'M-SEP-ALT', '2026-09-01', 20.0, [[$tMobil, '1520', 20.0]], batch: 3); // gebucht
snapshot('vodafone_invoice', 'M-OHNE', null, 5.0, [[$tMobil, '1520', 5.0]]);                    // ohne Monat

$service = new DatevReallocationService();
$sep     = new DateTimeImmutable('2026-09-15');
$r       = $service->prepare(TEAM, TENANT, $sep, client: '530');

// ---- 1: Auswahl ----------------------------------------------------------------------------

check('Monat normiert', '2026-09', $r['month']);
check('Nur ungebuchte Stände des Monats', ['F-SEP', 'M-SEP'], array_column($r['snapshots'], 'invoice'));
check('Gebuchte Stände werden gelistet, nicht verteilt', ['M-SEP-ALT'], array_column($r['booked'], 'invoice'));
check('Rechnung auf genau einer KST: direkt gebucht, nicht im Stapel', [['F-GL', '2890']],
    array_map(fn ($d) => [$d['invoice'], $d['cost_center']], $r['direct']));

// ---- 2 + 3: Rechnung × Anteil, Blöcke je Sachkonto × Kreditor ------------------------------

check('Blöcke nach Sachkonto', ['680500', '680510', '681000'], array_column($r['blocks'], 'account'));
check('Leistung = Kreditor + größte Kostenart', ['Vodafone Telefonie', 'Vodafone Mobilfunk', 'Vodafone Internet'], array_column($r['blocks'], 'service'));
check('Mobilfunk: Rechnungsbetrag statt Verteilungssumme', 10002, $r['blocks'][1]['amount_cents']);
check('Mobilfunk: Rest-Cent per Largest Remainder', ['1520' => 3001, '8090' => 7001], $r['blocks'][1]['shares']);
check('Telefonie + RC-Lizenzen in einem Block (680500)', ['1520' => 9200, '2599' => 6400], $r['blocks'][0]['shares']);
check('Kostenart ohne Sachkonto: nicht umgebucht', [['cost_type_key' => 'sonstiges', 'invoice' => 'F-SEP', 'amount_cents' => 1000]], $r['skipped']);

check('Buchungen: Quelle H, Ziele S je Block', [
    'H 15600 1520 680500', 'S 9200 1520 680500', 'S 6400 2599 680500',
    'H 10002 1520 680510', 'S 3001 1520 680510', 'S 7001 8090 680510',
    'H 15200 1520 681000', 'S 15200 1520 681000',
], rows($r['bookings']));
check('Text Stil C', 'Umb. [1520|diverse] Vodafone Mobilfunk Sep26', $r['bookings'][3]['text']);

// ---- 4: Befunde ----------------------------------------------------------------------------

// Übersprungener Betrag blockiert (#949): nach dem Stapel wäre er nie mehr buchbar.
check('Einziger Verstoß: Kostenart ohne Sachkonto', true, count($r['violations']) === 1 && str_contains($r['violations'][0], 'Sonstiges') && str_contains($r['violations'][0], 'Sachkonto'));
check('Befund: Kostenart ohne Sachkonto', true, (bool) array_filter($r['findings'], fn ($f) => str_contains($f, 'Sonstiges') && str_contains($f, '10,00')));
check('Befund: Monatsstand ohne Buchungsmonat', true, (bool) array_filter($r['findings'], fn ($f) => str_contains($f, '1 Rechnung') && str_contains($f, 'ohne Buchungsmonat')));

snapshot('vodafone_invoice', 'M-SEP-2', '2026-09-01', 10.0, [[$tMobil, null, 10.0]]);
$r2 = $service->prepare(TEAM, TENANT, $sep, client: '530');
check('Anteil ohne KST blockiert', true, (bool) array_filter($r2['violations'], fn ($v) => str_contains($v, 'ohne Kostenstelle')));
AssetInvoiceSnapshot::withoutTenantScope()->where('invoice', 'M-SEP-2')->delete();

$r3 = $service->prepare(TEAM, TENANT, $sep, client: '530', knownCostCenters: ['1520', '2599']);
check('DATEV-KST-Liste wird durchgereicht', true, (bool) array_filter($r3['violations'], fn ($v) => str_contains($v, '8090')));

$oct = $service->prepare(TEAM, TENANT, new DateTimeImmutable('2026-10-01'), client: '530');
check('Oktober: nur der Oktober-Stand', ['H 5000 1520 680510', 'S 3000 1520 680510', 'S 2000 2000 680510'], rows($oct['bookings']));

// Umstellung ab Oktober: der September ist vorverteilt — keine Blöcke, nichts offen.
$pre = $service->prepare(TEAM, TENANT, $sep, client: '530', startMonth: new DateTimeImmutable('2026-10-01'));
check('Vor der Umstellung: vorverteilt, kein Stapel', [true, [], [], []],
    [$pre['before_start'], $pre['bookings'], $pre['snapshots'], $pre['direct']]);
check('Vor der Umstellung: alle ungebuchten Stände gelistet', ['F-GL', 'F-SEP', 'M-SEP'], array_column($pre['predistributed'], 'invoice'));
$octStart = $service->prepare(TEAM, TENANT, new DateTimeImmutable('2026-10-01'), client: '530', startMonth: new DateTimeImmutable('2026-10-01'));
check('Ab dem Umstellungsmonat: normal umgebucht', [false, 3], [$octStart['before_start'], count($octStart['bookings'])]);

$nov = $service->prepare(TEAM, TENANT, new DateTimeImmutable('2026-11-01'), client: '530');
check('Leerer Monat: keine Blöcke, keine Buchungen', [[], []], [$nov['blocks'], $nov['bookings']]);

// ---- 5: der Stapel geht durch den Writer ---------------------------------------------------

$meta = [
    'consultant' => '189671', 'client' => '530', 'fiscal_year_start' => new DateTimeImmutable('2026-01-01'),
    'account_length' => 6, 'period_from' => new DateTimeImmutable('2026-09-01'), 'period_to' => new DateTimeImmutable('2026-09-30'),
    'label' => 'CTRL Umb IT Sep 2026', 'initials' => 'KI', 'created_at' => new DateTimeImmutable('2026-10-15 12:00'),
];
check('Writer nimmt den September-Stapel an', [], DatevExtfWriter::fromTemplate()->violations($meta, $r['bookings']));


// ---- #950: Verteilung weicht vom Rechnungsnetto ab -----------------------------------------
snapshot('vodafone_invoice', 'M-SEP-GAP', '2026-09-01', 60.0, [[$tMobil, '1520', 10.0], [$tMobil, '2599', 40.0]]);
$rGap = $service->prepare(TEAM, TENANT, $sep, client: '530');
check('Lücke ≥ 1 € blockiert (#950)', true, (bool) array_filter($rGap['violations'], fn ($v) => str_contains($v, 'M-SEP-GAP') && str_contains($v, '10,00')));
AssetInvoiceSnapshot::withoutTenantScope()->where('invoice', 'M-SEP-GAP')->delete();

snapshot('vodafone_invoice', 'M-SEP-RND', '2026-09-01', 50.02, [[$tMobil, '1520', 10.0], [$tMobil, '2599', 40.0]]);
$rRnd = $service->prepare(TEAM, TENANT, $sep, client: '530');
check('Rundung < 1 € blockiert nicht', false, (bool) array_filter($rRnd['violations'], fn ($v) => str_contains($v, 'M-SEP-RND')));
AssetInvoiceSnapshot::withoutTenantScope()->where('invoice', 'M-SEP-RND')->delete();

check_summary();
