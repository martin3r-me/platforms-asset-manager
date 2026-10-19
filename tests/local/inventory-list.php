<?php

/**
 * Inventar-Liste (#974, #977): SQL-Vorfilter, Sortierung über beide Quellen, CSV-Export und die
 * Sammelaktionen, die von der Legacy-Liste /assets ins Inventar umgezogen sind.
 *
 *  1. Filter — jeder Sidebar-Filter wird in SQL ausgewertet und muss dieselbe Menge liefern wie vorher
 *     der PHP-Filter. Fremder Tenant und fremdes Team bleiben draußen.
 *  2. Seite vs. Export — die Seite sortiert über schmale Schlüssel, der Export über volle Zeilen. Beide
 *     müssen dieselbe Reihenfolge haben, sonst stimmt die CSV nicht mit dem Bildschirm überein.
 *  3. Export — Zeilenzahl = Trefferzahl, Kostenspalten nur mit Controlling (ADR 0008).
 *  4. Sammelaktionen — über den AssetWriteService (Status-Regel #941), nur eigene IDs.
 *  5. Sammelanlage — Seriennummer bleibt bei mehreren Stücken leer, Zuordnung je Stück.
 *  6. Query-Budget — eine Seite kostet gleich viele Abfragen, egal wie groß der Bestand ist; Träger
 *     werden nur bei offenem Modal bzw. offener Auswahl geladen.
 *
 * Aufruf: php tests/local/inventory-list.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\InventoryService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\InventoryRow;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_items', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('category_id')->nullable();
    $t->string('source')->default('manual');
    $t->string('name')->nullable();
    $t->string('manufacturer')->nullable();
    $t->string('model')->nullable();
    $t->string('serial_number')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable();
    $t->timestamp('assigned_at')->nullable();
    $t->string('status')->default('in_stock');
    $t->date('purchase_date')->nullable();
    $t->decimal('purchase_price', 12, 2)->nullable();
    $t->integer('depreciation_months')->nullable();
    $t->text('notes')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('device_name')->nullable();
    $t->string('manufacturer')->nullable();
    $t->string('model')->nullable();
    $t->string('serial_number')->nullable();
    $t->string('user_display_name')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('lifecycle_status')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->string('location')->nullable();
    $t->decimal('monthly_cost', 10, 2)->nullable();
    $t->decimal('purchase_price', 10, 2)->nullable();
    $t->integer('depreciation_months')->nullable();
    $t->date('purchase_date')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_categories', function (Blueprint $t) {
    $t->id(); $t->string('name')->nullable(); $t->integer('sort_order')->default(0);
    $t->integer('default_depreciation_months')->nullable(); $t->string('icon')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('code')->nullable(); $t->string('name')->nullable(); $t->boolean('is_active')->default(true);
    $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_assignments', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id')->nullable(); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('asset_item_id')->nullable(); $t->string('assignable_type')->nullable(); $t->unsignedBigInteger('assignable_id')->nullable();
    $t->unsignedBigInteger('holder_id')->nullable(); $t->timestamp('assigned_at')->nullable(); $t->timestamp('returned_at')->nullable();
    $t->string('source')->nullable(); $t->timestamps();
});
Schema::create('asset_device_models', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id')->nullable(); $t->string('manufacturer')->nullable(); $t->string('model')->nullable();
    $t->integer('depreciation_months')->nullable(); $t->timestamps();
});
Schema::create('asset_device_model_costs', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id')->nullable(); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('device_model_id'); $t->unsignedBigInteger('cost_type_id')->nullable(); $t->unsignedBigInteger('vendor_id')->nullable();
    $t->decimal('monthly_cost', 10, 2)->nullable(); $t->decimal('purchase_price', 10, 2)->nullable(); $t->timestamps();
});
Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name')->nullable(); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_tenant_selections', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('selected_tenant_id')->nullable();
    $t->boolean('all_tenants')->default(false); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(false); $t->timestamps();
});

const TEAM   = 7;
const TENANT = 1;

TenantContext::forceTenant(TENANT);
config(['cache.default' => 'array']);

// Die Modul-Routen sind ohne `modules`-Tabelle nicht registriert; die Zeilen bauen ihren Detail-Link.
Illuminate\Support\Facades\Route::get('/inventory/{type}/{id}', fn () => '')->name('asset-manager.inventory.show');
app('router')->getRoutes()->refreshNameLookups();

DB::table('asset_tenants')->insert([['id' => TENANT, 'team_id' => TEAM, 'name' => 'Kunde', 'is_default' => true]]);
DB::table('asset_holders')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'anna@k.de', 'display_name' => 'Anna Beispiel', 'is_active' => true],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'bernd@k.de', 'display_name' => 'Bernd', 'is_active' => true],
    ['id' => 3, 'team_id' => 99, 'tenant_id' => TENANT, 'user_principal_name' => 'fremd@k.de', 'display_name' => 'Fremd', 'is_active' => true],
]);
DB::table('asset_categories')->insert([['id' => 1, 'name' => 'Monitor'], ['id' => 2, 'name' => 'Tastatur']]);
DB::table('asset_cost_centers')->insert([
    ['id' => 10, 'team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2599', 'name' => 'Verwaltung'],
    ['id' => 11, 'team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2600', 'name' => 'Küche'],
]);
DB::table('asset_items')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 1, 'name' => 'Dell Monitor U2720', 'manufacturer' => 'Dell', 'model' => 'U2720',
     'assignee_id' => 1, 'status' => 'assigned', 'purchase_price' => 360, 'depreciation_months' => 36, 'serial_number' => 'SN-1'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 2, 'name' => 'Logitech MX Keys', 'manufacturer' => 'Logitech', 'model' => 'MX',
     'assignee_id' => null, 'status' => 'in_stock', 'purchase_price' => null, 'depreciation_months' => null, 'serial_number' => null],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 1, 'name' => 'Zebra Drucker', 'manufacturer' => 'Zebra', 'model' => 'ZD',
     'assignee_id' => null, 'status' => 'retired', 'purchase_price' => null, 'depreciation_months' => null, 'serial_number' => null],
    ['id' => 4, 'team_id' => TEAM, 'tenant_id' => 2, 'category_id' => 1, 'name' => 'Fremder Tenant', 'manufacturer' => 'Dell', 'model' => 'X',
     'assignee_id' => null, 'status' => 'in_stock', 'purchase_price' => null, 'depreciation_months' => null, 'serial_number' => null],
    ['id' => 5, 'team_id' => 99, 'tenant_id' => TENANT, 'category_id' => 1, 'name' => 'Fremdes Team', 'manufacturer' => 'Dell', 'model' => 'X',
     'assignee_id' => 3, 'status' => 'assigned', 'purchase_price' => null, 'depreciation_months' => null, 'serial_number' => null],
]);
DB::table('asset_assignments')->insert([
    ['tenant_id' => TENANT, 'asset_item_id' => 1, 'assignable_type' => 'item', 'assignable_id' => 1, 'holder_id' => 1, 'assigned_at' => '2026-01-01 00:00:00', 'source' => 'manual'],
]);
DB::table('asset_devices')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'LAPTOP-01', 'manufacturer' => 'Dell', 'model' => 'Latitude 5440',
     'user_display_name' => 'Anna Beispiel', 'user_principal_name' => 'anna@k.de', 'lifecycle_status' => 'in_use', 'cost_center_id' => 10,
     'monthly_cost' => 21.36, 'location' => 'Düsseldorf'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'LAPTOP-02', 'manufacturer' => 'Lenovo', 'model' => 'T14',
     'user_display_name' => null, 'user_principal_name' => '', 'lifecycle_status' => 'spare', 'cost_center_id' => 11,
     'monthly_cost' => null, 'location' => null],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'IPHONE-7', 'manufacturer' => 'Apple', 'model' => 'iPhone 15',
     'user_display_name' => null, 'user_principal_name' => 'bernd@k.de', 'lifecycle_status' => null, 'cost_center_id' => null,
     'monthly_cost' => null, 'location' => null],
    ['id' => 4, 'team_id' => TEAM, 'tenant_id' => 2, 'device_name' => 'FREMD-TENANT', 'manufacturer' => 'Dell', 'model' => 'X',
     'user_display_name' => null, 'user_principal_name' => null, 'lifecycle_status' => null, 'cost_center_id' => null,
     'monthly_cost' => null, 'location' => null],
]);

$svc = app(InventoryService::class);

/** Zeilen-Schlüssel „m1"/„i3" einer Seite bzw. Collection. */
$keys = fn (iterable $rows): array => collect($rows instanceof Illuminate\Pagination\LengthAwarePaginator ? $rows->items() : $rows)->map(fn (InventoryRow $r) => ($r->type === 'manual' ? 'm' : 'i') . $r->id)->values()->all();
$page = fn (array $filters, string $sort = 'name', string $dir = 'asc', int $per = 25, int $p = 1) => $svc->page(TEAM, TENANT, $filters, $sort, $dir, $per, $p);

