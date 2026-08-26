<?php

/**
 * Gruppierte Ansicht, Kennzahlen und Auswahl der Kostenpositionen-Liste.
 *
 * Deckt ab: Aggregat-Query je Achse, NULL-Gruppen, Gutschriften (negative Summen),
 * once-Positionen, das offPivot-Kennzeichen, Anteils-Bezugsgroesse, Sortierung nach
 * ABS(Betrag), Gueltigkeitsfilter, Reconcile-Preset, Mehrfachauswahl inkl. der
 * Kopf-Checkbox, die Gates der Schreibpfade, Bulk-Umbuchung, Sortierung der flachen
 * Ansicht (Joins duerfen Zeilen nicht vervielfachen) und den CSV-Export.
 *
 * Aufruf: php tests/local/cost-lines-grouping.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Services\TenantContext;

// --- Minimalschema: nur die Spalten, die die Queries anfassen -------------
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
    $t->date('valid_from')->nullable();
    $t->date('valid_to')->nullable();
    $t->boolean('active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('key');
    $t->string('name');
    $t->integer('sort_order')->default(0);
    $t->string('allocation_level')->default('cost_center');
    $t->string('aggregation_source')->default('cost_line');
    $t->boolean('allow_negative')->default(false);
    $t->string('system_default')->nullable();
    $t->unsignedBigInteger('vendor_default_id')->nullable();
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('parent_id')->nullable();
    $t->integer('depth')->default(0);
    $t->string('code');
    $t->string('name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->integer('sort_order')->default(0);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('name');
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('display_name')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});

const TEAM = 7;
const TENANT = 1;
TenantContext::forceTenant(TENANT);

// --- Daten: die reale Struktur im Kleinen --------------------------------
// Kostenarten: eine per Person, eine per Kostenstelle, eine Gutschrift, eine Graph-Quelle.
DB::table('asset_cost_types')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk',
     'allocation_level' => 'person', 'aggregation_source' => 'cost_line', 'allow_negative' => 0],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'necta', 'name' => 'necta',
     'allocation_level' => 'cost_center', 'aggregation_source' => 'cost_line', 'allow_negative' => 0],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'rabatt', 'name' => 'VF Lizenz RC Rabatt',
     'allocation_level' => 'person', 'aggregation_source' => 'cost_line', 'allow_negative' => 1],
    ['id' => 4, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'ms', 'name' => 'MS Lizenz',
     'allocation_level' => 'person', 'aggregation_source' => 'ms_license', 'allow_negative' => 0],
]);

DB::table('asset_cost_centers')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'parent_id' => null, 'depth' => 0, 'code' => 'verwaltung', 'name' => 'BROICH - VERWALTUNG'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'parent_id' => 1, 'depth' => 1, 'code' => '1520', 'name' => null],
]);

DB::table('asset_vendors')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'name' => 'Vodafone'],
]);

/** Legt eine Zeile ueber das Model an, damit der saving()-Hook monthly_amount berechnet. */
function line(array $attr): AssetCostLine
{
    return AssetCostLine::create(array_merge([
        'team_id'   => TEAM,
        'tenant_id' => TENANT,
        'currency'  => 'EUR',
        'frequency' => 'monthly',
        'active'    => true,
        'source'    => 'excel_import',
    ], $attr));
}

line(['cost_type_id' => 1, 'cost_center_id' => 2, 'vendor_id' => 1, 'label' => 'Mobilfunk A', 'amount' => 100]);
line(['cost_type_id' => 1, 'cost_center_id' => 2, 'vendor_id' => 1, 'label' => 'Mobilfunk B', 'amount' => 50]);
line(['cost_type_id' => 2, 'cost_center_id' => 1, 'label' => 'necta Zentrale', 'amount' => 600, 'frequency' => 'quarterly']);
line(['cost_type_id' => 3, 'cost_center_id' => 2, 'label' => 'Rabatt', 'amount' => -30]);
line(['cost_type_id' => 2, 'cost_center_id' => null, 'label' => 'necta ohne KSt', 'amount' => 20]);
line(['cost_type_id' => 2, 'cost_center_id' => 2, 'label' => 'Einmal-Setup', 'amount' => 500, 'frequency' => 'once']);
line(['cost_type_id' => 1, 'cost_center_id' => 2, 'label' => 'Inaktiv', 'amount' => 999, 'active' => false]);
line(['cost_type_id' => 4, 'cost_center_id' => 2, 'label' => 'MS-Seat (Graph-Kostenart)', 'amount' => 12]);
line(['cost_type_id' => 1, 'cost_center_id' => 2, 'label' => 'Abgelaufen', 'amount' => 77,
      'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31']);

