<?php

/**
 * Status eines manuellen Assets (#941): {@see \Platform\AssetManager\Services\AssetWriteService::setItemStatus()}.
 *
 *  1. Detailseite: „Verloren" schließt die offene Zuordnung und entfernt den Träger.
 *  2. „Lager" löst ebenfalls, „Zugewiesen" ohne Träger wird abgelehnt.
 *  3. Unbekannter Status wird abgelehnt.
 *
 * Aufruf: php tests/local/item-status.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\TenantContext;

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
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_assignments', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id')->nullable();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('asset_item_id')->nullable();
    $t->string('assignable_type')->nullable();
    $t->unsignedBigInteger('assignable_id')->nullable();
    $t->unsignedBigInteger('holder_id')->nullable();
    $t->timestamp('assigned_at')->nullable();
    $t->timestamp('returned_at')->nullable();
    $t->string('source')->nullable();
    $t->timestamps();
});

const TEAM   = 7;
const TENANT = 1;
TenantContext::forceTenant(TENANT);

$writer = app(AssetWriteService::class);

$assigned = function (string $name): AssetItem {
    $item = AssetItem::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'name' => $name,
        'assignee_id' => 42, 'assigned_at' => now(), 'status' => 'assigned']);
    AssetAssignment::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'asset_item_id' => $item->id,
        'assignable_type' => AssetAssignment::SUBJECT_ITEM, 'assignable_id' => $item->id,
        'holder_id' => 42, 'assigned_at' => now(), 'source' => AssetAssignment::SOURCE_MANUAL]);

    return $item;
};

// 1. Detailseite: Verloren
$laptop = $assigned('Laptop');
$writer->updateItemDetails($laptop, ['status' => 'lost', 'name' => 'Laptop', 'categoryId' => null]);
$laptop->refresh();
check('Verloren: Status gesetzt', 'lost', $laptop->status);
check('Verloren: Träger entfernt', null, $laptop->assignee_id);
check('Verloren: offene Zuordnung geschlossen', 0, AssetAssignment::where('asset_item_id', $laptop->id)->whereNull('returned_at')->count());
check('Verloren: Zuordnung bleibt als Verlauf', 1, AssetAssignment::where('asset_item_id', $laptop->id)->count());

// 2. Lager löst, Zugewiesen ohne Träger nicht
$monitor = $assigned('Monitor');
check('Lager: gesetzt', true, $writer->setItemStatus($monitor, 'in_stock'));
check('Lager: Träger entfernt', null, $monitor->fresh()->assignee_id);
check('Zugewiesen ohne Träger: abgelehnt', false, $writer->setItemStatus($monitor->fresh(), 'assigned'));
check('Zugewiesen ohne Träger: Status bleibt', 'in_stock', $monitor->fresh()->status);

// 3. Unbekannt
check('Unbekannter Status: abgelehnt', false, $writer->setItemStatus($monitor->fresh(), 'kaputt'));

// Unveränderter Status fasst die Zuordnung nicht an
$dock = $assigned('Dock');
$writer->updateItemDetails($dock, ['status' => 'assigned', 'name' => 'Dock 2']);
check('Gleicher Status: Träger bleibt', 42, (int) $dock->fresh()->assignee_id);
check('Gleicher Status: Name geändert', 'Dock 2', $dock->fresh()->name);

check_summary();