// --- 1) Filter ------------------------------------------------------------
check('Ohne Filter: alle 6 eigenen Zeilen, nach Name', ['m1', 'i3', 'i1', 'i2', 'm2', 'm3'], $keys($page([])));
check('Ohne Filter: Trefferzahl', 6, $page([])->total());
check('Typ manuell', ['m1', 'm2', 'm3'], $keys($page(['type' => 'manual'])));
check('Typ Intune', ['i3', 'i1', 'i2'], $keys($page(['type' => 'intune'])));
check('Zugeordnet (Item über Träger, Gerät über Name/UPN)', ['m1', 'i3', 'i1'], $keys($page(['assignment' => 'assigned'])));
check('Nicht zugeordnet (leere UPN zählt als leer)', ['i2', 'm2', 'm3'], $keys($page(['assignment' => 'unassigned'])));
check('Status Lager (Item)', ['m2'], $keys($page(['status' => 'in_stock'])));
check('Status In Betrieb (Gerät)', ['i1'], $keys($page(['status' => 'in_use'])));
check('Kategorie schließt Geräte aus', ['m1', 'm3'], $keys($page(['category' => 1])));
check('Kostenstelle schließt Items aus', ['i1'], $keys($page(['costCenter' => 10])));
check('Suche Hersteller', ['m1', 'i1'], $keys($page(['search' => 'dell'])));
check('Suche wortweise über Spalten', ['i1'], $keys($page(['search' => 'dell lat'])));
check('Suche findet Item über Trägernamen', ['m1', 'i1'], $keys($page(['search' => 'Anna'])));
check('Suche Seriennummer', ['m1'], $keys($page(['search' => 'SN-1'])));
check('Kombiniert: manuell + Kategorie 1 + Suche', ['m3'], $keys($page(['type' => 'manual', 'category' => 1, 'search' => 'zebra'])));