// --- Komponente mit festem Team (teamId() greift sonst auf Auth zu) ------
$component = new class extends Platform\AssetManager\Livewire\CostLines\Index {
    public function teamId(): int { return TEAM; }
};

$call = function (string $method, ...$args) use ($component) {
    $m = new ReflectionMethod($component, $method);
    $m->setAccessible(true);
    return $m->invoke($component, ...$args);
};

echo "\n=== Achse Kostenart ===\n";
$component->groupBy = 'cost_type';
$groups = $call('decorateGroups', $call('groupRows'), [])->keyBy('label');

// Mobilfunk: 100 + 50 + 999(inaktiv) + 77(abgelaufen) = 1226; alle monatlich
check('Mobilfunk: Anzahl', 4, $groups['Mobilfunk']['count']);
check('Mobilfunk: EUR/Monat', 1226.0, $groups['Mobilfunk']['monthly']);
check('Mobilfunk: inaktiv gezaehlt', 1, $groups['Mobilfunk']['inactive']);
check('Mobilfunk: Bezugsebene', 'person', $groups['Mobilfunk']['level']);
check('Mobilfunk: nicht offPivot', false, $groups['Mobilfunk']['offPivot']);

// necta: 600 quarterly = 200/Mt, 20 monthly, 500 once = 0/Mt -> 220
check('necta: EUR/Monat (once zaehlt 0)', 220.0, $groups['necta']['monthly']);
check('necta: Einmalbetrag separat', 500.0, $groups['necta']['once']);
check('necta: Anzahl inkl. once', 3, $groups['necta']['count']);

// Gutschrift
check('Rabatt: negative Summe', -30.0, $groups['VF Lizenz RC Rabatt']['monthly']);

// Graph-Kostenart muss als "nicht im Pivot" markiert sein
check('MS Lizenz: offPivot markiert', true, $groups['MS Lizenz']['offPivot']);

// Anteil: Bezugsgroesse = Summe der POSITIVEN Gruppen = 1226 + 220 + 12 = 1458
check('Anteil Mobilfunk (Bezug = positive Summen)', 84.1, $groups['Mobilfunk']['sharePct']);
check('Anteil Rabatt als Betrag ohne Vorzeichen', 2.1, $groups['VF Lizenz RC Rabatt']['sharePct']);

echo "\n=== Sortierung: Betrag ohne Vorzeichen ===\n";
$order = $call('decorateGroups', $call('groupRows'), [])->pluck('label')->all();
check('groesste Gruppe zuerst', 'Mobilfunk', $order[0]);
check('Gutschrift nicht am Ende (vor MS Lizenz)', true,
    array_search('VF Lizenz RC Rabatt', $order, true) < array_search('MS Lizenz', $order, true));

echo "\n=== Achse Kostenstelle: NULL-Gruppe ===\n";
$component->groupBy = 'cost_center';
$byCenter = $call('decorateGroups', $call('groupRows'), [])->keyBy('key');
check('Gruppe "none" existiert', true, $byCenter->has('none'));
check('none: Label', 'Ohne Kostenstelle', $byCenter['none']['label']);
check('none: EUR/Monat', 20.0, $byCenter['none']['monthly']);
check('KSt 1520: Untertitel leer (name ist NULL)', null, $byCenter['2']['sublabel']);
check('KSt verwaltung: Untertitel gesetzt', 'BROICH - VERWALTUNG', $byCenter['1']['sublabel']);

echo "\n=== Achse Kreditor: NULL-Gruppe ===\n";
$component->groupBy = 'vendor';
$byVendor = $call('decorateGroups', $call('groupRows'), [])->keyBy('label');
check('Gruppe "Ohne Kreditor" existiert', true, $byVendor->has('Ohne Kreditor'));
check('Vodafone: Anzahl', 2, $byVendor['Vodafone']['count']);

