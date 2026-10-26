<?php

/**
 * Query-Budgets gegen ECHTE Aufrufe — Komponente, Tool, Job oder Import selbst, keine nachgebaute Query.
 *
 * Gemessen wird jeder Aufruf zweimal: mit wenigen (5) und mit vielen (40) Zeilen. Die Zahl der Abfragen
 * darf mit der Zeilenzahl nicht wachsen (N+1). Wo ein Schreibpfad je Zeile schreiben MUSS (geänderter
 * Datensatz), ist das Budget „höchstens 1 Query je Zeile" — vorher waren es dort 2 bis 4.
 *
 * Fällt hier ein Fall, ist ein `with()`/Vorladen verloren gegangen oder eine Schleife fragt wieder einzeln.
 * Das Schema entsteht generisch aus den `$fillable` aller Modelle (Text-Spalten, wie die Sync-Tests), damit
 * auch große Komponenten ohne handgepflegtes Schema laufen.
 *
 * Aufruf: php tests/local/query-budgets.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostLineHistoryService;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TelephonySeatImportService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;
use Platform\AssetManager\Support\TeamRole;
use Platform\Core\Contracts\ToolContext;

const TEAM   = 7;
const TENANT = 1;
const SMALL  = 5;
const LARGE  = 40;

// ---- Schema: alle Modul-Tabellen aus den $fillable ------------------------------------------
// Spalten, die Queries anfassen, aber nicht in jedem $fillable stehen.
$extra = ['team_id', 'tenant_id', 'depth', 'sort_order', 'is_active', 'parent_id', 'code', 'name', 'import_hash',
    'graph_id', 'synced_at', 'source', 'user_principal_name', 'display_name', 'holder_type', 'holder_type_pinned',
    'cost_center_id', 'raw_data', 'controlling_enabled', 'is_default', 'aggregation_source', 'user_id', 'status',
    'returned_at', 'deleted_at_marker', 'device_snapshot', 'serial_number', 'intune_id', 'last_sync_date_time'];

$tables = [];
foreach (glob(__DIR__ . '/../../src/Models/*.php') as $file) {
    $class = 'Platform' . chr(92) . 'AssetManager' . chr(92) . 'Models' . chr(92) . basename($file, '.php');
    if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
        continue;
    }
    $m = new $class();
    if (in_array($m->getTable(), $tables, true)) {
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
    $tables[] = $m->getTable();
}
// Die Upserts brauchen ihren Unique-Index wie live.
Schema::table('asset_device_sources', fn (Blueprint $t) => $t->unique(['asset_device_id', 'provider']));
Schema::table('asset_cost_line_history', fn (Blueprint $t) => $t->unique(['tenant_id', 'import_hash', 'invoice']));
Schema::table('asset_telephony_seats', fn (Blueprint $t) => $t->unique(['tenant_id', 'provider', 'external_id']));
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
$tables[] = 'users';

// ---- Rahmen --------------------------------------------------------------------------------
function reset_all(array $tables): void
{
    foreach ($tables as $table) {
        DB::table($table)->delete();
    }
    TenantContext::clearOverride();
    TenantContext::forceTenant(TENANT);
    ControllingContext::forget();
    TeamRole::flushResolved();
    HolderClassifier::forget();
    CostLineHistoryService::forgetTableCheck();

    // Uhr einfrieren: sonst ändert ein Sekundenwechsel zwischen zwei Läufen jeden Zeitstempel, und ein
    // „unveränderter" zweiter Lauf schriebe je Zeile — das Budget hinge am Zufall.
    \Illuminate\Support\Carbon::setTestNow('2026-10-26 10:00:00');

    DB::table('users')->insert(['id' => 1, 'name' => 'Max']);
    DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Broich', 'is_default' => 1]);
    DB::table('asset_team_settings')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'controlling_enabled' => 1]);
}

function count_queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

/**
 * Budget prüfen: `$measure($n)` baut den Bestand mit n Zeilen frisch auf und liefert die Query-Zahl des
 * Aufrufs. Erlaubt ist ein Zuwachs von höchstens `$perRow` Queries je zusätzlicher Zeile.
 */
