<?php

/**
 * Trägerliste: extrahierte Filter-Query, Mehrfachauswahl und die Gates der Sammelaktion.
 *
 * Der Schwerpunkt liegt auf `filteredQuery()`. Die Methode ist beim Einbau der Mehrfachauswahl aus
 * `render()` herausgelöst worden, damit Liste und Auswahl **dieselbe** Menge sehen — driftet sie,
 * wählt „alle gefilterten auswählen" etwas anderes aus als der Bildschirm zeigt, und die
 * Sammelaktion schreibt auf Zeilen, die niemand gesehen hat. Jedes Preset und jeder Sidebar-Filter
 * wird deshalb einzeln nachgerechnet.
 *
 * Aufruf: php tests/local/holders-bulk-type.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderTypeService;
use Platform\AssetManager\Services\LicenseSeatAllocator;
use Platform\AssetManager\Services\TenantContext;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('email')->nullable();
    $t->string('department')->nullable();
    $t->string('cost_center')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('source')->default('graph');
    $t->string('holder_type')->default('person');
    $t->string('function_system')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});

Schema::create('asset_devices', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->timestamps();
    $t->softDeletes();   // AssetDevice nutzt SoftDeletes — ohne die Spalte scheitert jede Query
});

Schema::create('asset_items', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable();
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('sku_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->timestamps();
});

Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->json('holder_type_rules')->nullable();
    $t->timestamps();
});

Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('period_label')->nullable();
    $t->timestamps();
});

TenantContext::forceTenant(1);

const TEAM   = 7;
const TENANT = 1;

function holder(array $attrs): AssetHolder
{
    return AssetHolder::create(array_merge([
        'team_id'     => TEAM,
        'tenant_id'   => TENANT,
        'holder_type' => 'person',
        'is_active'   => true,
        'source'      => 'graph',
    ], $attrs));
}

// --- Bestand --------------------------------------------------------------
// lic  : Lizenz, keine Geraete       dev  : Geraet, keine Lizenz
// item : Inventar-Asset              lone : nichts davon (unassigned)
// off  : inaktiv                     adm  : Admin-Konto mit Lizenz
$lic  = holder(['user_principal_name' => 'lic@k.de',  'display_name' => 'Lisa',  'department' => 'IT']);
$dev  = holder(['user_principal_name' => 'dev@k.de',  'display_name' => 'Dieter','department' => 'Kueche']);
$item = holder(['user_principal_name' => 'item@k.de', 'display_name' => 'Ida',   'department' => 'IT']);
$lone = holder(['user_principal_name' => 'lone@k.de', 'display_name' => 'Lars',  'department' => 'Kueche']);
$off  = holder(['user_principal_name' => 'off@k.de',  'display_name' => 'Olga',  'is_active' => false]);
$adm  = holder(['user_principal_name' => 'adm@k.de',  'display_name' => 'Admin', 'holder_type' => 'admin']);

DB::table('asset_user_licenses')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'sku_id' => 'e3', 'user_principal_name' => 'lic@k.de'],
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'sku_id' => 'e1', 'user_principal_name' => 'adm@k.de'],
]);
DB::table('asset_devices')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'dev@k.de'],
]);
DB::table('asset_items')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'assignee_id' => $item->id],
]);

// --- Komponente ohne Auth -------------------------------------------------
$component = new class extends Platform\AssetManager\Livewire\Holders\Index {
    public function teamId(): int { return TEAM; }
    public function activeTenantId(): int { return TENANT; }
};

$call = function (string $method, ...$args) use ($component) {
    $m = new ReflectionMethod($component, $method);
    $m->setAccessible(true);

    return $m->invoke($component, ...$args);
};

/** IDs, die die extrahierte Query liefert — sortiert, damit der Vergleich stabil ist. */
$ids = function () use ($call): array {
    $out = $call('filteredQuery', TEAM)->pluck('id')->map(fn ($i) => (int) $i)->all();
    sort($out);

    return $out;
};

$sorted = function (array $models): array {
    $out = array_map(fn ($m) => (int) $m->id, $models);
    sort($out);

    return $out;
};