echo "\n=== Achse Frequenz ===\n";
$component->groupBy = 'frequency';
$byFreq = $call('decorateGroups', $call('groupRows'), [])->keyBy('key');
check('Frequenz-Label uebersetzt', 'Monatlich', $byFreq['monthly']['label']);
check('once-Gruppe: monthly = 0', 0.0, $byFreq['once']['monthly']);
check('once-Gruppe: Einmalbetrag ausgewiesen', 500.0, $byFreq['once']['once']);

echo "\n=== Detail-Query der offenen Gruppe ===\n";
$component->groupBy   = 'cost_type';
$component->openGroup = '1';
$open = $call('openGroupLines');
check('Mobilfunk aufgeklappt: 4 Zeilen', 4, $open->count());
check('nach Betrag ohne Vorzeichen sortiert', 'Inaktiv', $open->first()->label);

$component->openGroup = 'none';
$component->groupBy   = 'cost_center';
check('offene NULL-Gruppe liefert ihre Zeile', 1, $call('openGroupLines')->count());

echo "\n=== KPIs ===\n";
$component->groupBy = 'cost_type';
$kpi = $call('kpis');
// positiv: 100+50+200+20+0(once)+999+12+77 = 1458 ; negativ: -30
check('KPI Anzahl', 9, $kpi['count']);
check('KPI Aufwand', 1458.0, $kpi['gross']);
check('KPI Gutschriften', -30.0, $kpi['credits']);
check('KPI Netto', 1428.0, $kpi['net']);
check('KPI Einmalkosten', 500.0, $kpi['once']);

echo "\n=== Gueltigkeits-Filter ===\n";
$component->filterValidity = 'expired';
check('nur abgelaufene', 1, $call('kpis')['count']);
$component->filterValidity = 'current';
check('nur aktuell gueltige (8 von 9)', 8, $call('kpis')['count']);
$component->filterValidity = '';

echo "\n=== Reconcile-Preset ===\n";
$component->applyReconcilePreset();
check('Preset setzt aktiv + gueltig', true, $component->isReconciled());
// aktiv UND heute gueltig: 100+50+200+20+0+12 = 382 brutto, -30 Gutschrift
check('Preset-Netto (ohne inaktiv/abgelaufen)', 352.0, $call('kpis')['net']);

echo "\n=== Auswahl (Bulk) ===\n";
$component->view      = 'grouped';
$component->groupBy   = 'cost_type';
$component->openGroup = null;
$component->filterActive   = '';
$component->filterValidity = '';
$component->clearSelection();

// Ganze Gruppe auswaehlen - nimmt ALLE Positionen der Gruppe, nicht nur die sichtbaren.
$component->selectGroup('2');   // necta: 3 Positionen
check('selectGroup: 3 IDs', 3, count($component->selected));
check('selectGroup: IDs sind Strings', true, is_string($component->selected[0]));

$component->selectGroup('3');   // + Rabatt: 1 Position
check('selectGroup addiert', 4, count($component->selected));
$component->selectGroup('3');
check('zweiter Aufruf erzeugt keine Duplikate', 4, count($component->selected));

$component->clearSelection();
check('clearSelection leert', 0, count($component->selected));

$component->selectAllFiltered();
check('selectAllFiltered: alle 9', 9, count($component->selected));

// Kopf-Checkbox: MUSS $selected wirklich fuellen, sonst ist sie ein Blindgaenger.
$component->clearSelection();
$component->perPage = 5;
$component->updatedSelectPage(true);
check('updatedSelectPage(true) waehlt die Seite', 5, count($component->selected));
$component->updatedSelectPage(false);
check('updatedSelectPage(false) hebt sie auf', 0, count($component->selected));

// Auswahl ueberlebt Ansichts- und Achsenwechsel, nicht aber einen Filterwechsel.
$component->selectGroup('2');
$component->setView('flat');
check('Auswahl ueberlebt Ansichtswechsel', 3, count($component->selected));
$component->setGroupBy('vendor');
check('Auswahl ueberlebt Achsenwechsel', 3, count($component->selected));
$component->updatingFilterVendor();
check('Filterwechsel verwirft die Auswahl', 0, count($component->selected));

echo "\n=== Bulk-Umbuchung aus der Komponente ===\n";
$component->view = 'grouped';
$component->setGroupBy('cost_type');
$component->selectGroup('2');                 // necta: KSt 1, KSt NULL, KSt 2
$component->bulkCenter = 1;                   // Ziel: verwaltung (id 1)
$svc = app(Platform\AssetManager\Services\CostLineReassignService::class);