function budget(string $what, callable $measure, int $perRow = 0): void
{
    try {
        $small = $measure(SMALL);
        $large = $measure(LARGE);
    } catch (\Throwable $e) {
        $GLOBALS['am_checks']['total']++;
        $GLOBALS['am_checks']['failed'][] = $what;
        printf("%-62s FAIL  %s\n", $what, get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300));

        return;
    }

    $GLOBALS['am_checks']['total']++;
    $allowed = $small + $perRow * (LARGE - SMALL);
    $ok      = $large <= $allowed;
    printf("%-62s %s  %d Zeilen=%d, %d Zeilen=%d (max %d)\n", $what, $ok ? 'ok  ' : 'FAIL', SMALL, $small, LARGE, $large, $allowed);
    if (! $ok) {
        $GLOBALS['am_checks']['failed'][] = $what;
    }
}

$ctx = new ToolContext(new GenericUser(['id' => 1, 'name' => 'Max']), (object) ['id' => TEAM]);

/** Komponente mit festem Team — die echte Auflösung hängt am eingeloggten Core-User. */
function component(string $class): object
{
    return eval('return new class extends ' . chr(92) . $class . ' {
        protected function teamId(): int { return ' . TEAM . '; }
        protected function canManage(): bool { return true; }
        protected function controllingEnabled(): bool { return true; }
    };');
}

$costCenters = function (int $n): void {
    $root = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1000', 'name' => 'Root', 'depth' => 0, 'is_active' => 1]);
    for ($i = 1; $i < $n; $i++) {
        $cc = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'parent_id' => $root, 'code' => (string) (2000 + $i), 'name' => "KST {$i}", 'depth' => 1, 'is_active' => 1]);
        DB::table('asset_holders')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => "u{$i}@x.de", 'cost_center_id' => $cc]);
    }
};

// ---- 1. Stammdaten › Kostenstellen (Baum) -----------------------------------------------------
budget('Stammdaten: Kostenstellen-Baum mit Träger-Zahl', function (int $n) use ($tables, $costCenters) {
    reset_all($tables);
    $costCenters($n);
    $c = component(\Platform\AssetManager\Livewire\MasterData\Index::class);

    return count_queries(fn () => $c->costCenters()->each(fn ($node) => $node->holders_count));
});

// ---- 2. MCP cost-centers.GET -------------------------------------------------------------------
budget('MCP cost-centers.GET', function (int $n) use ($tables, $costCenters, $ctx) {
    reset_all($tables);
    $costCenters($n);

    return count_queries(function () use ($ctx) {
        $r = (new \Platform\AssetManager\Tools\MasterData\ListCostCentersTool())->execute(['tenant_id' => TENANT], $ctx);
        if (! $r->success) {
            throw new RuntimeException(json_encode($r->toArray()));
        }
    });
});

// ---- 3. Kostenstelle umhängen: Tiefe der Nachfahren -------------------------------------------
budget('Kostenstelle umhängen: Tiefe des Teilbaums (1 Schreibzugriff/Zeile)', function (int $n) use ($tables) {
    reset_all($tables);
    $root  = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1000', 'depth' => 0]);
    $other = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1100', 'depth' => 0]);
    $moved = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1200', 'parent_id' => $root, 'depth' => 1]);
    for ($i = 0; $i < $n; $i++) {
        DB::table('asset_cost_centers')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => (string) (3000 + $i), 'parent_id' => $moved, 'depth' => 2]);
    }
    DB::table('asset_cost_centers')->where('id', $moved)->update(['parent_id' => $other]);
    DB::table('asset_cost_centers')->where('id', $other)->update(['parent_id' => $root, 'depth' => 1]);

    $c    = component(\Platform\AssetManager\Livewire\MasterData\Index::class);
    $node = Models\AssetCostCenter::find($moved);
    $m    = new ReflectionMethod($c, 'recomputeSubtreeDepths');
    $q    = count_queries(fn () => $m->invoke($c, $node));

    $depths = DB::table('asset_cost_centers')->where('parent_id', $moved)->pluck('depth')->map(fn ($d) => (int) $d)->unique()->values()->all();
    if ($depths !== [3] || (int) DB::table('asset_cost_centers')->where('id', $moved)->value('depth') !== 2) {
        throw new RuntimeException('Tiefe falsch: ' . json_encode($depths));
    }

    return $q;
}, perRow: 1);