// --- filteredQuery: jedes Preset ------------------------------------------
// Default ist preset=active + filterHolderType=person: nur Personen mit Lizenz, Geraet oder Asset.
check('Preset active (Default)', $sorted([$lic, $dev, $item]), $ids());

$component->preset = 'all';
check('Preset all (nur Personen)', $sorted([$lic, $dev, $item, $lone, $off]), $ids());

$component->preset = 'unassigned';
check('Preset unassigned', $sorted([$lone]), $ids());

$component->preset = 'inactive';
check('Preset inactive', $sorted([$off]), $ids());

$component->preset = 'with_license';
check('Preset with_license (adm faellt am Typfilter raus)', $sorted([$lic]), $ids());

$component->preset = 'with_device';
check('Preset with_device', $sorted([$dev]), $ids());

$component->preset = 'with_asset';
check('Preset with_asset', $sorted([$item]), $ids());

// --- filteredQuery: Sidebar-Filter ----------------------------------------
$component->preset = 'all';

$component->filterHolderType = '';
check('Typfilter leer zeigt auch Nicht-Personen', 6, count($ids()));

$component->filterHolderType = 'non_person';
check('Typfilter non_person', $sorted([$adm]), $ids());

$component->filterHolderType = 'admin';
check('Typfilter auf konkreten Typ', $sorted([$adm]), $ids());

$component->filterHolderType = 'person';
$component->search = 'Lisa';
check('Suche auf display_name', $sorted([$lic]), $ids());

$component->search = 'lone@';
check('Suche auf UPN', $sorted([$lone]), $ids());

$component->search = '';
$component->filterDept = 'Kueche';
check('Abteilungsfilter', $sorted([$dev, $lone]), $ids());

$component->filterDept = '';
$component->filterHasDevice = true;
check('Filter hat Geraet', $sorted([$dev]), $ids());

$component->filterHasDevice = false;
$component->filterHasAsset = true;
check('Filter hat Asset', $sorted([$item]), $ids());

$component->filterHasAsset = false;
$component->filterHasLicense = true;
check('Filter hat Lizenz', $sorted([$lic]), $ids());

$component->filterHasLicense = false;
$component->filterSku = 'e3';
check('Filter auf konkrete SKU', $sorted([$lic]), $ids());

$component->filterSku = '';
$component->filterDept = 'IT';
$component->filterHasAsset = true;
check('Filter kombinieren', $sorted([$item]), $ids());

// --- Mehrfachauswahl ------------------------------------------------------
$component->filterDept = '';
$component->filterHasAsset = false;
$component->preset = 'all';
$component->perPage = 3;

$component->updatedSelectPage(true);
check('Kopf-Haekchen waehlt genau die Seite', 3, count($component->selected));
check('IDs sind Strings', 'string', gettype($component->selected[0]));

$component->updatedSelectPage(false);
check('Abwaehlen entfernt sie wieder', 0, count($component->selected));

// Auswahl ueber zwei Seiten sammeln — der Grund, warum Blaettern die Auswahl NICHT verwirft.
$component->updatedSelectPage(true);
$first = $component->selected;
$component->setPage(2);
$component->updatingPage();
check('Blaettern behaelt die Auswahl', 3, count($component->selected));
check('Blaettern loest nur das Kopf-Haekchen', false, $component->selectPage);

$component->updatedSelectPage(true);
check('Zweite Seite kommt hinzu', 5, count($component->selected));
check('Erste Seite ist noch drin', true, array_intersect($first, $component->selected) === $first);

$component->setPage(1);
$component->selectAllFiltered();
check('selectAllFiltered nimmt alle gefilterten, nicht nur die Seite', 5, count($component->selected));

$component->filterHolderType = '';
$component->selectAllFiltered();
check('selectAllFiltered folgt dem Filter', 6, count($component->selected));
check('selectionCapped: unter dem Deckel false', false, $component->selectionCapped());

// --- Seitengroesse „Alle" -------------------------------------------------
$AM_ALL = Platform\AssetManager\Livewire\Holders\Index::PER_PAGE_ALL;