// Erst nachweisen, dass die Schreibpfade das Gate wirklich fronten (kein User = kein Recht) ...
$denied = false;
try {
    $component->openBulkReassign($svc);
} catch (Illuminate\Auth\Access\AuthorizationException) {
    $denied = true;
}
check('openBulkReassign ohne Rechte blockiert', true, $denied);

$denied = false;
try {
    $component->bulkReassignCenter($svc);
} catch (Illuminate\Auth\Access\AuthorizationException) {
    $denied = true;
}
check('bulkReassignCenter ohne Rechte blockiert', true, $denied);

// ... dann fuer den Funktionstest erlauben.
// Nullable-Parameter ist Pflicht: Laravel ruft before-Callbacks fuer Gaeste nur auf,
// wenn der erste Parameter null zulaesst (Gate::callbackAllowsGuests).
Illuminate\Support\Facades\Gate::before(fn (?object $user = null) => true);

$component->openBulkReassign($svc);
check('Vorschau geoeffnet', true, $component->showBulkReassign);
check('Vorschau ist dry_run', true, $component->bulkPreview['dry_run']);
check('Vorschau: 2 wuerden umziehen', 2, $component->bulkPreview['summary']['updated']);
check('Vorschau: 1 haengt schon dort', 1, $component->bulkPreview['summary']['unchanged']);

$idsBefore = $component->selected;
$component->bulkReassignCenter($svc);
check('nach dem Umbuchen: Auswahl geleert', 0, count($component->selected));
check('Modal geschlossen', false, $component->showBulkReassign);
check('alle drei liegen jetzt auf KSt 1', 3,
    AssetCostLine::whereIn('id', $idsBefore)->where('cost_center_id', 1)->count());


echo "\n=== Sortierung der flachen Ansicht (alle Felder) ===\n";
$component->view = 'flat';
$component->clearSelection();
foreach (['label', 'cost_type', 'cost_center', 'assignee', 'vendor', 'amount', 'frequency',
          'monthly_amount', 'valid_from'] as $field) {
    foreach (['asc', 'desc'] as $dir) {
        $component->sortField     = $field;
        $component->sortDirection = $dir;
        try {
            $q = $call('baseQuery');
            $m = new ReflectionMethod($component, 'applySort');
            $m->setAccessible(true);
            $m->invoke($component, $q);
            $count = $q->select('asset_cost_lines.*')->get()->count();
            $ok = $count === 9;   // Joins sind 1:1, die Zeilenzahl darf sich nicht veraendern
        } catch (\Throwable $e) {
            $ok = false;
            echo '   Fehler: ' . $e->getMessage() . "\n";
        }
        check("sortBy($field, $dir) ohne Vervielfachung", true, $ok);
    }
}
$component->sortField     = 'monthly_amount';
$component->sortDirection = 'desc';

echo "\n=== CSV-Export ===\n";
$response = $component->exportCsv();
check('liefert StreamedResponse', true, $response instanceof Symfony\Component\HttpFoundation\StreamedResponse);
check('Content-Type', 'text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
check('Dateiname im Header', true, str_contains((string) $response->headers->get('Content-Disposition'), 'kostenpositionen-'));

ob_start();
$response->sendContent();
$csv = ob_get_clean();

check('UTF-8-BOM vorhanden', "\xEF\xBB\xBF", substr($csv, 0, 3));
$rows = array_values(array_filter(explode("\n", trim($csv))));
check('Kopfzeile + 9 Datenzeilen', 10, count($rows));
check('Semikolon als Trenner', true, str_contains($rows[0], 'Bezeichnung;Kostenart'));
check('ID als erste Spalte', true, str_starts_with(ltrim($rows[0], "\xEF\xBB\xBF"), 'ID;'));
check('Traeger-Spalte im Kopf', true, str_contains($rows[0], 'Asset-Traeger'));
check('Dezimalkomma in den Betraegen', true, str_contains($csv, '937,72') || str_contains($csv, '100,00'));

// Der Export muss den Filtern des Bildschirms folgen.
$component->filterFreq = 'once';
ob_start();
$component->exportCsv()->sendContent();
$filtered = array_values(array_filter(explode("\n", trim(ob_get_clean()))));
check('Export folgt dem Filter (1 once-Zeile)', 2, count($filtered));
$component->filterFreq = '';


echo "\n";
check_summary();
