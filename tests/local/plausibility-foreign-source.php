<?php

/**
 * Plausibilitätsregel #899: Kostenposition auf einer Kostenart mit Quelle asset_device/ms_license.
 *
 * Aufruf: php tests/local/plausibility-foreign-source.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Services\CostPlausibilityService;

$type = function (string $source, string $name): AssetCostType {
    $t = new AssetCostType();
    $t->forceFill(['id' => crc32($name), 'name' => $name, 'key' => strtolower($name), 'aggregation_source' => $source, 'allocation_level' => 'cost_center']);

    return $t;
};
$line = function (int $id, AssetCostType $t, ?string $from = null, ?string $to = null): AssetCostLine {
    $l = new AssetCostLine();
    $l->forceFill(['id' => $id, 'label' => "Zeile {$id}", 'amount' => 10, 'monthly_amount' => 10, 'frequency' => 'monthly',
        'active' => true, 'valid_from' => $from, 'valid_to' => $to, 'cost_type_id' => $t->id]);
    $l->setRelation('costType', $t);
    $l->setRelation('costCenter', null);
    $l->setRelation('assignee', null);

    return $l;
};

$geraete = $type(AssetCostType::SOURCE_ASSET_DEVICE, 'Geräte');
$lizenz  = $type(AssetCostType::SOURCE_MS_LICENSE, 'Lizenzen');
$mobil   = $type(AssetCostType::SOURCE_COST_LINE, 'Mobilfunk');

$lines = collect([
    $line(1, $geraete),                                // trifft
    $line(2, $lizenz, '2020-01-01'),                   // trifft
    $line(3, $mobil),                                  // richtige Quelle
    $line(4, $geraete, null, '2020-12-31'),            // abgelaufen
    $line(5, $lizenz, now()->addMonth()->toDateString()), // erst künftig gültig
]);

$m = (new ReflectionClass(CostPlausibilityService::class))->getMethod('linesOnForeignSource');
$m->setAccessible(true);
$finding = $m->invoke(app(CostPlausibilityService::class), $lines);

check('Befund vorhanden', 'line_on_foreign_source', $finding['key'] ?? null);
check('nur aktive, heute gültige Zeilen 1 und 2', [1, 2], array_values(array_map('intval', $finding['line_ids'] ?? [])));
check('kein Befund ohne Treffer', null, $m->invoke(app(CostPlausibilityService::class), collect([$line(9, $mobil)])));

check_summary();
