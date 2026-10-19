<?php

/**
 * Regeln der Geräte-Kostenfelder (#959): {@see \Platform\AssetManager\Services\AssetWriteService::deviceCostErrors()}.
 * Dieselben Regeln wie die Livewire-Validierung der Detailseite — jetzt auch für die MCP-Tools.
 *
 * Aufruf: php tests/local/device-cost-rules.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Services\AssetWriteService;

check('gültige Werte', [], AssetWriteService::deviceCostErrors(['monthly_cost' => '21.36', 'purchase_price' => 1200, 'depreciation_months' => 36, 'purchase_date' => '2026-01-15']));
check('leere Werte sind erlaubt (Override entfernen)', [], AssetWriteService::deviceCostErrors(['monthly_cost' => '', 'depreciation_months' => null]));
check('nicht übergebene Felder werden nicht geprüft', [], AssetWriteService::deviceCostErrors([]));
check('negative Monatsrate', ['monthly_cost'], array_keys(AssetWriteService::deviceCostErrors(['monthly_cost' => -5])));
check('Text als Kaufpreis', ['purchase_price'], array_keys(AssetWriteService::deviceCostErrors(['purchase_price' => 'abc'])));
check('negative AfA-Monate', ['depreciation_months'], array_keys(AssetWriteService::deviceCostErrors(['depreciation_months' => -12])));
check('AfA 0 Monate', ['depreciation_months'], array_keys(AssetWriteService::deviceCostErrors(['depreciation_months' => 0])));
check('AfA gebrochen', ['depreciation_months'], array_keys(AssetWriteService::deviceCostErrors(['depreciation_months' => 2.5])));
check('Datum ungültig', ['purchase_date'], array_keys(AssetWriteService::deviceCostErrors(['purchase_date' => 'gestern irgendwann'])));

check_summary();
