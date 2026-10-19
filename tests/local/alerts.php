<?php

/**
 * Fristen & Warnungen (#973): {@see \Platform\AssetManager\Services\AlertService}.
 *
 * Aufruf: php tests/local/alerts.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\AlertService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->date('warranty_until')->nullable(); $t->date('lease_until')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('period_label', 7);
    $t->decimal('quantity', 12, 2)->default(0); $t->decimal('unit_price', 12, 4)->default(0); $t->string('binding', 8)->default('P1M');
    $t->date('cancellable_at')->nullable(); $t->string('sku_id')->nullable(); $t->string('match_state', 20)->default('auto');
    $t->decimal('overflow_quantity', 12, 2)->default(0); $t->timestamps();
});
Schema::create('asset_billing_runs', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('status', 16); $t->timestamps();
});
foreach (['asset_device_sync_logs', 'asset_license_sync_logs'] as $table) {
    Schema::create($table, function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('status');
        $t->timestamp('started_at')->nullable(); $t->timestamps();
    });
}
Schema::create('asset_connector_configs', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->boolean('enabled')->default(true); $t->timestamps();
});
Schema::create('asset_import_runs', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('source');
    $t->string('status'); $t->boolean('dry_run')->default(false); $t->timestamps();
});

const TEAM = 7;
TenantContext::forceTenant(1);
$svc  = app(AlertService::class);
$keys = fn (bool $controlling) => array_column($svc->collect(TEAM, $controlling), 'key');

check('leerer Bestand: keine Warnungen', [], $keys(true));

DB::table('asset_devices')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'warranty_until' => now()->addDays(30)->toDateString(), 'lease_until' => null],
    ['team_id' => TEAM, 'tenant_id' => 1, 'warranty_until' => '2020-01-01', 'lease_until' => null], // längst abgelaufen
]);
DB::table('asset_license_contract_lines')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'period_label' => '2026-09', 'quantity' => 10, 'unit_price' => 33, 'binding' => 'P1Y', 'cancellable_at' => now()->addDays(20)->toDateString()],
    ['team_id' => TEAM, 'tenant_id' => 1, 'period_label' => '2026-09', 'quantity' => 5, 'unit_price' => 6, 'binding' => 'P1Y', 'cancellable_at' => null],
]);
DB::table('asset_billing_runs')->insert(['team_id' => TEAM, 'tenant_id' => 1, 'status' => 'pending', 'created_at' => now()->subHour()]);
DB::table('asset_device_sync_logs')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'status' => 'error', 'started_at' => now()->subHours(2)],
    ['team_id' => TEAM, 'tenant_id' => 1, 'status' => 'error', 'started_at' => now()->subHour()],
]);
DB::table('asset_license_sync_logs')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'status' => 'error', 'started_at' => now()->subHours(2)],
    ['team_id' => TEAM, 'tenant_id' => 1, 'status' => 'success', 'started_at' => now()->subHour()],
]);
DB::table('asset_connector_configs')->insert(['team_id' => TEAM, 'tenant_id' => 1, 'enabled' => true]);

$all = $keys(true);
sort($all);
check('mit Controlling: alle Fälle', ['billing_pending', 'device_expiring', 'device_sync_failing', 'directory_stale', 'license_cancel_missing', 'license_cancel_window'], $all);
check('Lizenz-Sync: ein Fehler, dann Erfolg → keine Warnung', false, in_array('license_sync_failing', $all, true));

$plain = $keys(false);
sort($plain);
check('ohne Controlling: keine kaufmännischen Punkte', ['device_expiring', 'device_sync_failing', 'directory_stale'], $plain);

$first = $svc->collect(TEAM, true)[0]['severity'];
check('sortiert: Dringendes zuerst', 'high', $first);

check_summary();
