<?php

/**
 * Trägerliste: anpassbare Tabellen-Ansicht (#889, ADR 0020) — Spaltenreihenfolge und Sichtbarkeit
 * pro User × Team über {@see \Platform\AssetManager\Concerns\CustomizesTableView}.
 *
 * Erfolgssignal aus dem Issue: eine ausgeblendete Spalte bleibt nach Neuladen **und** nach einem
 * Tenant-Wechsel ausgeblendet, und die Trefferzahl ändert sich nicht. Dazu: Spalten, die in der Sicht
 * nicht gelten (Offboarding, Controlling), behalten beim Umsortieren ihren Platz; unbekannte
 * Schlüssel werden ignoriert; die letzte sichtbare Spalte bleibt; Zurücksetzen räumt die Zeile weg.
 *
 * Aufruf: php tests/local/holders-table-view.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Livewire\Holders\Index as HoldersIndex;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetTableView;
use Platform\AssetManager\Services\TenantContext;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_table_views', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('team_id');
    $t->string('table_key', 64);
    $t->json('payload');
    $t->timestamps();
    $t->unique(['user_id', 'team_id', 'table_key']);
});
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
    $t->boolean('holder_type_pinned')->default(false);
    $t->boolean('is_active')->default(true);
    $t->timestamp('billing_excluded_at')->nullable();
    $t->string('billing_excluded_reason')->nullable();
    $t->timestamps();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_items', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('sku_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->timestamps();
});

const TEAM   = 7;
const TENANT = 1;
const KEY    = 'holders.index';

TenantContext::forceTenant(TENANT);

foreach (['a@k.de', 'b@k.de', 'c@k.de'] as $upn) {
    AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => $upn, 'holder_type' => 'person', 'is_active' => true]);
}

$proto = new class extends HoldersIndex {
    public static int $user = 22;
    public function teamId(): int { return TEAM; }
    protected function currentUserId(): int { return static::$user; }
};
$class = get_class($proto);

$open = fn (): HoldersIndex => new $class();
$call = function (HoldersIndex $c, string $method, ...$args) {
    $m = new ReflectionMethod($c, $method);
    $m->setAccessible(true);

    return $m->invoke($c, ...$args);
};
$hits = function (HoldersIndex $c) use ($call): int {
    $c->preset           = 'all';
    $c->filterHolderType = '';

    return $call($c, 'filteredQuery', TEAM)->count();
};
$rows = fn (): int => AssetTableView::where('table_key', KEY)->count();

$base    = ['department', 'cost_center', 'devices', 'assets', 'licenses', 'source'];
$allKeys = array_keys(HoldersIndex::TABLE_COLUMNS);

// --- 1) Werkszustand ----------------------------------------------------------------------------
$c = $open();
check('Default: alle Spalten in Ursprungsreihenfolge', $allKeys, $call($c, 'tableColumnOrder'));
check('Normale Sicht: nur die Grundspalten',          $base, $call($c, 'tableColumnsFor', false, false));
check('Offboarding ohne Controlling: + 2 Spalten',     [...$base, 'open_assignments', 'open_handovers'], $call($c, 'tableColumnsFor', true, false));
check('Offboarding mit Controlling: + Betrag',         $allKeys, $call($c, 'tableColumnsFor', true, true));
check('Default: keine gespeicherte Zeile',             0, $rows());

$hitsBefore = $hits($c);

// --- 2) Ausblenden ------------------------------------------------------------------------------
$c->toggleTableColumn('department');
check('Ausgeblendet: Spalte fehlt',                    ['cost_center', 'devices', 'assets', 'licenses', 'source'], $call($c, 'tableColumnsFor', false, false));
check('Ausgeblendet: Trefferzahl unverändert',         $hitsBefore, $hits($c));
check('Genau eine Zeile',                              1, $rows());

$menu = $call($c, 'tableColumnMenuFor', false, false);
check('Menü: ausgeblendete Spalte bleibt erreichbar',  true, $menu[0]['key'] === 'department' && $menu[0]['hidden'] === true);
check('Menü: Offboarding-Spalten außerhalb nicht drin', false, in_array('open_assignments', array_column($menu, 'key'), true));

// --- 3) Erfolgssignal: Neuladen und Tenant-Wechsel ----------------------------------------------
$c = $open();
check('Neu geladen: weiter ausgeblendet',              false, in_array('department', $call($c, 'tableColumnsFor', false, false), true));
check('Neu geladen: Trefferzahl unverändert',          $hitsBefore, $hits($c));

TenantContext::forceTenant(2);
$c = $open();
check('Nach Tenant-Wechsel: weiter ausgeblendet',      false, in_array('department', $call($c, 'tableColumnsFor', false, false), true));
TenantContext::forceTenant(TENANT);

$class::$user = 23;
check('Anderer Nutzer: Spalte sichtbar',               true, in_array('department', $call($open(), 'tableColumnsFor', false, false), true));
$class::$user = 22;

// --- 4) Unbekanntes wird ignoriert --------------------------------------------------------------
$c = $open();
$before = $call($c, 'tableView')->toArray();
$c->toggleTableColumn('password');
$c->toggleTableColumn('name');          // Name ist fest, kein anpassbarer Schlüssel
check('Unbekannte Spalte: Ansicht unverändert',        $before, $call($c, 'tableView')->toArray());

// --- 5) Umsortieren: nicht gerenderte Spalten behalten ihren Platz ---------------------------------
// Gerendert sind in der normalen Sicht 5 Spalten (department ausgeblendet, Offboarding-Spalten
// gelten nicht). Der Browser schickt nur diese — alles andere muss stehen bleiben.
$c = $open();
$c->reorderTableColumns([
    ['order' => 1, 'value' => 'source'],
    ['order' => 2, 'value' => 'cost_center'],
    ['order' => 3, 'value' => 'devices'],
    ['order' => 4, 'value' => 'assets'],
    ['order' => 5, 'value' => 'licenses'],
]);
// department stand vorne und bleibt dort — es kam im Payload nicht vor.
check('Umsortiert: Ausgeblendetes und Offboarding-Spalten an ihrem Platz',
    ['department', 'source', 'cost_center', 'devices', 'assets', 'licenses', 'open_assignments', 'open_handovers', 'cost'],
    $call($open(), 'tableColumnOrder'));
check('Umsortiert: sichtbare Reihenfolge',             ['source', 'cost_center', 'devices', 'assets', 'licenses'], $call($open(), 'tableColumnsFor', false, false));
check('Umsortiert: Ausblendung bleibt',                true, $call($open(), 'tableView')->isColumnHidden('department'));

// Manipuliertes Drag-Payload: Dubletten und Fremdes fallen weg, nichts geht verloren.
$c = $open();
$beforeOrder = $call($c, 'tableColumnOrder');
$c->reorderTableColumns(['licenses', 'licenses', '<script>', 'evil']);
$order = $call($open(), 'tableColumnOrder');
$sorted = $order;
sort($sorted);
$expected = $allKeys;
sort($expected);
check('Manipuliertes Payload: jede Spalte genau einmal', $expected, $sorted);
// Nur eine echte Spalte im Payload: alle anderen behalten ihren Platz, also ändert sich nichts.
check('Manipuliertes Payload: Reihenfolge stabil',      $beforeOrder, $order);

// --- 6) Die letzte sichtbare Spalte bleibt ------------------------------------------------------
$c = $open();
$c->resetTableView();
foreach ($allKeys as $key) {
    $c->toggleTableColumn($key);
}
check('Letzte Spalte lässt sich nicht ausblenden',      1, count($call($c, 'visibleTableColumns')));
check('Letzte Spalte: Trefferzahl unverändert',         $hitsBefore, $hits($c));

// --- 7) Zurücksetzen ----------------------------------------------------------------------------
$c->resetTableView();
check('Zurückgesetzt: alle Spalten wieder da',          $allKeys, $call($open(), 'tableColumnsFor', true, true));
check('Zurückgesetzt: keine eigene Zeile mehr',         0, AssetTableView::where('table_key', KEY)->where('user_id', 22)->count());

// Ein- und wieder ausblenden derselben Spalte = Werkszustand = keine Zeile (ADR 0020 §1).
$c = $open();
$c->toggleTableColumn('source');
$c->toggleTableColumn('source');
check('Hin und zurück: keine Zeile',                    0, AssetTableView::where('table_key', KEY)->where('user_id', 22)->count());

check_summary();
