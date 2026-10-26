<?php

/**
 * Neue MCP-Tools im echten Aufruf: Inventar (inventory.GET, item.GET, items.POST, item.PUT), die Erweiterung
 * von holder.PUT (Name, E-Mail, Mobilfunk) und das Import-Protokoll (import-runs.GET).
 *
 *  1. Inventar: manuelle Assets UND Geräte in einer Liste; Kosten nur mit Controlling; Kategorien dabei.
 *  2. items.POST: Tenant aus dem Aufruf (nicht aus der UI-Auswahl), Träger → „Zugeordnet" + Zuordnung,
 *     Sammelanlage ohne Seriennummer, fremder Träger abgelehnt, Member → ACCESS_DENIED.
 *  3. item.PUT: nur übergebene Felder ändern sich; Ausmustern löst die Zuordnung (#941); holder_id + status
 *     zusammen abgelehnt; AfA ohne Controlling abgelehnt.
 *  4. holder.PUT: Anzeigename/E-Mail; gesetzte Rufnummer ist vor Entra geschützt; ungültige E-Mail ändert nichts.
 *  5. import-runs.GET: Läufe mit Kennzahlen; ohne Controlling nur der Verzeichnis-Abgleich.
 *
 * Aufruf: php tests/local/mcp-inventory-tools.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\TeamRole;
use Platform\AssetManager\Tools;
use Platform\Core\Contracts\ToolContext;

const TEAM_A  = 7;   // Owner
const TEAM_B  = 9;   // nur Member
const TENANT  = 1;
const TENANT2 = 2;   // zweiter Tenant von TEAM_A

// ---- Schema aus den $fillable aller Modelle ------------------------------------------------
$extra = ['team_id', 'tenant_id', 'sort_order', 'is_active', 'code', 'name', 'user_id', 'status', 'returned_at',
    'phone_overridden', 'controlling_enabled', 'is_default', 'selected_tenant_id', 'holder_type', 'holder_type_pinned'];
foreach (glob(__DIR__ . '/../../src/Models/*.php') as $file) {
    $class = 'Platform' . chr(92) . 'AssetManager' . chr(92) . 'Models' . chr(92) . basename($file, '.php');
    if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
        continue;
    }
    $m = new $class();
    if (Schema::hasTable($m->getTable())) {
        continue;
    }
    Schema::create($m->getTable(), function (Blueprint $t) use ($m, $extra) {
        $t->id();
        foreach (array_unique(array_merge($m->getFillable(), $extra)) as $col) {
            if (! in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $t->text($col)->nullable();
            }
        }
        $t->timestamps();
        $t->softDeletes();
    });
}
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
// Die Liste verlinkt je Zeile auf die Detailseite — die Modul-Routen sind ohne Core-Modultabelle nicht registriert.
Illuminate\Support\Facades\Route::get('/inventory/{type}/{id}', fn () => '')->name('asset-manager.inventory.show');
app('router')->getRoutes()->refreshNameLookups();

// ---- Daten ---------------------------------------------------------------------------------
DB::table('users')->insert(['id' => 1, 'name' => 'Max']);
DB::table('asset_tenants')->insert([
    ['id' => TENANT, 'team_id' => TEAM_A, 'name' => 'Broich', 'is_default' => 1],
    ['id' => TENANT2, 'team_id' => TEAM_A, 'name' => 'Zweit', 'is_default' => 0],
    ['id' => 3, 'team_id' => TEAM_B, 'name' => 'Fremd', 'is_default' => 1],
]);
DB::table('asset_team_settings')->insert([
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'controlling_enabled' => 1],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT2, 'controlling_enabled' => 0],
]);
// Die UI-Auswahl des Users steht auf dem ZWEITEN Tenant — items.POST mit tenant_id=1 darf dort nicht landen.
DB::table('asset_tenant_selections')->insert(['user_id' => 1, 'team_id' => TEAM_A, 'selected_tenant_id' => TENANT2]);
DB::table('asset_categories')->insert([['id' => 1, 'name' => 'Monitor', 'sort_order' => 1], ['id' => 2, 'name' => 'Dock', 'sort_order' => 2]]);
$anna  = DB::table('asset_holders')->insertGetId(['team_id' => TEAM_A, 'tenant_id' => TENANT, 'user_principal_name' => 'anna@broich.de', 'display_name' => 'ANNA PARADZE', 'is_active' => 1, 'holder_type' => 'person']);
$ben   = DB::table('asset_holders')->insertGetId(['team_id' => TEAM_A, 'tenant_id' => TENANT, 'user_principal_name' => 'ben@broich.de', 'display_name' => 'Ben', 'is_active' => 1, 'holder_type' => 'person']);
$alien = DB::table('asset_holders')->insertGetId(['team_id' => TEAM_B, 'tenant_id' => 3, 'user_principal_name' => 'x@fremd.de']);
DB::table('asset_devices')->insert(['team_id' => TEAM_A, 'tenant_id' => TENANT, 'device_name' => 'NB-01', 'serial_number' => 'PF1', 'user_principal_name' => 'anna@broich.de', 'monthly_cost' => 21.36]);

// ---- Kontext (wie mcp-tools-smoke.php): Owner in TEAM_A, Member in TEAM_B ---------------------
$user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
    public int $id = 1;
    public ?int $current_team_id = TEAM_A;
    public string $name = 'Max';
    public function teams(): object
    {
        return new class {
            private ?int $team = null;
            public function where($column, $value = null): static { $this->team = (int) $value; return $this; }
            public function first(): ?object
            {
                $role = [TEAM_A => 'owner', TEAM_B => 'member'][$this->team] ?? null;
                return $role ? (object) ['pivot' => (object) ['role' => $role]] : null;
            }
        };
    }
    public function __get($name) { return $name === 'currentTeam' ? (object) ['id' => $this->current_team_id] : null; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): int { return 1; }
    public function getAuthPassword(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getRememberToken(): string { return ''; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return 'remember_token'; }
};
$ctxA = new ToolContext($user, (object) ['id' => TEAM_A]);
$ctxB = new ToolContext($user, (object) ['id' => TEAM_B]);

function run(string $class, array $args, ToolContext $ctx): array
{
    TenantContext::clearOverride();
    ControllingContext::forget();
    TeamRole::flushResolved();
    $result = (new $class())->execute($args, $ctx);

    return json_decode(json_encode($result->toArray()), true);
}
function ok(array $r): bool { return ($r['ok'] ?? false) === true; }
function code(array $r): ?string { return $r['error']['code'] ?? null; }
function data(array $r): array { return (array) ($r['data'] ?? []); }
function item(int $id): object { return DB::table('asset_items')->find($id); }

// ---- 2. items.POST -------------------------------------------------------------------------------
$r = run(Tools\Inventory\CreateItemTool::class, ['tenant_id' => TENANT, 'category_id' => 1, 'name' => 'Dell U2723', 'manufacturer' => 'Dell',
    'serial_number' => 'MON-1', 'holder_id' => $anna, 'purchase_price' => 360, 'depreciation_months' => 36], $ctxA);
check('items.POST: angelegt', true, ok($r));
$monitor = (int) (data($r)['ids'][0] ?? 0);
check('items.POST: Tenant aus dem Aufruf, nicht aus der UI-Auswahl', TENANT, (int) item($monitor)->tenant_id);
check('items.POST: mit Träger → Status assigned', ['assigned', (string) $anna], [item($monitor)->status, (string) item($monitor)->assignee_id]);
check('items.POST: Zuordnung geschrieben', 1, DB::table('asset_assignments')->where('asset_item_id', $monitor)->whereNull('returned_at')->count());
check('items.POST: AfA mit Controlling gespeichert', 10.0, (float) (data($r)['item']['monthly_cost'] ?? -1));

$r = run(Tools\Inventory\CreateItemTool::class, ['tenant_id' => TENANT, 'category_id' => 2, 'name' => 'Dock WD19', 'quantity' => 3], $ctxA);
check('items.POST: Sammelanlage 3 Stück', 3, (int) (data($r)['created'] ?? 0));
$dock = (int) data($r)['ids'][0];
$r = run(Tools\Inventory\CreateItemTool::class, ['tenant_id' => TENANT, 'category_id' => 2, 'name' => 'Dock', 'quantity' => 2, 'serial_number' => 'X'], $ctxA);
check('items.POST: Seriennummer bei mehreren Stücken abgelehnt', 'VALIDATION_ERROR', code($r));
$r = run(Tools\Inventory\CreateItemTool::class, ['tenant_id' => TENANT, 'category_id' => 1, 'name' => 'X', 'holder_id' => $alien], $ctxA);
check('items.POST: Träger eines fremden Teams abgelehnt', 'VALIDATION_ERROR', code($r));
$r = run(Tools\Inventory\CreateItemTool::class, ['tenant_id' => TENANT, 'category_id' => 99, 'name' => 'X'], $ctxA);
check('items.POST: unbekannte Kategorie abgelehnt', 'VALIDATION_ERROR', code($r));
$r = run(Tools\Inventory\CreateItemTool::class, ['category_id' => 1, 'name' => 'X'], $ctxB);
check('items.POST als Member: ACCESS_DENIED', 'ACCESS_DENIED', code($r));
$r = run(Tools\Inventory\CreateItemTool::class, ['tenant_id' => TENANT2, 'category_id' => 1, 'name' => 'Ohne Controlling', 'purchase_price' => 100, 'depreciation_months' => 12], $ctxA);
check('items.POST ohne Controlling: angelegt, AfA verworfen + Hinweis', [true, null, 1],
    [ok($r), item((int) data($r)['ids'][0])->purchase_price, count(data($r)['warnings'] ?? [])]);
$before = DB::table('asset_items')->count();

// ---- 1. inventory.GET ------------------------------------------------------------------------------
$r = run(Tools\Inventory\ListInventoryTool::class, ['tenant_id' => TENANT], $ctxA);
check('inventory.GET: manuelle Assets + Gerät', [4, 1], [
    count(array_filter(data($r)['inventory'] ?? [], fn ($row) => $row['type'] === 'manual')),
    count(array_filter(data($r)['inventory'] ?? [], fn ($row) => $row['type'] === 'intune')),
]);
check('inventory.GET: nur der eigene Tenant', 5, (int) (data($r)['pagination']['total'] ?? -1));
check('inventory.GET: Kategorien für items.POST', ['Monitor', 'Dock'], array_column(data($r)['categories'] ?? [], 'name'));
check('inventory.GET: Monatskosten mit Controlling', true, array_key_exists('monthly_cost', data($r)['inventory'][0] ?? []));
$r = run(Tools\Inventory\ListInventoryTool::class, ['tenant_id' => TENANT, 'type' => 'manual', 'search' => 'dell'], $ctxA);
check('inventory.GET: Filter type + search', ['Dell U2723'], array_column(data($r)['inventory'] ?? [], 'name'));
$r = run(Tools\Inventory\ListInventoryTool::class, ['tenant_id' => TENANT2], $ctxA);
check('inventory.GET ohne Controlling: keine Kosten', [false, false], [data($r)['controlling_enabled'] ?? null, array_key_exists('monthly_cost', data($r)['inventory'][0] ?? [])]);
$r = run(Tools\Inventory\ListInventoryTool::class, ['tenant_id' => TENANT2, 'sort' => 'monthlyCost'], $ctxA);
check('inventory.GET ohne Controlling: Kosten-Sortierung abgelehnt', 'CONTROLLING_DISABLED', code($r));
$r = run(Tools\Inventory\ListInventoryTool::class, ['tenant_id' => 3], $ctxA);
check('inventory.GET fremder Tenant: NOT_FOUND', 'NOT_FOUND', code($r));

// ---- item.GET ----------------------------------------------------------------------------------------
$r = run(Tools\Inventory\GetItemTool::class, ['tenant_id' => TENANT, 'id' => $monitor], $ctxA);
check('item.GET: Träger + Verlauf', ['ANNA PARADZE', 1], [data($r)['holder'] ?? null, count(data($r)['assignments'] ?? [])]);
$r = run(Tools\Inventory\GetItemTool::class, ['tenant_id' => TENANT, 'id' => 999], $ctxA);
check('item.GET: unbekannt → NOT_FOUND', 'NOT_FOUND', code($r));

// ---- 3. item.PUT -------------------------------------------------------------------------------------
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT, 'id' => $monitor, 'model' => 'U2723QE'], $ctxA);
check('item.PUT: nur das Modell ändert sich', [true, 'U2723QE', 'Dell', 'MON-1', 'assigned'],
    [ok($r), item($monitor)->model, item($monitor)->manufacturer, item($monitor)->serial_number, item($monitor)->status]);
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT, 'id' => $monitor, 'holder_id' => $ben, 'status' => 'assigned'], $ctxA);
check('item.PUT: holder_id + status zusammen abgelehnt', 'VALIDATION_ERROR', code($r));
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT, 'id' => $monitor, 'holder_id' => $ben, 'valid_from' => '2026-10-01'], $ctxA);
check('item.PUT: umgehängt an Ben', [(string) $ben, 'assigned', 1, 2],
    [(string) item($monitor)->assignee_id, item($monitor)->status,
     DB::table('asset_assignments')->where('asset_item_id', $monitor)->whereNull('returned_at')->count(),
     DB::table('asset_assignments')->where('asset_item_id', $monitor)->count()]);
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT, 'id' => $monitor, 'status' => 'retired', 'notes' => 'Panel defekt'], $ctxA);
check('item.PUT: ausgemustert löst die Zuordnung (#941)', ['retired', null, 0, 'Panel defekt'],
    [item($monitor)->status, item($monitor)->assignee_id,
     DB::table('asset_assignments')->where('asset_item_id', $monitor)->whereNull('returned_at')->count(), item($monitor)->notes]);
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT, 'id' => $dock, 'status' => 'assigned'], $ctxA);
check('item.PUT: assigned ohne Träger abgelehnt', 'VALIDATION_ERROR', code($r));
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT, 'id' => $dock, 'depreciation_months' => 24], $ctxA);
check('item.PUT: nur AfA-Monate, Kaufpreis bleibt leer', [true, '24'], [ok($r), (string) item($dock)->depreciation_months]);
$noCtrl = (int) DB::table('asset_items')->where('tenant_id', TENANT2)->value('id');
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT2, 'id' => $noCtrl, 'purchase_price' => 50], $ctxA);
check('item.PUT: AfA ohne Controlling abgelehnt', 'CONTROLLING_DISABLED', code($r));
$r = run(Tools\Inventory\UpdateItemTool::class, ['tenant_id' => TENANT2, 'id' => $monitor, 'name' => 'X'], $ctxA);
check('item.PUT: Asset aus anderem Tenant → NOT_FOUND', 'NOT_FOUND', code($r));
$r = run(Tools\Inventory\UpdateItemTool::class, ['id' => $monitor, 'name' => 'X'], $ctxB);
check('item.PUT als Member: ACCESS_DENIED', 'ACCESS_DENIED', code($r));
check('… und keine Anlage nebenbei', $before, DB::table('asset_items')->count());

// ---- 4. holder.PUT -----------------------------------------------------------------------------------
$r = run(Tools\Holders\UpdateHolderTool::class, ['tenant_id' => TENANT, 'id' => $anna, 'display_name' => 'ANNA PERADZE', 'email' => 'a.peradze@broich.de',
    'mobile_phone' => '+49 173 3185736', 'sim_number' => '8949', 'contract_number' => 'V-1', 'data_volume' => '20 GB'], $ctxA);
$h = DB::table('asset_holders')->find($anna);
check('holder.PUT: Name, E-Mail, Mobilfunk gesetzt', [true, 'ANNA PERADZE', 'a.peradze@broich.de', '+49 173 3185736', '8949', 'V-1', '20 GB'],
    [ok($r), $h->display_name, $h->email, $h->mobile_phone, $h->sim_number, $h->contract_number, $h->data_volume]);
check('holder.PUT: gesetzte Nummer vor Entra geschützt', [true, false], [(bool) $h->phone_overridden, data($r)['phone_from_entra'] ?? null]);
$r = run(Tools\Holders\UpdateHolderTool::class, ['tenant_id' => TENANT, 'id' => $anna, 'phone_from_entra' => true], $ctxA);
check('holder.PUT: phone_from_entra gibt die Nummer frei', false, (bool) DB::table('asset_holders')->find($anna)->phone_overridden);
$r = run(Tools\Holders\UpdateHolderTool::class, ['tenant_id' => TENANT, 'id' => $anna, 'email' => 'kein-mail', 'display_name' => 'FALSCH'], $ctxA);
check('holder.PUT: ungültige E-Mail → Fehler, nichts geändert', ['VALIDATION_ERROR', 'ANNA PERADZE'], [code($r), DB::table('asset_holders')->find($anna)->display_name]);
$r = run(Tools\Holders\UpdateHolderTool::class, ['tenant_id' => TENANT, 'id' => $anna, 'sim_number' => ''], $ctxA);
check('holder.PUT: "" leert', null, DB::table('asset_holders')->find($anna)->sim_number);

// ---- 5. import-runs.GET --------------------------------------------------------------------------------
DB::table('asset_import_runs')->insert([
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'source' => 'directory', 'status' => 'ok', 'dry_run' => 0, 'headline' => '280 Benutzer, 0 stillgelegt',
        'summary' => json_encode(['metrics' => ['Benutzer' => '280', 'Stillgelegt' => '0']]), 'duration_ms' => 502, 'created_at' => '2026-10-26 11:34:00'],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'source' => 'vodafone', 'status' => 'warning', 'dry_run' => 1, 'headline' => '116 Rufnummern · 4.008,52 € netto',
        'summary' => json_encode(['findings' => ['1 Nummer ohne Träger']]), 'duration_ms' => 1100, 'created_at' => '2026-10-20 09:01:00'],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT2, 'source' => 'directory', 'status' => 'ok', 'dry_run' => 0, 'headline' => 'Zweit', 'summary' => null, 'duration_ms' => 10, 'created_at' => '2026-10-26 11:00:00'],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT2, 'source' => 'vodafone', 'status' => 'ok', 'dry_run' => 0, 'headline' => '10 €', 'summary' => null, 'duration_ms' => 10, 'created_at' => '2026-10-26 10:00:00'],
]);
$r = run(Tools\Sync\ListImportRunsTool::class, ['tenant_id' => TENANT], $ctxA);
check('import-runs.GET: beide Läufe des Tenants, neueste zuerst', ['vodafone', 'directory'], array_column(data($r)['runs'] ?? [], 'source'));
check('import-runs.GET: Kennzahlen + Befunde', [['Benutzer' => '280', 'Stillgelegt' => '0'], ['1 Nummer ohne Träger']],
    [data($r)['runs'][1]['metrics'] ?? null, data($r)['runs'][0]['findings'] ?? null]);
$r = run(Tools\Sync\ListImportRunsTool::class, ['tenant_id' => TENANT, 'include_dry_runs' => false], $ctxA);
check('import-runs.GET: ohne Probeläufe', ['directory'], array_column(data($r)['runs'] ?? [], 'source'));
$r = run(Tools\Sync\ListImportRunsTool::class, ['tenant_id' => TENANT2], $ctxA);
check('import-runs.GET ohne Controlling: nur Verzeichnis-Abgleich', ['directory'], array_column(data($r)['runs'] ?? [], 'source'));
$r = run(Tools\Sync\ListImportRunsTool::class, ['tenant_id' => TENANT2, 'source' => 'vodafone'], $ctxA);
check('import-runs.GET ohne Controlling: Rechnungsläufe gesperrt', 'CONTROLLING_DISABLED', code($r));

echo "\n";
check_summary();
