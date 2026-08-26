<?php

/**
 * Die vier N+1-/Eager-Loading-Stellen aus dem Projekt-Audit — gegen den AKTUELLEN Code, nicht
 * gegen nachgebaute Muster.
 *
 * Die Query-Zahlen sind Obergrenzen: sie duerfen sinken, aber ein Anstieg heisst, dass ein
 * Eager-Load oder ein vorgeladener Resolver wieder verloren gegangen ist. Zusaetzlich wird
 * geprueft, dass AssetDevice::deviceModel() nach dem Umbau auf den DeviceCostResolver dieselbe
 * Zuordnung trifft wie vorher — die Kosten haengen daran.
 *
 * Aufruf: php tests/local/n-plus-1.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetHandoverLine;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\DeviceCostResolver;

TenantContext::forceTenant(1);

// --- Minimalschema: nur die Spalten, die die Queries anfassen -------------
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_handovers', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('holder_id')->nullable();
    $t->string('status')->nullable();
    $t->date('issued_at')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_handover_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('handover_id');
    $t->unsignedBigInteger('asset_device_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->json('device_snapshot')->nullable();
    $t->string('status')->nullable();
    $t->timestamp('returned_at')->nullable();
    $t->timestamps();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('device_name')->nullable();
    $t->string('manufacturer')->nullable();
    $t->string('model')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->decimal('monthly_cost', 10, 2)->nullable();
    $t->decimal('purchase_price', 10, 2)->nullable();
    $t->integer('depreciation_months')->nullable();
    $t->date('purchase_date')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_device_models', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->string('manufacturer')->nullable();
    $t->string('model')->nullable();
    $t->integer('depreciation_months')->nullable();
    $t->timestamps();
});
Schema::create('asset_device_model_costs', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('device_model_id');
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->unsignedBigInteger('vendor_id')->nullable();
    $t->decimal('monthly_cost', 10, 2)->nullable();
    $t->decimal('purchase_price', 10, 2)->nullable();
    $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('name')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('name')->nullable();
    $t->timestamps();
    $t->softDeletes();
});

// --- Daten ---------------------------------------------------------------
DB::table('asset_holders')->insert(['id' => 1, 'team_id' => 1, 'tenant_id' => 1, 'user_principal_name' => 'a@b.c']);
DB::table('asset_handovers')->insert(['id' => 1, 'team_id' => 1, 'tenant_id' => 1, 'holder_id' => 1, 'status' => 'open']);
DB::table('asset_handover_lines')->insert(['id' => 1, 'handover_id' => 1, 'asset_device_id' => 1, 'tenant_id' => 1]);

$N = 20;
for ($i = 1; $i <= $N; $i++) {
    DB::table('asset_cost_types')->insert(['id' => $i, 'team_id' => 1, 'tenant_id' => 1, 'name' => "Art {$i}"]);
    DB::table('asset_vendors')->insert(['id' => $i, 'team_id' => 1, 'tenant_id' => 1, 'name' => "Kreditor {$i}"]);
    DB::table('asset_device_models')->insert(['id' => $i, 'team_id' => 1, 'manufacturer' => 'Dell', 'model' => "M{$i}", 'depreciation_months' => 36]);
    DB::table('asset_device_model_costs')->insert(['team_id' => 1, 'tenant_id' => 1, 'device_model_id' => $i, 'cost_type_id' => $i, 'vendor_id' => $i, 'monthly_cost' => 10]);
    DB::table('asset_devices')->insert(['id' => $i, 'team_id' => 1, 'tenant_id' => 1, 'device_name' => "D{$i}", 'manufacturer' => 'Dell', 'model' => "M{$i}", 'user_principal_name' => 'a@b.c']);
}

/** Queries einer Closure zaehlen. */
function queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

/** Obergrenze pruefen (Gegenstueck zu check() aus dem Bootstrap). */
function at_most(string $what, int $max, int $actual): void
{
    $GLOBALS['am_checks']['total']++;
    $ok = $actual <= $max;

    printf("%-58s %s  max=%d ist=%d\n", $what, $ok ? 'ok  ' : 'FAIL', $max, $actual);

    if (! $ok) {
        $GLOBALS['am_checks']['failed'][] = $what;
    }
}

// --- 1) Inventory/Show: handover.holder, NICHT handover.employee ---------
// Die Relation heisst seit der Umbenennung „Mitarbeiter"→„Asset-Traeger" holder(). Der alte Name
// warf eine RelationNotFoundException, sobald das Geraet ueberhaupt Protokoll-Zeilen hatte — bei
// null Zeilen ueberspringt Laravel verschachteltes Eager-Loading und der Fehler blieb verdeckt.
$err = null;
$n = queries(function () use (&$err) {
    try {
        AssetHandoverLine::with('handover.holder')
            ->whereHas('handover', fn ($q) => $q->where('team_id', 1))
            ->get()
            ->each(fn ($l) => $l->handover?->holder?->user_principal_name);
    } catch (\Throwable $e) {
        $err = get_class($e) . ': ' . $e->getMessage();
    }
});
check('Inventory/Show: handover.holder laedt ohne Fehler', null, $err);
at_most('Inventory/Show: Queries Protokoll-Zeilen + Traeger', 4, $n);