// ---- 4. Stammdaten: Reihenfolge per Drag ------------------------------------------------------
budget('Stammdaten: Reihenfolge speichern (zwei Plätze getauscht)', function (int $n) use ($tables) {
    reset_all($tables);
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $ids[] = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => (string) (5000 + $i), 'sort_order' => ($i + 1) * 10]);
    }
    [$ids[0], $ids[1]] = [$ids[1], $ids[0]];
    $c = component(\Platform\AssetManager\Livewire\MasterData\Index::class);
    $c->active = 'cost-centers';
    \Illuminate\Support\Facades\Gate::define('asset-manager.manage', fn () => true);
    auth()->setUser(new GenericUser(['id' => 1]));

    return count_queries(fn () => $c->reorder(array_map(fn ($id) => ['value' => $id], $ids)));
});

// ---- 5. Kosten-Import › Import-Historie --------------------------------------------------------
budget('Kosten-Import: Import-Historie mit Benutzer', function (int $n) use ($tables) {
    reset_all($tables);
    for ($i = 0; $i < min($n, 25); $i++) {
        DB::table('users')->insertOrIgnore(['id' => 100 + $i, 'name' => "User {$i}"]);
        DB::table('asset_import_runs')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'source' => 'vodafone_invoice', 'status' => 'ok', 'user_id' => 100 + $i, 'created_at' => now()]);
    }
    $c = component(\Platform\AssetManager\Livewire\Costs\Import::class);

    return count_queries(fn () => collect($c->render()->getData()['runs'])->each(fn ($run) => $run->user?->name));
});

// ---- 6. Gerätemodelle (Kostenart + Kreditor je Modell) ------------------------------------------
budget('Gerätemodelle: Liste mit Kostenart und Kreditor', function (int $n) use ($tables) {
    reset_all($tables);
    for ($i = 1; $i <= $n; $i++) {
        $type   = DB::table('asset_cost_types')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'name' => "Art {$i}"]);
        $vendor = DB::table('asset_vendors')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'name' => "Kreditor {$i}"]);
        $model  = DB::table('asset_device_models')->insertGetId(['team_id' => TEAM, 'manufacturer' => 'Dell', 'model' => "M{$i}"]);
        DB::table('asset_device_model_costs')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_model_id' => $model, 'cost_type_id' => $type, 'vendor_id' => $vendor, 'monthly_cost' => 10]);
    }
    $c = component(\Platform\AssetManager\Livewire\DeviceModels\Index::class);

    return count_queries(fn () => collect($c->render()->getData()['models'])
        ->each(fn ($m) => [$m->cost?->costType?->name, $m->cost?->vendor?->name]));
});

// ---- 7. MCP holder.GET (Monatskosten je Gerät) -------------------------------------------------
budget('MCP holder.GET: Träger mit Geräten', function (int $n) use ($tables, $ctx) {
    reset_all($tables);
    $holder = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'anna@x.de', 'display_name' => 'Anna']);
    for ($i = 1; $i <= $n; $i++) {
        $model = DB::table('asset_device_models')->insertGetId(['team_id' => TEAM, 'manufacturer' => 'Dell', 'model' => "M{$i}"]);
        DB::table('asset_device_model_costs')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_model_id' => $model, 'monthly_cost' => 10]);
        DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => "D{$i}", 'manufacturer' => 'Dell', 'model' => "M{$i}", 'user_principal_name' => 'anna@x.de']);
    }

    return count_queries(function () use ($ctx, $holder) {
        $r = (new \Platform\AssetManager\Tools\Holders\GetHolderTool())->execute(['id' => $holder, 'tenant_id' => TENANT], $ctx);
        if (! $r->success) {
            throw new RuntimeException(json_encode($r->toArray()));
        }
    });
});

