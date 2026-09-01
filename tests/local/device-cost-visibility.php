<?php

/**
 * Die Plausibilitätsregel `device_cost_not_aggregated` — Geräte, die einen Preis tragen, dessen
 * Kostenart aber nicht die Quelle `asset_device` hat und deren Betrag deshalb in KEINER Auswertung
 * landet.
 *
 * Warum das einen Test braucht: der Fehlerfall ist **stumm**. Genau so lagen 69 Laptops mit
 * hinterlegter Leasingrate mit 0 € in der Kostenaufteilung — der Hardware-Bucket blieb bei 0, ohne
 * Fehler und ohne Hinweis. Ein stummer Fall fällt beim Entwickeln nicht auf, also muss er hier
 * festgenagelt sein.
 *
 * Geprüft wird auch der Feld-Vertrag der Beispielzeilen (label/cost_type/holder/hint/monthly): die
 * Anzeige im Kosten-Dashboard rendert für ALLE Befunde dieselbe Tabelle, ein eigenes Vokabular
 * ergäbe dort stumme leere Spalten — also derselbe Fehlertyp nochmal, nur eine Ebene höher.
 *
 * Aufruf: php tests/local/device-cost-visibility.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Services\CostPlausibilityService;
use Platform\AssetManager\Services\TenantContext;

TenantContext::forceTenant(1);

const TEAM = 7;

// --- Minimalschema: nur was die Regel anfasst -----------------------------
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('key')->nullable();
    $t->string('name')->nullable();
    $t->string('aggregation_source')->nullable();
    $t->string('allocation_level')->nullable();
    $t->timestamps();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('device_name')->nullable();
    $t->string('serial_number')->nullable();
    $t->string('manufacturer')->nullable();
    $t->string('model')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
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

// --- Stammdaten -----------------------------------------------------------
$now = '2026-08-31 12:00:00';

DB::table('asset_cost_types')->insert([
    // Die reale Ausgangslage: die Laptop-Kostenart kam aus dem Excel-Import und war cost_line.
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => 1, 'key' => 'lap_dock', 'name' => 'Lap+Dock',
     'aggregation_source' => AssetCostType::SOURCE_COST_LINE, 'allocation_level' => 'person',
     'created_at' => $now, 'updated_at' => $now],
]);

DB::table('asset_device_models')->insert([
    ['id' => 1, 'team_id' => TEAM, 'manufacturer' => 'LENOVO', 'model' => '21STS09900',
     'depreciation_months' => null, 'created_at' => $now, 'updated_at' => $now],
]);

DB::table('asset_devices')->insert([
    // 1: eigener Override + Kostenart am Gerät
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => 'KB-LNVE16-AAA',
     'manufacturer' => 'LENOVO', 'model' => '21STS09900', 'user_principal_name' => 'a@example.com',
     'cost_type_id' => 1, 'monthly_cost' => 21.36, 'created_at' => $now, 'updated_at' => $now],
    // 2: Rate, aber gar keine Kostenart (auch das Modell hat keine)
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => 'KB-LNVE16-BBB',
     'manufacturer' => 'DELL', 'model' => 'XPS13', 'user_principal_name' => 'b@example.com',
     'cost_type_id' => null, 'monthly_cost' => 30.00, 'created_at' => $now, 'updated_at' => $now],
    // 3: gar kein Preis — darf NIE auftauchen, sonst meldet die Regel jedes Handy im Bestand
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => 'KB-PHONE-CCC',
     'manufacturer' => 'samsung', 'model' => 'SM-A556B', 'user_principal_name' => 'c@example.com',
     'cost_type_id' => 1, 'monthly_cost' => null, 'created_at' => $now, 'updated_at' => $now],
]);

$service = new CostPlausibilityService();

/** Die Regel isoliert aufrufen — check() zieht Kostenpositionen und Kostenstellen mit, die hier nichts beitragen. */
$rule = function () use ($service): ?array {
    $m = new ReflectionMethod($service, 'devicesWithSilentCost');
    $m->setAccessible(true);

    return $m->invoke($service, TEAM);
};

