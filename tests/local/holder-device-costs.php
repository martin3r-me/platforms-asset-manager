<?php

/**
 * Träger-Detail (#977): Gerätekosten nur für die Geräte des Trägers.
 *
 * `Holders/Show::deviceCostRows()` ersetzt auf der Detailseite den Aufruf von
 * `CostAggregationService::deviceCostRows($teamId)`, der bei jeder Modal-Interaktion den ganzen
 * Gerätebestand des Teams lud. Die Regel muss dieselbe bleiben — sonst zeigt die Detailseite andere
 * Gerätekosten als Dashboard und Kostenaufteilung:
 *
 *  1. Je Gerät derselbe Betrag wie im Aggregator (Override, Modell-Default, Kostenart-Gate, 0 € raus).
 *  2. Der Träger-Total über `holderCost()` bleibt gleich, egal welcher Weg die Zeilen liefert.
 *  3. Die Abfragen hängen nicht von der Größe des Gerätebestands ab.
 *
 * Aufruf: php tests/local/holder-device-costs.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Livewire\Holders\Show;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('device_name')->nullable(); $t->string('manufacturer')->nullable(); $t->string('model')->nullable();
    $t->string('user_principal_name')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable(); $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->decimal('monthly_cost', 10, 2)->nullable(); $t->decimal('purchase_price', 10, 2)->nullable(); $t->integer('depreciation_months')->nullable();
    $t->date('purchase_date')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->string('display_name')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key')->nullable();
    $t->string('aggregation_source')->default('cost_line'); $t->timestamps(); $t->softDeletes();
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
// holderCost() liest zusätzlich Items, Lizenzen und Kostenpositionen — leer, aber vorhanden.
Schema::create('asset_items', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('category_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable(); $t->decimal('purchase_price', 12, 2)->nullable(); $t->integer('depreciation_months')->nullable();
    $t->date('purchase_date')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_categories', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('asset_item_id')->nullable();
    $t->boolean('active')->default(true); $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('sku_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->decimal('unit_price', 10, 2)->nullable(); $t->unsignedBigInteger('contract_line_id')->nullable();
    $t->timestamps();
});
Schema::create('asset_license_skus', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('sku_id');
    $t->decimal('unit_price', 10, 2)->nullable(); $t->timestamps();
});
Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('period_label')->nullable(); $t->timestamps();
});

Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(false); $t->json('holder_type_rules')->nullable(); $t->timestamps();
});

const TEAM   = 7;
const TENANT = 1;
TenantContext::forceTenant(TENANT);

DB::table('asset_holders')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'anna@k.de', 'display_name' => 'Anna'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'bernd@k.de', 'display_name' => 'Bernd'],
]);
DB::table('asset_cost_types')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'lap', 'aggregation_source' => 'asset_device'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'other', 'aggregation_source' => 'cost_line'],
]);
DB::table('asset_device_models')->insert([
    ['id' => 1, 'team_id' => TEAM, 'manufacturer' => 'Dell', 'model' => 'Latitude', 'depreciation_months' => 36],
    ['id' => 2, 'team_id' => TEAM, 'manufacturer' => 'HP', 'model' => 'Elite', 'depreciation_months' => 36],
]);
DB::table('asset_device_model_costs')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'device_model_id' => 1, 'cost_type_id' => 1, 'monthly_cost' => 21.36],
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'device_model_id' => 2, 'cost_type_id' => 2, 'monthly_cost' => 15.00], // Kostenart ohne Geräte-Gate
]);
DB::table('asset_devices')->insert([
    // Anna: Modell-Default, Override, falsches Kostenart-Gate, 0 €
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'A1', 'manufacturer' => 'Dell', 'model' => 'Latitude', 'user_principal_name' => 'anna@k.de', 'cost_type_id' => null, 'monthly_cost' => null],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'A2', 'manufacturer' => 'Dell', 'model' => 'Latitude', 'user_principal_name' => 'anna@k.de', 'cost_type_id' => 1, 'monthly_cost' => 30.00],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'A3', 'manufacturer' => 'HP', 'model' => 'Elite', 'user_principal_name' => 'anna@k.de', 'cost_type_id' => null, 'monthly_cost' => null],
    ['id' => 4, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'A4', 'manufacturer' => 'Acer', 'model' => 'X', 'user_principal_name' => 'anna@k.de', 'cost_type_id' => 1, 'monthly_cost' => 0],
    // Bernd
    ['id' => 5, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'B1', 'manufacturer' => 'Dell', 'model' => 'Latitude', 'user_principal_name' => 'bernd@k.de', 'cost_type_id' => null, 'monthly_cost' => null],
]);

$show = new Show();
$rowsFor = function (AssetHolder $holder) use ($show) {
    $devices = AssetDevice::where('team_id', TEAM)->where('user_principal_name', $holder->user_principal_name)->get();
    $m = new ReflectionMethod($show, 'deviceCostRows');
    $m->setAccessible(true);

    return $m->invoke($show, TEAM, $devices);
};
$amounts = fn ($rows) => $rows->map(fn ($r) => round((float) $r['amount'], 2))->sortKeys()->all();

$anna = AssetHolder::find(1);
$agg  = app(CostAggregationService::class)->deviceCostRows(TEAM)->keyBy('device_id');

$own = $rowsFor($anna);
check('Anna: dieselben Geräte wie im Aggregator', $amounts($agg->only([1, 2, 3, 4])), $amounts($own));
check('Anna: Modell-Default + Override, Gate und 0 € raus', [1 => 21.36, 2 => 30.0], $amounts($own));
check('Anna: Kostenart je Zeile wie im Aggregator', $agg->only([1, 2])->pluck('cost_type_id', 'device_id')->all(), $own->pluck('cost_type_id', 'device_id')->all());

$svc = app(CostAggregationService::class);
check('Anna: Träger-Total gleich, egal woher die Zeilen kommen',
    $svc->holderCost(TEAM, $anna, $agg)['device'],
    $svc->holderCost(TEAM, $anna, $own)['device']);
check('Bernd: nur sein Gerät', [5 => 21.36], $amounts($rowsFor(AssetHolder::find(2))));
DB::flushQueryLog();
DB::enableQueryLog();
$m = new ReflectionMethod($show, 'deviceCostRows');
$m->setAccessible(true);
$empty = $m->invoke($show, TEAM, collect());
check('Ohne Geräte: leer und ohne Abfrage', [0, 0], [$empty->count(), count(DB::getQueryLog())]);
DB::disableQueryLog();

// Abfragen hängen nicht vom Team-Bestand ab: 200 fremde Geräte ändern nichts.
$count = function () use ($anna) {
    $devices = AssetDevice::where('team_id', TEAM)->where('user_principal_name', $anna->user_principal_name)->get();
    DB::flushQueryLog(); DB::enableQueryLog();
    $m = new ReflectionMethod(Show::class, 'deviceCostRows'); $m->setAccessible(true);
    $m->invoke(new Show(), TEAM, $devices);
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
};
$before = $count();
for ($i = 0; $i < 200; $i++) {
    DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => "X{$i}", 'manufacturer' => 'Dell', 'model' => 'Latitude', 'user_principal_name' => "x{$i}@k.de"]);
}
check('Abfragen unabhängig vom Gerätebestand des Teams', $before, $count());
check('Abfragen: Kostenarten + Resolver (3)', 3, $before);

check_summary();
