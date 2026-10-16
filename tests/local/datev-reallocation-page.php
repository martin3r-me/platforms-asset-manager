<?php

/**
 * Seite „DATEV-Umbuchung" (Baustein 5, docs/adr/0027): {@see \Platform\AssetManager\Livewire\Costs\DatevReallocation}.
 *
 * Ohne Livewire-Runtime: die Komponente wird direkt instanziiert (Team/Tenant/Rechte überschrieben).
 *  1. Startmonat = jüngster Monat mit offenen Rechnungen; Vorschau rechnet.
 *  2. Unvollständiger Stapelkopf: Vorschau ja, Erzeugen nein.
 *  3. Erzeugen → Stapel, Rechnung gebucht, Download liefert die Datei byte-gleich.
 *  4. Verwerfen braucht einen Grund und gibt die Rechnung frei.
 *
 * Aufruf: php tests/local/datev-reallocation-page.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetDatevBatch;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Services\DatevBatchService;
use Platform\AssetManager\Services\DatevReallocationService;
use Platform\AssetManager\Services\TenantContext;

// ---- Schema --------------------------------------------------------------------------------

Schema::create('users', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->timestamps();
});
Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('code')->nullable(); $t->string('name'); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key'); $t->string('name');
    $t->string('aggregation_source')->default('cost_line'); $t->string('datev_account', 8)->nullable(); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(true);
    $t->string('datev_consultant', 7)->nullable(); $t->string('datev_client', 5)->nullable(); $t->string('datev_fiscal_year_start', 5)->nullable();
    $t->unsignedTinyInteger('datev_account_length')->nullable(); $t->string('datev_initials', 2)->nullable();
    $t->unsignedBigInteger('datev_source_cost_center_id')->nullable(); $t->date('datev_start_month')->nullable(); $t->timestamps();
});
Schema::create('asset_invoice_snapshots', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id');
    $t->string('source', 32); $t->string('account', 64)->default(''); $t->string('invoice', 64);
    $t->date('invoice_date')->nullable(); $t->date('booking_month')->nullable(); $t->string('period_label')->nullable();
    $t->decimal('total_net', 12, 2); $t->json('distribution'); $t->unsignedBigInteger('datev_batch_id')->nullable();
    $t->timestamp('captured_at')->nullable(); $t->timestamps();
});
Schema::create('asset_datev_batches', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id');
    $t->date('booking_month'); $t->string('client', 5); $t->string('consultant', 7); $t->string('label', 30);
    $t->string('file_name'); $t->longText('file_base64'); $t->string('file_sha256', 64);
    $t->unsignedInteger('bookings_count'); $t->bigInteger('total_cents'); $t->json('summary');
    $t->unsignedBigInteger('user_id')->nullable(); $t->timestamp('voided_at')->nullable();
    $t->unsignedBigInteger('voided_by')->nullable(); $t->string('void_reason')->nullable(); $t->timestamps();
});

// ---- Ausgangsdaten -------------------------------------------------------------------------

const TEAM   = 1;
const TENANT = 1;

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'BHG', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$cc1520 = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1520', 'name' => 'IT']);
$tMobil = DB::table('asset_cost_types')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'datev_account' => '680510']);
DB::table('asset_team_settings')->insert([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'datev_consultant' => '189671', 'datev_client' => '530',
    'datev_fiscal_year_start' => '01-01', 'datev_account_length' => 6, 'datev_initials' => null, 'datev_source_cost_center_id' => $cc1520,
]);

$snap = fn (string $invoice, string $month) => AssetInvoiceSnapshot::withoutTenantScope()->create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'source' => 'vodafone_invoice', 'account' => 'K1', 'invoice' => $invoice,
    'booking_month' => $month, 'total_net' => 100.0,
    'distribution' => [
        ['cost_type_id' => $tMobil, 'cost_type_key' => 'mobilfunk', 'cost_center_id' => null, 'cost_center_code' => '1520', 'amount' => 40.0, 'lines' => 1],
        ['cost_type_id' => $tMobil, 'cost_type_key' => 'mobilfunk', 'cost_center_id' => null, 'cost_center_code' => '8090', 'amount' => 60.0, 'lines' => 1],
    ],
]);
$sep = $snap('M-SEP', '2026-09-01');
$snap('M-AUG', '2026-08-01');

$make = fn () => new class extends Platform\AssetManager\Livewire\Costs\DatevReallocation {
    public function teamId(): int { return TEAM; }
    protected function tenantId(): int { return TENANT; }
    protected function canManage(): bool { return true; }
};

/** Daten, die render() an die View gibt. */
function viewData($c): array
{
    return $c->render(app(DatevReallocationService::class))->getData();
}

// ---- 1: Startmonat und Vorschau ------------------------------------------------------------

$c = $make();
$c->mount();
check('Startmonat = jüngster Monat mit offenen Rechnungen', '2026-09', $c->month);

$data = viewData($c);
check('Vorschau: ein Block, 3 Buchungszeilen', [1, 3], [count($data['plan']['summary']), count($data['plan']['bookings'])]);
check('Offene Monate', ['2026-09' => 1, '2026-08' => 1], $data['openMonths']->all());
check('Rechnungen des Monats', ['M-SEP'], $data['snapshots']->pluck('invoice')->all());

// ---- 2: Stapelkopf unvollständig (Diktatkürzel fehlt) --------------------------------------