// ---- 7b. MCP inventory.GET (manuelle Assets mit Träger + Geräte) --------------------------------
Illuminate\Support\Facades\Route::get('/inventory/{type}/{id}', fn () => '')->name('asset-manager.inventory.show');
app('router')->getRoutes()->refreshNameLookups();
budget('MCP inventory.GET: Assets mit Träger und Geräte', function (int $n) use ($tables, $ctx) {
    reset_all($tables);
    DB::table('asset_categories')->insert(['id' => 1, 'name' => 'Monitor']);
    for ($i = 1; $i <= $n; $i++) {
        $holder = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => "u{$i}@x.de", 'display_name' => "U {$i}"]);
        DB::table('asset_items')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 1, 'name' => "Monitor {$i}", 'assignee_id' => $holder, 'status' => 'assigned', 'purchase_price' => 300, 'depreciation_months' => 36]);
        DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => "NB-{$i}", 'user_principal_name' => "u{$i}@x.de"]);
    }

    return count_queries(function () use ($ctx) {
        $r = (new \Platform\AssetManager\Tools\Inventory\ListInventoryTool())->execute(['tenant_id' => TENANT, 'limit' => 200], $ctx);
        if (! $r->success) {
            throw new RuntimeException(json_encode($r->toArray()));
        }
    });
});

// ---- 8. Übergabeprotokolle: Gerät je Zeile (Snapshot ohne Name) ---------------------------------
budget('Übergabeprotokolle: Liste, Gerät je Position', function (int $n) use ($tables) {
    reset_all($tables);
    $holder = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'anna@x.de']);
    for ($i = 1; $i <= min($n, 25); $i++) {
        $device   = DB::table('asset_devices')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => "D{$i}"]);
        $handover = DB::table('asset_handovers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $holder, 'status' => 'open', 'issued_at' => '2026-10-01']);
        DB::table('asset_handover_lines')->insert(['tenant_id' => TENANT, 'handover_id' => $handover, 'asset_device_id' => $device, 'device_snapshot' => json_encode([])]);
    }
    $c = component(\Platform\AssetManager\Livewire\Handovers\Index::class);

    return count_queries(fn () => collect($c->render()->getData()['handovers']->items())
        ->each(fn ($h) => [$h->holder?->display_name, $h->lines->map(fn ($l) => $l->deviceName())->all()]));
});

// ---- 8b. Inventar-Detail eines Geräts: Protokoll-Zeilen mit Träger ------------------------------
// `handover.holder` und NICHT `handover.employee` — der alte Relationsname warf erst, sobald das Gerät
// Protokoll-Zeilen hatte. Der echte render() deckt beides ab: Fehler und Query-Zahl.
budget('Inventar-Detail: Gerät mit Übergabe-Positionen', function (int $n) use ($tables) {
    reset_all($tables);
    $device = DB::table('asset_devices')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'NB-1', 'serial_number' => 'SN1']);
    for ($i = 1; $i <= $n; $i++) {
        $holder   = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => "u{$i}@x.de"]);
        $handover = DB::table('asset_handovers')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $holder, 'status' => 'closed', 'issued_at' => '2026-10-01']);
        DB::table('asset_handover_lines')->insert(['tenant_id' => TENANT, 'handover_id' => $handover, 'asset_device_id' => $device, 'returned_at' => '2026-10-02']);
    }
    $c = component(\Platform\AssetManager\Livewire\Inventory\Show::class);
    $c->device = Models\AssetDevice::find($device);

    return count_queries(fn () => collect($c->render()->getData()['handoverLines'])
        ->each(fn ($l) => $l->handover?->holder?->user_principal_name));
});

