<?php

/**
 * Kostenstelle löschen (#966): {@see \Platform\AssetManager\Services\MasterDataDeletionService::deleteCostCenter()}.
 *
 *  1. Eine Kostenstelle, auf die eine Einstellung zeigt (Pool, Überhang, Telefonie, DATEV-Quelle), ist gesperrt.
 *  2. dry_run nennt die Mengen und löscht nichts.
 *  3. Löschen leert neben der Zuordnung auch den alten Code-String am Träger.
 *
 * Aufruf: php tests/local/cost-center-delete.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Services\MasterDataDeletionService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('parent_id')->nullable();
    $t->integer('depth')->default(0);
    $t->string('code');
    $t->string('name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->integer('sort_order')->default(0);
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('license_pool_cost_center_id')->nullable();
    $t->unsignedBigInteger('license_overflow_cost_center_id')->nullable();
    $t->unsignedBigInteger('telephony_cost_center_id')->nullable();
    $t->unsignedBigInteger('datev_source_cost_center_id')->nullable();
    $t->timestamps();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('cost_center')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->timestamps();
});
foreach (['asset_cost_lines', 'asset_devices'] as $table) {
    Schema::create($table, function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('team_id');
        $t->unsignedBigInteger('tenant_id')->nullable();
        $t->unsignedBigInteger('cost_center_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
}

const TEAM   = 7;
const TENANT = 1;
TenantContext::forceTenant(TENANT);

$cc = fn (string $code, ?int $parent = null) => DB::table('asset_cost_centers')->insertGetId(
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'code' => $code, 'parent_id' => $parent, 'depth' => $parent ? 1 : 0]
);
$pool  = $cc('1520');
$sales = $cc('2599');
$child = $cc('2599-1', $sales);

DB::table('asset_team_settings')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'license_pool_cost_center_id' => $pool, 'datev_source_cost_center_id' => $pool]);
DB::table('asset_holders')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'cost_center_id' => $sales, 'cost_center' => '2599'],
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'cost_center_id' => $sales, 'cost_center' => '2599'],
]);
DB::table('asset_cost_lines')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'cost_center_id' => $sales]);
DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'cost_center_id' => $sales]);

$svc = app(MasterDataDeletionService::class);

// 1. Einstellungs-Rolle sperrt
$r = $svc->deleteCostCenter(TEAM, $pool);
check('Pool/DATEV-Quelle: gesperrt', false, $r['ok']);
check('Sperre nennt beide Rollen', true, str_contains($r['message'], 'Lizenz-Pool') && str_contains($r['message'], 'DATEV-Quellkostenstelle'));
check('Gesperrt: Kostenstelle bleibt', true, AssetCostCenter::find($pool) !== null);

// 2. Vorschau
$r = $svc->deleteCostCenter(TEAM, $sales, dryRun: true);
check('Vorschau ok', true, $r['ok'] && ($r['dry_run'] ?? false));
check('Vorschau: Mengen', ['children' => 1, 'holders' => 2, 'cost_lines' => 1, 'devices' => 1], $r['impact']);
check('Vorschau löscht nichts', true, AssetCostCenter::find($sales) !== null);

// 3. Löschen
$r = $svc->deleteCostCenter(TEAM, $sales);
check('Gelöscht', true, $r['ok'] && AssetCostCenter::find($sales) === null);
check('Alter Code-String am Träger geleert', 0, DB::table('asset_holders')->whereNotNull('cost_center')->count());
check('Kind ist oberster Knoten mit depth 0', 0, (int) AssetCostCenter::find($child)?->depth);

check_summary();