check('Kopf unvollständig: Kürzel fehlt', ['Diktatkürzel'], $data['missing']);
check('Kopf unvollständig: Erzeugen gesperrt', false, $data['canCreate']);
$c->createBatch(app(DatevBatchService::class));
check('Erzeugen trotzdem aufgerufen: Meldung, kein Stapel', [true, 0],
    [str_contains($c->problems[0] ?? '', 'Diktatkürzel'), AssetDatevBatch::withoutTenantScope()->count()]);

// ---- 3: Erzeugen und Download --------------------------------------------------------------

DB::table('asset_team_settings')->update(['datev_initials' => 'MW']);
$c = $make();
$c->month = '2026-09';
check('Kopf vollständig: Erzeugen möglich', true, viewData($c)['canCreate']);

$c->createBatch(app(DatevBatchService::class));
$batch = AssetDatevBatch::withoutTenantScope()->first();
check('Stapel erzeugt, Meldung', [true, []], [$batch !== null && str_contains((string) $c->flash, $batch->label), $c->problems]);
check('Rechnung gebucht', $batch?->id, $sep->fresh()->datev_batch_id);
check('Vorschau danach: nichts mehr offen', [[], false], [viewData($c)['plan']['bookings'], viewData($c)['canCreate']]);

$response = $c->download($batch->id);
ob_start();
$response->sendContent();
$bytes = ob_get_clean();
check('Download: Datei byte-gleich', $batch->contents(), $bytes);
check('Download: Dateiname + Zeichensatz', [true, 'text/csv; charset=windows-1252'],
    [str_contains((string) $response->headers->get('Content-Disposition'), $batch->file_name), $response->headers->get('Content-Type')]);

// ---- 4: Verwerfen --------------------------------------------------------------------------

$c->startVoid($batch->id);
$c->voidReason = '   ';
$c->confirmVoid(app(DatevBatchService::class));
check('Verwerfen ohne Grund: Meldung, nicht verworfen', [true, null], [$c->problems !== [], $batch->fresh()->voided_at]);

$c->voidReason = 'Testlauf';
$c->confirmVoid(app(DatevBatchService::class));
check('Verwerfen mit Grund', [true, null, null], [$batch->fresh()->voided_at !== null, $sep->fresh()->datev_batch_id, $c->voidingId]);
check('Nach Verwerfen wieder erzeugbar', true, viewData($c)['canCreate']);

// ---- 5: separate Rechnung auf einer KST — direkt gebucht, nie „offen" ----------------------

$gl = AssetInvoiceSnapshot::withoutTenantScope()->create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'source' => 'vodafone_fixednet', 'account' => '1056352491', 'invoice' => 'F-GL',
    'booking_month' => '2026-10-01', 'total_net' => 369.0,
    'distribution' => [['cost_type_id' => $tMobil, 'cost_type_key' => 'mobilfunk', 'cost_center_id' => null, 'cost_center_code' => '2890', 'amount' => 369.0, 'lines' => 1]],
]);
$c->month = '2026-10';
$data = viewData($c);
check('Direkt gebucht: Status mit Ziel-KST', '2890', $data['directIds'][$gl->id] ?? null);
check('Direkt gebucht: kein offener Monat, kein Stapel', [false, [], false],
    [$data['openMonths']->has('2026-10'), $data['plan']['bookings'], $data['canCreate']]);

// ---- 6: Zurücksetzen -----------------------------------------------------------------------

$snaps = app(Platform\AssetManager\Services\InvoiceSnapshotService::class);
$c->resetSnapshot($gl->id, $snaps);
check('Zurücksetzen: Stand weg, Meldung', [null, true], [AssetInvoiceSnapshot::withoutTenantScope()->find($gl->id), str_contains((string) $c->flash, 'F-GL')]);

// September-Stand in einen neuen Stapel, dann ist er gesperrt.
$c->month = '2026-09';
$c->createBatch(app(DatevBatchService::class));
$c->resetSnapshot($sep->id, $snaps);
check('Zurücksetzen gebuchter Stand: Meldung, Stand bleibt', [true, true], [str_contains($c->problems[0] ?? '', 'Stapel'), $sep->fresh() !== null]);

$c->month = '2026-08';
$c->resetMonth($snaps);
check('Monat zurücksetzen: August leer', [0, true],
    [AssetInvoiceSnapshot::withoutTenantScope()->whereDate('booking_month', '2026-08-01')->count(), str_contains((string) $c->flash, '1 offene')]);

// ---- 7: Umstellungsmonat -------------------------------------------------------------------

$snap('M-OKT', '2026-10-01');
$snap('M-NOV', '2026-11-01');
DB::table('asset_team_settings')->update(['datev_start_month' => '2026-11-01']);
$c = $make();
$c->month = '2026-10';
$data = viewData($c);
check('Vor der Umstellung: Hinweis, vorverteilt, kein Stapel', [true, true, false],
    [$data['plan']['before_start'], $data['predistributedIds']->has(AssetInvoiceSnapshot::withoutTenantScope()->where('invoice', 'M-OKT')->value('id')), $data['canCreate']]);
check('Vor der Umstellung: Oktober nicht offen, November schon', [false, true], [$data['openMonths']->has('2026-10'), $data['openMonths']->has('2026-11')]);

$c2 = $make();
$c2->mount();
check('Startmonat überspringt vorverteilte Monate', '2026-11', $c2->month);

check_summary();