// ---- 9.–11. Sync-Jobs: zweiter Lauf ohne Änderungen --------------------------------------------
$config = fn () => Models\AssetConnectorConfig::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'enabled' => true, 'azure_tenant_id' => 'tenant-guid',
    'client_id' => 'client', 'client_secret' => 'secret', 'consent_confirmed_at' => now(),
]);
$graph = new class extends IntuneGraphService {
    public array $users = [];
    public array $devices = [];
    public array $skus = [['skuId' => 'E3', 'skuPartNumber' => 'ENTERPRISEPACK', 'consumedUnits' => 1, 'prepaidUnits' => ['enabled' => 99], 'capabilityStatus' => 'Enabled']];
    public function getUsersWithLicenses($config): ?array { return $this->users; }
    public function getSubscribedSkus($config): ?array { return $this->skus; }
    public function getManagedDevices($config): ?array { return $this->devices; }
};
$users = fn (int $n) => array_map(fn ($i) => [
    'id' => "entra-{$i}", 'userPrincipalName' => "user{$i}@broich.de", 'displayName' => "User {$i}", 'accountEnabled' => true,
    'department' => 'IT', 'assignedLicenses' => [['skuId' => 'E3']],
], range(1, $n));

budget('Verzeichnis-Import: zweiter Lauf ohne Änderungen', function (int $n) use ($tables, $config, $graph, $users) {
    reset_all($tables);
    $cfg = $config();
    $graph->users = $users($n);
    $run = fn () => (new ImportTenantUsersJob($cfg->id))->handle($graph, app(HolderService::class));
    $run();
    if (Models\AssetHolder::withoutTenantScope()->count() !== $n) {
        throw new RuntimeException('Träger nicht angelegt');
    }

    return count_queries($run);
});

budget('Lizenz-Sync: zweiter Lauf ohne Änderungen', function (int $n) use ($tables, $config, $graph, $users) {
    reset_all($tables);
    $cfg = $config();
    $graph->users = $users($n);
    $run = function () use ($cfg, $graph) {
        (new SyncLicensesJob($cfg->id))->handle($graph, app(HolderService::class));
        TenantContext::forceTenant(TENANT);
    };
    $run();
    if (Models\AssetUserLicense::withoutTenantScope()->count() !== $n) {
        throw new RuntimeException('Zuweisungen nicht angelegt: ' . Models\AssetUserLicense::withoutTenantScope()->count());
    }

    return count_queries($run);
});

budget('Geräte-Sync: zweiter Lauf ohne Änderungen', function (int $n) use ($tables, $config, $graph) {
    reset_all($tables);
    $cfg = $config();
    $graph->devices = array_map(fn ($i) => [
        'id' => "intune-{$i}", 'deviceName' => "NB-{$i}", 'serialNumber' => "SN{$i}", 'userPrincipalName' => "user{$i}@broich.de",
        'userDisplayName' => "User {$i}", 'manufacturer' => 'Dell', 'model' => 'Latitude', 'operatingSystem' => 'Windows',
        'lastSyncDateTime' => '2026-10-20T08:00:00Z', 'complianceState' => 'compliant',
    ], range(1, $n));
    $run = function () use ($cfg, $graph) {
        (new SyncIntuneDevicesJob($cfg->id))->handle($graph, app(HolderService::class));
        TenantContext::forceTenant(TENANT);
    };
    $run();
    if (Models\AssetDeviceSource::count() !== $n) {
        throw new RuntimeException('Quellen nicht angelegt: ' . Models\AssetDeviceSource::count());
    }

    return count_queries($run);
});

