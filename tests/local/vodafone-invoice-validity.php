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

check_summary();