// --- 2) Sortierung + Blättern; Seite == Export-Reihenfolge ------------------
check('Name absteigend', ['m3', 'm2', 'i2', 'i1', 'i3', 'm1'], $keys($page([], 'name', 'desc')));
check('Seite 2 bei 2 je Seite', ['i1', 'i2'], $keys($page([], 'name', 'asc', 2, 2)));
check('Seite 2: Gesamtzahl bleibt', 6, $page([], 'name', 'asc', 2, 2)->total());
foreach (['name', 'type', 'assignedTo', 'status', 'monthlyCost'] as $field) {
    foreach (['asc', 'desc'] as $dir) {
        check("Seite = Export-Reihenfolge ({$field} {$dir})",
            $keys($svc->sort($svc->filteredRows(TEAM, TENANT, []), $field, $dir)),
            $keys($page([], $field, $dir)));
    }
}
check('Monatskosten absteigend: Gerät-Override vor Item-AfA', ['i1', 'm1'], array_slice($keys($page([], 'monthlyCost', 'desc')), 0, 2));
check('Monatskosten des Geräts aufgelöst', 21.36, $page([], 'monthlyCost', 'desc')->first()->monthlyCost);
check('IDs der gefilterten manuellen Assets', [1, 3], $svc->filteredItemIds(TEAM, TENANT, ['category' => 1]));
check('Keine Item-IDs bei Typ Intune', [], $svc->filteredItemIds(TEAM, TENANT, ['type' => 'intune']));

// --- Komponente ohne Auth -------------------------------------------------
$component = new class extends Platform\AssetManager\Livewire\Inventory\Index {
    public function teamId(): int { return TEAM; }
};
$call = function (string $method, ...$args) use ($component) {
    $m = new ReflectionMethod($component, $method);
    $m->setAccessible(true);

    return $m->invoke($component, ...$args);
};
$controlling = function (bool $on) use ($component): void {
    $p = new ReflectionProperty($component, 'controllingMemo');
    $p->setAccessible(true);
    $p->setValue($component, $on);
};