// ---- 12. Vodafone-Mobilfunk-Import: dieselbe Rechnung erneut --------------------------------------
final class BudgetRowsVodafoneImport extends VodafoneInvoiceImportService
{
    public function __construct(private array $rows) { parent::__construct(); }
    protected function readRows(string $path): array { return $this->rows; }
}
budget('Vodafone-Import: erneuter Lauf (höchstens 1 Schreibzugriff/Nummer)', function (int $n) use ($tables) {
    reset_all($tables);
    DB::table('asset_cost_centers')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '1520', 'name' => 'IT']);
    DB::table('asset_cost_types')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'aggregation_source' => 'cost_line']);
    $base = ['B' => '86839593', 'C' => '1225', 'D' => '30.09.2026', 'E' => '01.09.2026', 'F' => '30.09.2026'];
    $rows = [['A' => 'Rechnung'] + $base + ['J' => 10.0 * $n]];
    for ($i = 1; $i <= $n; $i++) {
        $msisdn = '49173' . str_pad((string) (3185700 + $i), 7, '0', STR_PAD_LEFT);
        DB::table('asset_holders')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => "u{$i}@x.de", 'mobile_phone' => '+' . $msisdn]);
        $rows[] = ['A' => 'Rufnummer'] + $base + ['J' => 10.0, 'K' => $msisdn, 'M' => '1520'];
    }
    (new BudgetRowsVodafoneImport($rows))->import(TEAM, 'egal', 'lauf-1', false, TENANT);
    $q = count_queries(fn () => (new BudgetRowsVodafoneImport($rows))->import(TEAM, 'egal', 'lauf-2', false, TENANT));
    if (Models\AssetCostLine::withoutTenantScope()->count() !== $n || Models\AssetCostLineHistory::withoutTenantScope()->count() !== $n) {
        throw new RuntimeException('Zeilen/Historie falsch');
    }

    return $q;
}, perRow: 1);

// ---- 13. Preis-Historie schreiben -----------------------------------------------------------------
budget('Preis-Historie: Rechnung nachtragen', function (int $n) use ($tables) {
    reset_all($tables);
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        DB::table('asset_cost_lines')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'import_hash' => "h{$i}", 'label' => "L{$i}", 'amount' => 1]);
        $rows[] = ['cost_line_id' => null, 'import_hash' => "h{$i}", 'source' => 'vodafone_invoice', 'account' => 'K1', 'invoice' => 'R-1',
            'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'booking_month' => '2026-09-01', 'label' => "L{$i}", 'amount' => 1.5,
            'cost_type_id' => null, 'cost_center_id' => null, 'assignee_id' => null];
    }
    $svc = app(CostLineHistoryService::class);
    $q   = count_queries(fn () => $svc->store(TEAM, TENANT, $rows, resolveLineIds: true));
    $svc->store(TEAM, TENANT, $rows, resolveLineIds: true); // derselbe Lauf erneut: überschreibt
    if (Models\AssetCostLineHistory::withoutTenantScope()->count() !== $n
        || Models\AssetCostLineHistory::withoutTenantScope()->whereNull('cost_line_id')->exists()) {
        throw new RuntimeException('Historie falsch');
    }

    return $q;
});

// ---- 14. RingCentral-Benutzerliste: erneuter Import -------------------------------------------------
budget('Telefonie-Plätze: erneuter Import ohne Änderungen', function (int $n) use ($tables) {
    reset_all($tables);
    $users = [];
    for ($i = 1; $i <= $n; $i++) {
        DB::table('asset_holders')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => "u{$i}@x.de", 'email' => "u{$i}@x.de", 'display_name' => "User {$i}"]);
        $users[] = ['external_id' => (string) (2195505000 + $i), 'email' => "u{$i}@x.de", 'name' => "User {$i}", 'status' => 'Aktiviert',
            'extension' => (string) (100 + $i), 'direct_number' => '', 'device' => 'Snom', 'raw' => []];
    }
    $svc = new class($users) extends TelephonySeatImportService {
        public function __construct(private array $rows) { parent::__construct(); }
        protected function readUsers(string $path): array { return $this->rows; }
    };
    $svc->import(TEAM, 'egal', false, TENANT);
    $q = count_queries(fn () => $svc->import(TEAM, 'egal', false, TENANT));
    if (Models\AssetTelephonySeat::count() !== $n) {
        throw new RuntimeException('Plätze falsch: ' . Models\AssetTelephonySeat::count());
    }

    return $q;
});

echo "\n";
check_summary();
