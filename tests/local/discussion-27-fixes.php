<?php

/**
 * Korrekturen aus Discussion #27 (Datenbefunde Testlauf 2026-10-20):
 *
 *  1. Träger-Detail „pro Monat" rechnet die zugeordneten Kostenpositionen mit — wie die Auswertung je Person
 *     (`topHolders`). Eine Position auf einer Kostenart, die aus Geräten rechnet (Lap+Dock), zählt NICHT:
 *     sonst stünde sie doppelt neben dem Gerät. Einzel- und Sammelrechnung liefern dieselbe Zahl.
 *  2. Kostenlose Microsoft-SKUs (FLOW_FREE …) gelten als „hat Preis" — kein „Preis fehlt".
 *  3. Migration: die doppelte Kategorie „Drucker" (Schlüssel `drucker`) wird in `printer` überführt.
 *
 * Aufruf: php tests/local/discussion-27-fixes.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\TenantContext;

const TEAM   = 7;
const TENANT = 1;

// ---- Schema aus den $fillable aller Modelle ------------------------------------------------
$extra = ['team_id', 'tenant_id', 'is_active', 'active', 'valid_from', 'valid_to', 'monthly_amount', 'aggregation_source',
    'assignee_id', 'cost_type_id', 'key', 'name', 'sort_order', 'is_default', 'controlling_enabled', 'category_id'];
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

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Broich', 'is_default' => 1]);
TenantContext::forceTenant(TENANT);

// ---- 1. Kostenpositionen im Träger-Detail ---------------------------------------------------
$mobil = DB::table('asset_cost_types')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'aggregation_source' => AssetCostType::SOURCE_COST_LINE]);
$lapDock = DB::table('asset_cost_types')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'lap_dock', 'name' => 'Lap+Dock', 'aggregation_source' => AssetCostType::SOURCE_ASSET_DEVICE]);
$winters = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'c.winters@broich.de', 'display_name' => 'CHRISTINA WINTERS', 'is_active' => 1]);
$ben     = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'ben@broich.de', 'display_name' => 'Ben', 'is_active' => 1]);
$line = fn (array $v) => DB::table('asset_cost_lines')->insert($v + ['team_id' => TEAM, 'tenant_id' => TENANT, 'active' => 1, 'frequency' => 'monthly', 'label' => 'x']);
$line(['assignee_id' => $winters, 'cost_type_id' => $mobil, 'amount' => 104, 'monthly_amount' => 104]);
$line(['assignee_id' => $winters, 'cost_type_id' => $lapDock, 'amount' => 64.08, 'monthly_amount' => 21.36]);       // zählt nicht (Geräte-Quelle)
$line(['assignee_id' => $winters, 'cost_type_id' => $mobil, 'amount' => 5, 'monthly_amount' => 5, 'active' => 0]);   // inaktiv
$line(['assignee_id' => $winters, 'cost_type_id' => $mobil, 'amount' => 7, 'monthly_amount' => 7, 'valid_to' => '2020-01-31']); // abgelaufen
$line(['assignee_id' => $ben, 'cost_type_id' => $mobil, 'amount' => 21, 'monthly_amount' => 21]);
$line(['assignee_id' => null, 'cost_type_id' => $mobil, 'amount' => 50, 'monthly_amount' => 50]);                   // ohne Träger

$agg  = app(CostAggregationService::class);
$cost = $agg->holderCost(TEAM, AssetHolder::find($winters));
check('Träger-Detail: Kostenposition Mobilfunk zählt mit', 104.0, $cost['costlines']);
check('Träger-Detail: „pro Monat" enthält sie', 104.0, $cost['total']);
check('Ben: nur seine eigene Position', 21.0, $agg->holderCost(TEAM, AssetHolder::find($ben))['costlines']);

$batch = $agg->holderCostsFor(TEAM, AssetHolder::where('team_id', TEAM)->get());
check('Sammelrechnung (Liste/Export) = Einzelrechnung', [$cost, $agg->holderCost(TEAM, AssetHolder::find($ben))], [$batch[$winters], $batch[$ben]]);

$top = $agg->topHolders(TEAM)->keyBy(fn ($r) => $r['holder']->id);
check('gleiche Zahl wie die Auswertung je Person', (float) $top[$winters]['total'], $cost['total']);

// ---- 2. Kostenlose SKUs -----------------------------------------------------------------------
check('FLOW_FREE ist kostenlos', true, (new AssetLicenseSku(['sku_part_number' => 'FLOW_FREE']))->isFree());
check('POWER_BI_STANDARD ist kostenlos', true, (new AssetLicenseSku(['sku_part_number' => 'power_bi_standard']))->isFree());
check('POWERAPPS_DEV ist kostenlos', true, (new AssetLicenseSku(['sku_part_number' => 'POWERAPPS_DEV']))->isFree());
check('Business Standard ist nicht kostenlos', false, (new AssetLicenseSku(['sku_part_number' => 'O365_BUSINESS_PREMIUM']))->isFree());

// ---- 3. Migration: doppelte Kategorie „Drucker" -------------------------------------------------
$migration = require __DIR__ . '/../../database/migrations/2026_10_27_000001_merge_duplicate_printer_category.php';

DB::table('asset_categories')->insert([
    ['id' => 11, 'key' => 'printer', 'name' => 'Drucker'],
    ['id' => 18, 'key' => 'drucker', 'name' => 'Drucker'],
]);
DB::table('asset_items')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 18, 'name' => 'Kyocera A', 'deleted_at' => null],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 18, 'name' => 'Kyocera B', 'deleted_at' => now()],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 11, 'name' => 'Brother', 'deleted_at' => null],
]);
$migration->up();
check('Migration: alle Drucker an der Grunddaten-Kategorie (auch gelöschte)', ['11', '11', '11'],
    DB::table('asset_items')->orderBy('id')->pluck('category_id')->map(fn ($v) => (string) $v)->all());
check('Migration: Dublette entfernt', ['printer'], DB::table('asset_categories')->where('name', 'Drucker')->pluck('key')->all());
$migration->up();
check('Migration: zweiter Lauf ändert nichts', 1, DB::table('asset_categories')->where('name', 'Drucker')->count());

DB::table('asset_categories')->delete();
DB::table('asset_categories')->insert(['id' => 18, 'key' => 'drucker', 'name' => 'Drucker']);
$migration->up();
check('Migration ohne Grunddaten-Kategorie: Schlüssel umgestellt', ['18' => 'printer'],
    DB::table('asset_categories')->pluck('key', 'id')->mapWithKeys(fn ($k, $id) => [(string) $id => $k])->all());

echo "\n";
check_summary();