// --- 1) Ausgangslage: Kostenart ist cost_line → beide Preise sind stumm ---
$f = $rule();
check('Befund erkennt die stummen Geräte', 2, $f['count'] ?? null);
check('Summe = die Beträge, die nirgends landen', 51.36, $f['monthly'] ?? null);
check('Gerät ohne Preis bleibt außen vor', false,
    in_array('KB-PHONE-CCC', array_column($f['examples'] ?? [], 'label'), true));

$byLabel = array_column($f['examples'] ?? [], null, 'label');
check('Feld-Vertrag der Anzeige eingehalten', ['label', 'cost_type', 'holder', 'hint', 'monthly'],
    array_values(array_intersect(['label', 'cost_type', 'holder', 'hint', 'monthly'],
        array_keys($byLabel['KB-LNVE16-AAA'] ?? []))));
check('Hinweis nennt die falsche Quelle', true,
    str_contains($byLabel['KB-LNVE16-AAA']['hint'] ?? '', 'Quelle der Kostenart'));
check('Hinweis nennt die fehlende Kostenart', true,
    str_contains($byLabel['KB-LNVE16-BBB']['hint'] ?? '', 'keine Kostenart'));
check('Modell steht im Hinweis', true,
    str_contains($byLabel['KB-LNVE16-AAA']['hint'] ?? '', 'LENOVO 21STS09900'));
check('line_ids leer — sind Geräte, keine Positionen', [], $f['line_ids'] ?? null);

// --- 2) Quelle umgestellt → das Gerät MIT Kostenart zählt jetzt mit -------
DB::table('asset_cost_types')->where('id', 1)
    ->update(['aggregation_source' => AssetCostType::SOURCE_ASSET_DEVICE]);

$f = $rule();
check('nach Umstellung bleibt nur das Gerät ohne Kostenart', 1, $f['count'] ?? null);
check('und nur dessen Betrag', 30.00, $f['monthly'] ?? null);

// --- 3) Modell-Default statt Override: derselbe stumme Fall ---------------
// Gerät 4 hat keinen eigenen Preis, erbt ihn vom Modell — und das Modell trägt eine
// cost_line-Kostenart. Muss genauso auffallen wie ein Override.
DB::table('asset_cost_types')->insert([
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => 1, 'key' => 'sonstiges', 'name' => 'Sonstiges',
     'aggregation_source' => AssetCostType::SOURCE_COST_LINE, 'allocation_level' => 'person',
     'created_at' => $now, 'updated_at' => $now],
]);
DB::table('asset_device_model_costs')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'device_model_id' => 1, 'cost_type_id' => 2,
     'monthly_cost' => 19.90, 'created_at' => $now, 'updated_at' => $now],
]);
DB::table('asset_devices')->insert([
    ['id' => 4, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => 'KB-LNVE16-DDD',
     'manufacturer' => 'lenovo', 'model' => ' 21sts09900 ', 'user_principal_name' => 'd@example.com',
     'cost_type_id' => null, 'monthly_cost' => null, 'created_at' => $now, 'updated_at' => $now],
]);

$f      = $rule();
$labels = array_column($f['examples'] ?? [], 'monthly', 'label');
check('Modell-Default wird genauso geprüft', true, isset($labels['KB-LNVE16-DDD']));
check('mit dem Betrag aus dem Modell', 19.9, $labels['KB-LNVE16-DDD'] ?? null);
check('Gerät 1 zählt weiterhin korrekt mit', false, isset($labels['KB-LNVE16-AAA']));

// --- 4) Alles saubergezogen → kein Befund --------------------------------
DB::table('asset_cost_types')->where('id', 2)
    ->update(['aggregation_source' => AssetCostType::SOURCE_ASSET_DEVICE]);
DB::table('asset_devices')->where('id', 2)->update(['cost_type_id' => 1]);

check('kein Befund, wenn jede Rate eine Geräte-Kostenart hat', null, $rule());

check_summary();