// --- 2) DeviceModels/Index: cost.costType + cost.vendor mit vorladen ----
// Die Liste ist bewusst unpaginiert und zeigt Kostenart und Kreditor je Zeile.
$n = queries(function () {
    $models = AssetDeviceModel::where('team_id', 1)
        ->with(['cost.costType', 'cost.vendor'])
        ->orderBy('manufacturer')->orderBy('model')->get();

    foreach ($models as $m) {
        $cost = $m->cost;
        $cost?->costType?->name;
        $cost?->vendor?->name;
    }
});
at_most("DeviceModels/Index: {$N} Modelle mit Kostenart+Kreditor", 4, $n);

// --- 3) GetHolderTool: ein Resolver je Tenant, nicht je Geraet ----------
$n = queries(function () {
    $rows      = AssetDevice::where('team_id', 1)->get();
    $resolvers = [];

    foreach ($rows as $d) {
        $resolvers[(int) $d->tenant_id] ??= DeviceCostResolver::for(
            1,
            $d->tenant_id !== null ? (int) $d->tenant_id : null,
        );
    }

    foreach ($rows as $d) {
        $resolvers[(int) $d->tenant_id]->monthlyCost($d);
    }
});
at_most("GetHolderTool: Monatskosten fuer {$N} Geraete", 3, $n);

// --- 4) Detailseite: Kosten und Geraete-Modell teilen den Resolver ------
// Vor dem Umbau von deviceModel() waren das vier Queries: Geraet + Resolver-Paar + ein eigener
// Katalog-Load fuer das Modell. Jetzt beantwortet der schon gebaute Resolver auch das Modell.
$n = queries(function () {
    $d = AssetDevice::where('team_id', 1)->first();
    $d->resolvedMonthlyCost();
    $d->deviceModel();
});
at_most('Inventory/Show: Kosten + Modell EINES Geraets', 3, $n);

// --- 5) Regression: deviceModel() trifft weiter dasselbe Modell ---------
// Referenz ist die alte Implementierung: erster Treffer nach normalisiertem Schluessel.
$mismatch = [];
foreach (AssetDevice::where('team_id', 1)->get() as $d) {
    $key = AssetDeviceModel::normalizeKey($d->manufacturer, $d->model);
    $alt = AssetDeviceModel::where('team_id', $d->team_id)->get()
        ->first(fn ($m) => AssetDeviceModel::normalizeKey($m->manufacturer, $m->model) === $key);

    if ((int) ($alt?->id ?? 0) !== (int) ($d->deviceModel()?->id ?? 0)) {
        $mismatch[] = $d->device_name;
    }
}
check('deviceModel(): gleiche Zuordnung wie vor dem Umbau', [], $mismatch);

// --- 6) Schluessel-Kollision: der Resolver waehlt deterministisch -------
// Zwei Modelle normalisieren auf denselben Schluessel, nur eines hat Tenant-Kosten. Die alte
// Implementierung nahm „was die DB zuerst liefert" — und konnte damit gegenueber Pivot und
// Modell-Report auf ein anderes Modell (und einen anderen Preis) zeigen.
DB::table('asset_device_models')->insert(['id' => 900, 'team_id' => 1, 'manufacturer' => 'hp ', 'model' => ' X1', 'depreciation_months' => 36]);
DB::table('asset_device_models')->insert(['id' => 901, 'team_id' => 1, 'manufacturer' => 'HP', 'model' => 'x1', 'depreciation_months' => 36]);
DB::table('asset_device_model_costs')->insert(['team_id' => 1, 'tenant_id' => 1, 'device_model_id' => 901, 'monthly_cost' => 42]);
DB::table('asset_devices')->insert(['id' => 900, 'team_id' => 1, 'tenant_id' => 1, 'device_name' => 'HP-Kollision', 'manufacturer' => 'HP', 'model' => 'X1', 'user_principal_name' => 'a@b.c']);

$dev = AssetDevice::where('team_id', 1)->find(900);
check('Kollision: Modell MIT Tenant-Preis gewinnt', 901, (int) $dev->deviceModel()?->id);
check('Kollision: Monatskosten kommen vom Modell-Default', 42.0, $dev->resolvedMonthlyCost());

echo "\n";
check_summary();
