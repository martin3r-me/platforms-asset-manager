<?php

/**
 * DATEV-Stapel-Protokoll (Baustein 4, Discussion #26): {@see \Platform\AssetManager\Services\DatevBatchService}.
 *
 * Ein Stapel ist die erzeugte EXTF-Datei plus die Liste der Monatsstände, die darin umgebucht sind.
 * Geprüft wird der Schutz gegen Doppel-Umbuchung:
 *  1. Erzeugen sichert die Datei (Bytes unverändert, Prüfsumme) und sperrt die Monatsstände.
 *  2. Ein zweiter Stapel desselben Monats nimmt nur, was noch offen ist — sonst gibt es keinen.
 *  3. Verstöße (Rechner oder Writer) erzeugen keinen Stapel und sperren nichts.
 *  4. Verwerfen braucht einen Grund und gibt die Monatsstände wieder frei.
 *
 * Aufruf: php tests/local/datev-batch.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetDatevBatch;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Services\DatevBatchService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\DatevExtfException;

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
const USER   = 22;

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$tMobil = DB::table('asset_cost_types')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'datev_account' => '680510']);

function snapshot(string $invoice, string $month, float $total, array $shares): AssetInvoiceSnapshot
{
    return AssetInvoiceSnapshot::withoutTenantScope()->create([
        'team_id' => TEAM, 'tenant_id' => TENANT, 'source' => 'vodafone_invoice', 'account' => 'K1', 'invoice' => $invoice,
        'booking_month' => $month, 'total_net' => $total,
        'distribution' => array_map(fn ($code, $amount) => [
            'cost_type_id' => $GLOBALS['tMobil'], 'cost_type_key' => 'mobilfunk', 'cost_center_id' => null,
            'cost_center_code' => $code === '' ? null : (string) $code, 'amount' => $amount, 'lines' => 1,
        ], array_keys($shares), $shares),
    ]);
}

function meta(array $over = []): array
{
    return $over + [
        'consultant' => '189671', 'client' => '530', 'fiscal_year_start' => new DateTimeImmutable('2026-01-01'),
        'account_length' => 6, 'initials' => 'KI',
    ];
}

/** Ruft $fn auf und liefert die Exception-Klasse + Nachricht, oder null. */
function fails(callable $fn): ?array
{
    try {
        $fn();

        return null;
    } catch (\Throwable $e) {
        return [get_class($e), $e->getMessage()];
    }
}

$sepA = snapshot('M-SEP', '2026-09-01', 100.0, ['1520' => 40.0, '8090' => 60.0]);
$octA = snapshot('M-OKT', '2026-10-01', 50.0, ['1520' => 30.0, '2000' => 20.0]);

$service = new DatevBatchService();
$sep     = new DateTimeImmutable('2026-09-01');

// ---- 1: Stapel erzeugen --------------------------------------------------------------------

$batch = $service->create(TEAM, TENANT, $sep, meta(), USER);
$file  = $batch->contents();

check('Stapel angelegt', 1, AssetDatevBatch::withoutTenantScope()->count());
check('Kopf: Monat, Mandant, Bezeichnung', ['2026-09-01', '530', 'CTRL Umb IT Sep 2026'], [$batch->booking_month?->toDateString(), $batch->client, $batch->label]);
check('Dateiname', 'EXTF_CTRL_Umb_IT_Sep_2026.csv', $batch->file_name);
check('Datei beginnt mit dem EXTF-Vorlauf', true, str_starts_with($file, '"EXTF";510;21;Buchungsstapel;7;'));
check('Datei: Kopf + Kopfzeile + 3 Buchungen, CRLF', 5, substr_count($file, "\r\n"));
check('Prüfsumme über die Bytes', hash('sha256', $file), $batch->file_sha256);
check('Kennzahlen: Buchungen, Summe der Quellzeilen', [3, 10000], [$batch->bookings_count, $batch->total_cents]);
check('Benutzer festgehalten', USER, $batch->user_id);
check('Monatsstand September gesperrt', $batch->id, $sepA->fresh()->datev_batch_id);
check('Oktober bleibt offen', null, $octA->fresh()->datev_batch_id);
check('Protokoll nennt die Rechnung', ['M-SEP'], array_column($batch->summary['snapshots'] ?? [], 'invoice'));