// --- 3) Export ------------------------------------------------------------
$controlling(false);
$csv = iterator_to_array($call('csvRows', $svc), false);
check('Export ohne Controlling: 10 Spalten', 10, count($csv[0]));
check('Export ohne Controlling: keine Kostenspalte', false, in_array('Monatskosten EUR', $csv[0], true));
check('Export: Kopf + 6 Zeilen', 7, count($csv));
check('Export: erste Zeile = erste Zeile der Liste', ['Manuell', 1, 'Dell Monitor U2720'], array_slice($csv[1], 0, 3));
check('Export: Zuordnung + Status', ['Anna Beispiel', 'Zugeordnet', 'Monitor'], array_slice($csv[1], 6, 3));
check('Export: Gerät ohne Lifecycle hat leeren Status', '', collect($csv)->firstWhere(2, 'IPHONE-7')[7]);

$controlling(true);
$csv = iterator_to_array($call('csvRows', $svc), false);
check('Export mit Controlling: 12 Spalten', 12, count($csv[0]));
check('Export mit Controlling: Kostenstelle + Betrag am Gerät', ['Verwaltung', '21,36'], array_slice(collect($csv)->firstWhere(2, 'LAPTOP-01'), 10, 2));
check('Export mit Controlling: Item-AfA', '10,00', collect($csv)->firstWhere(2, 'Dell Monitor U2720')[11]);

$component->filterType = 'manual';
$component->sortDirection = 'desc';
$csv = iterator_to_array($call('csvRows', $svc), false);
check('Export folgt Filter und Sortierung', ['Zebra Drucker', 'Logitech MX Keys', 'Dell Monitor U2720'], array_column(array_slice($csv, 1), 2));

// Kostenstellen-Filter ohne Controlling wirkt nicht (#874) — auch nicht im Export.
$controlling(false);
$component->filterType = '';
$component->sortDirection = 'asc';
$component->filterCostCenter = 10;
check('Ohne Controlling: KST-Filter wirkungslos', 7, count(iterator_to_array($call('csvRows', $svc), false)));
$component->filterCostCenter = null;

// --- 4) Sammelaktionen ------------------------------------------------------
// Ohne Gate-Freigabe wird nichts geschrieben.
$denied = null;
try {
    $component->selected = ['2'];
    $component->bulkAssignee = 2;
    $component->bulkAssign(app(AssetWriteService::class));
} catch (Illuminate\Auth\Access\AuthorizationException $e) {
    $denied = 'denied';
}
check('Sammel-Zuordnen ohne Recht: verweigert', 'denied', $denied);
check('Sammel-Zuordnen ohne Recht: nichts geschrieben', null, AssetItem::find(2)->assignee_id);

Gate::before(fn (?object $user = null) => true);
$writer = app(AssetWriteService::class);

$component->selected     = ['2', '5'];   // 5 = fremdes Team → darf nicht angefasst werden
$component->bulkAssignee = 2;
$component->bulkAssign($writer);
check('Zuordnen: Item 2 an Bernd', [2, 'assigned'], [AssetItem::find(2)->assignee_id, AssetItem::find(2)->status]);
check('Zuordnen: Verlauf geschrieben', 1, AssetAssignment::where('asset_item_id', 2)->whereNull('returned_at')->count());
check('Zuordnen: fremdes Team unberührt', 3, (int) AssetItem::withoutTenantScope()->find(5)->assignee_id);
check('Zuordnen: Auswahl danach leer', [], $component->selected);
check('Zuordnen: Meldung', '1 Asset(s) an Bernd zugeordnet.', $component->flash);

$component->selected   = ['1', '2'];
$component->bulkStatus = 'lost';
$component->bulkSetStatus($writer);
check('Status Verloren: beide gesetzt', ['lost', 'lost'], [AssetItem::find(1)->status, AssetItem::find(2)->status]);
check('Status Verloren: Träger entfernt (#941)', [null, null], [AssetItem::find(1)->assignee_id, AssetItem::find(2)->assignee_id]);
check('Status Verloren: offene Zuordnungen geschlossen', 0, AssetAssignment::whereIn('asset_item_id', [1, 2])->whereNull('returned_at')->count());