check('effectivePerPage: fester Wert bleibt', 3, $call('effectivePerPage', null));

$component->perPage = $AM_ALL;
check('effectivePerPage: Alle nimmt die Treffermenge', 6, $call('effectivePerPage', 6));
check('effectivePerPage: Alle bei 0 Treffern bleibt >= 1', 1, $call('effectivePerPage', 0));

// Bei „Alle" waehlt das Kopf-Haekchen die ganze Treffermenge, nicht nur eine Seite.
$component->selected   = [];
$component->selectPage = false;
$component->updatedSelectPage(true);
check('Alle: Kopf-Haekchen waehlt die ganze Menge', 6, count($component->selected));

$component->selected = ['1', '2'];
$component->updatingPerPage();
check('Seitengroesse-Wechsel behaelt die Auswahl', 2, count($component->selected));
check('Seitengroesse-Wechsel loest das Kopf-Haekchen', false, $component->selectPage);

$component->perPage  = 3;
$component->selected = [];

// --- Auswahl und Filterwechsel -------------------------------------------
foreach (['updatingSearch', 'updatingFilterDept', 'updatingFilterHolderType', 'updatingFilterSku',
          'updatingFilterSource', 'updatingFilterCostCenter', 'updatingPreset',
          'updatingFilterHasLicense', 'updatingFilterHasDevice', 'updatingFilterHasAsset'] as $hook) {
    $component->selected = ['1', '2'];
    $component->$hook();

    check("Hook {$hook} verwirft die Auswahl", 0, count($component->selected));
}

$component->selected = ['1', '2'];
$component->resetFilters();
check('resetFilters verwirft die Auswahl', 0, count($component->selected));

// --- Gate: erst verweigert ------------------------------------------------
$service = new HolderTypeService(new HolderClassifier(), new class extends LicenseSeatAllocator {
    public function allocateForPeriod(int $teamId, int $tenantId, string $periodLabel, bool $dryRun = false): array
    {
        return [];
    }
});

$denied = 0;
foreach (['openBulkType', 'bulkSetType'] as $method) {
    try {
        $component->$method($service);
    } catch (Illuminate\Auth\Access\AuthorizationException) {
        $denied++;
    }
}
check('Beide Schreibpfade sind ohne Recht gesperrt', 2, $denied);

// Nullable-Parameter ist Pflicht: Laravel ruft before-Callbacks fuer Gaeste nur auf,
// wenn der erste Parameter null zulaesst (Gate::callbackAllowsGuests).
Gate::before(fn (?object $user = null) => true);

// --- Vorschau und Ausfuehrung --------------------------------------------
$component->selected = [(string) $adm->id];
$component->bulkType = AssetHolder::TYPE_SERVICE;
$component->openBulkType($service);

check('Vorschau oeffnet das Modal', true, $component->showBulkType);
check('Vorschau meldet 1 Aenderung', 1, $component->bulkPreview['summary']['updated']);
check('Vorschau schreibt nicht', 'admin', $adm->fresh()->holder_type);

$component->updatedShowBulkType(false);
check('Backdrop verwirft die Vorschau', null, $component->bulkPreview);
check('Backdrop behaelt die Auswahl', 1, count($component->selected));

$component->openBulkType($service);
$component->bulkSetType($service);

check('Ausfuehrung schreibt', 'service', $adm->fresh()->holder_type);
check('Ausfuehrung leert die Auswahl', 0, count($component->selected));
check('Ausfuehrung leert den Zieltyp', '', $component->bulkType);
check('Ausfuehrung meldet zurueck', true, str_contains((string) $component->flash, 'Service'));

// Ungueltiger Zieltyp erreicht den Schreibpfad gar nicht erst.
$component->selected = [(string) $lic->id];
$component->bulkType = 'quatsch';
$component->openBulkType($service);
check('Unbekannter Typ oeffnet kein Modal', false, $component->showBulkType);
check('Unbekannter Typ meldet den Grund', true, str_contains((string) $component->flash, 'Unbekannter'));

check_summary();