// ---- 2: zweiter Stapel desselben Monats ----------------------------------------------------

$err = fails(fn () => $service->create(TEAM, TENANT, $sep, meta(), USER));
check('Nichts offen: kein zweiter Stapel', true, $err !== null && str_contains($err[1], 'keine offenen Monatsstände'));
check('… und keine zweite Zeile', 1, AssetDatevBatch::withoutTenantScope()->count());

$sepB  = snapshot('M-SEP-NACH', '2026-09-01', 20.0, ['2000' => 12.0, '2500' => 8.0]);
$late  = $service->create(TEAM, TENANT, $sep, meta(), USER);
check('Nachzügler: eigener Stapel, Teil 2', 'CTRL Umb IT Sep 2026 Teil 2', $late->label);
check('Nachzügler: nur die neue Rechnung', ['M-SEP-NACH'], array_column($late->summary['snapshots'], 'invoice'));
check('Nachzügler: gesperrt auf Teil 2', $late->id, $sepB->fresh()->datev_batch_id);

// ---- 3: Verstöße erzeugen nichts -----------------------------------------------------------

$bad = snapshot('M-OKT-OHNE', '2026-10-01', 10.0, ['' => 10.0]);
$err = fails(fn () => $service->create(TEAM, TENANT, new DateTimeImmutable('2026-10-01'), meta(), USER));
check('Anteil ohne KST: Verstoß aus dem Rechner', DatevExtfException::class, $err[0] ?? null);
check('… kein Stapel, nichts gesperrt', [2, null, null], [AssetDatevBatch::withoutTenantScope()->count(), $octA->fresh()->datev_batch_id, $bad->fresh()->datev_batch_id]);
$bad->delete();

$err = fails(fn () => $service->create(TEAM, TENANT, new DateTimeImmutable('2026-10-01'), meta(['client' => 'abc']), USER));
check('Writer-Verstoß (Mandant): kein Stapel', [DatevExtfException::class, 2], [$err[0] ?? null, AssetDatevBatch::withoutTenantScope()->count()]);
check('Writer-Verstoß: Oktober bleibt offen (Rollback)', null, $octA->fresh()->datev_batch_id);

// ---- 4: Verwerfen --------------------------------------------------------------------------

$err = fails(fn () => $service->void($batch, USER, '  '));
check('Verwerfen ohne Grund: abgelehnt', true, $err !== null && $batch->fresh()->voided_at === null);

$service->void($batch, USER, 'Import in DATEV abgebrochen');
check('Verworfen: Zeitpunkt, Benutzer, Grund', [true, USER, 'Import in DATEV abgebrochen'], [$batch->fresh()->voided_at !== null, $batch->fresh()->voided_by, $batch->fresh()->void_reason]);
check('Verworfen: Monatsstand wieder offen', null, $sepA->fresh()->datev_batch_id);
check('Verworfen: Teil 2 bleibt gesperrt', $late->id, $sepB->fresh()->datev_batch_id);
check('Verworfen: Datei bleibt zur Nachvollziehbarkeit', $file, $batch->fresh()->contents());

$err = fails(fn () => $service->void($batch->fresh(), USER, 'nochmal'));
check('Zweimal verwerfen: abgelehnt', true, $err !== null);

$again = $service->create(TEAM, TENANT, $sep, meta(), USER);
check('Nach Verwerfen: neuer Stapel nimmt die Rechnung wieder auf', ['M-SEP'], array_column($again->summary['snapshots'], 'invoice'));
check('Nach Verwerfen: eindeutige Bezeichnung (Teil 3)', 'CTRL Umb IT Sep 2026 Teil 3', $again->label);

// ---- 5: vor der Umstellung kein Stapel ------------------------------------------------------

$sepC = snapshot('M-SEP-SPAET', '2026-09-01', 30.0, ['2000' => 10.0, '2500' => 20.0]);
$err = fails(fn () => $service->create(TEAM, TENANT, $sep, meta(), USER, startMonth: new DateTimeImmutable('2026-10-01')));
check('Vor der Umstellung: abgelehnt mit Begründung', true, $err !== null && str_contains($err[1], 'vor der Umstellung'));
check('Vor der Umstellung: nichts gesperrt', null, $sepC->fresh()->datev_batch_id);

check_summary();