$component->selected   = ['3'];
$component->bulkStatus = 'assigned';
$component->bulkSetStatus($writer);
check('Status Zugeordnet ohne Träger: übersprungen', 'retired', AssetItem::find(3)->status);
check('Status Zugeordnet ohne Träger: Hinweis', true, str_contains((string) $component->flash, '1 ohne Träger übersprungen'));

$component->selected   = ['3'];
$component->bulkStatus = 'kaputt';
$component->bulkSetStatus($writer);
check('Unbekannter Status: nichts passiert, Auswahl bleibt', ['retired', ['3']], [AssetItem::find(3)->status, $component->selected]);

$component->selected = ['1'];
$component->bulkUnassign($writer);
check('Ins Lager: Status Lager', 'in_stock', AssetItem::find(1)->status);

$component->selected = ['3', '5'];
$component->bulkDelete();
check('Löschen: eigenes Asset weg (soft)', [null, true], [AssetItem::find(3), AssetItem::withTrashed()->find(3)?->trashed()]);
check('Löschen: fremdes Team bleibt', false, AssetItem::withoutTenantScope()->find(5)->trashed());

$component->filterCategory = 1;
$component->selectAllFiltered($svc);
check('Alle gefilterten auswählen: nur manuelle der Filtermenge', ['1'], $component->selected);
$component->filterCategory = null;
$component->clearSelection();

// --- 5) Sammelanlage -------------------------------------------------------
$before  = AssetItem::count();
$created = $writer->createItems([
    'categoryId' => 2, 'name' => 'Headset', 'serialNumber' => 'DARF-NICHT', 'assigneeId' => 1,
], 3, TEAM, 1);
check('Sammelanlage: 3 Stück', 3, $created->count());
check('Sammelanlage: Bestand +3', $before + 3, AssetItem::count());
check('Sammelanlage: Seriennummer leer bei mehreren', [null, null, null], $created->pluck('serial_number')->all());
check('Sammelanlage: zugeordnet + Verlauf je Stück', [3, 3], [
    $created->where('status', 'assigned')->count(),
    AssetAssignment::whereIn('asset_item_id', $created->pluck('id'))->whereNull('returned_at')->count(),
]);
check('Sammelanlage: Tenant gesetzt', [TENANT], $created->pluck('tenant_id')->unique()->values()->all());
$single = $writer->createItems(['categoryId' => 2, 'name' => 'Maus', 'serialNumber' => 'M-1'], 1, TEAM, 1);
check('Einzelanlage behält die Seriennummer', 'M-1', $single->first()->serial_number);

// --- 6) Query-Budget + Träger nur bei Bedarf --------------------------------
function inv_queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

function inv_at_most(string $what, int $max, int $actual): void
{
    $GLOBALS['am_checks']['total']++;
    $ok = $actual <= $max;
    printf("%-58s %s  max=%d ist=%d\n", $what, $ok ? 'ok  ' : 'FAIL', $max, $actual);
    if (! $ok) {
        $GLOBALS['am_checks']['failed'][] = $what;
    }
}

$small = inv_queries(fn () => $page([], 'assignedTo')->items());
for ($i = 0; $i < 60; $i++) {
    DB::table('asset_items')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 1, 'name' => "Bulk {$i}", 'assignee_id' => 1, 'status' => 'assigned']);
    DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => "DEV-{$i}", 'manufacturer' => 'Dell', 'model' => "M{$i}", 'user_principal_name' => 'anna@k.de']);
}
$large = inv_queries(fn () => $page([], 'assignedTo')->items());
check('Seite: Abfragen unabhängig von der Bestandsgröße', $small, $large);
inv_at_most('Seite: feste Obergrenze an Abfragen', 10, $large);
check('Seite: nur perPage Zeilen aufgelöst', 25, count($page([])->items()));

$data = fn () => $component->render($svc)->getData();
$component->showCreate = false;
$component->selected   = [];
check('Modal zu, keine Auswahl: keine Träger geladen', 0, $data()['holders']->count());
$component->showCreate = true;
check('Modal offen: Träger geladen (aktive, eigenes Team)', 2, $data()['holders']->count());
$component->showCreate = false;
$component->selected   = ['1'];
check('Auswahl offen: Träger für Sammel-Zuordnung geladen', 2, $data()['holders']->count());

check_summary();
