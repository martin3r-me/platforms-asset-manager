<?php

/**
 * Lizenz-Reclaim (#970): {@see \Platform\AssetManager\Services\LicenseReclaimService}.
 *
 * Aufruf: php tests/local/license-reclaim.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\LicenseReclaimService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\LicensePriceBook;

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('display_name')->nullable(); $t->string('user_principal_name')->nullable(); $t->boolean('is_active')->default(true);
    $t->timestamp('last_sign_in_at')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('user_principal_name');
    $t->string('sku_id')->nullable(); $t->decimal('unit_price', 12, 4)->nullable(); $t->unsignedBigInteger('contract_line_id')->nullable(); $t->timestamps();
});
Schema::create('asset_license_skus', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('sku_id');
    $t->string('sku_part_number')->nullable(); $t->string('display_name')->nullable(); $t->decimal('unit_price', 12, 4)->nullable(); $t->timestamps();
});
Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('period_label', 7)->nullable();
    $t->string('match_state', 20)->default('auto'); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('license_overflow_cost_center_id')->nullable(); $t->unsignedBigInteger('license_pool_cost_center_id')->nullable(); $t->timestamps();
});

const TEAM = 7;
TenantContext::forceTenant(1);
$svc = app(LicenseReclaimService::class);

DB::table('asset_holders')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'display_name' => 'Ohne Daten', 'user_principal_name' => 'a@x.de', 'is_active' => true, 'last_sign_in_at' => null],
]);
DB::table('asset_user_licenses')->insert(['team_id' => TEAM, 'tenant_id' => 1, 'user_principal_name' => 'a@x.de', 'sku_id' => 'E3', 'unit_price' => 33]);
LicensePriceBook::flush();
$r = $svc->candidates(TEAM);
check('ohne Anmeldedaten: nicht verfügbar', false, $r['available']);
check('ohne Anmeldedaten: niemand gemeldet (unbekannt ≠ nie)', 0, $r['count']);

DB::table('asset_holders')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'display_name' => 'Lange weg', 'user_principal_name' => 'B@X.DE', 'is_active' => true, 'last_sign_in_at' => now()->subDays(200)],
    ['team_id' => TEAM, 'tenant_id' => 1, 'display_name' => 'Aktiv', 'user_principal_name' => 'c@x.de', 'is_active' => true, 'last_sign_in_at' => now()->subDays(3)],
    ['team_id' => TEAM, 'tenant_id' => 1, 'display_name' => 'Stillgelegt', 'user_principal_name' => 'd@x.de', 'is_active' => false, 'last_sign_in_at' => now()->subDays(300)],
    ['team_id' => TEAM, 'tenant_id' => 1, 'display_name' => 'Ohne Lizenz', 'user_principal_name' => 'e@x.de', 'is_active' => true, 'last_sign_in_at' => now()->subDays(300)],
]);
DB::table('asset_user_licenses')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'user_principal_name' => 'b@x.de', 'sku_id' => 'E3', 'unit_price' => 33],
    ['team_id' => TEAM, 'tenant_id' => 1, 'user_principal_name' => 'b@x.de', 'sku_id' => 'PBI', 'unit_price' => 9.4],
    ['team_id' => TEAM, 'tenant_id' => 1, 'user_principal_name' => 'c@x.de', 'sku_id' => 'E3', 'unit_price' => 33],
    ['team_id' => TEAM, 'tenant_id' => 1, 'user_principal_name' => 'd@x.de', 'sku_id' => 'E3', 'unit_price' => 33],
]);
DB::table('asset_license_skus')->insert(['team_id' => TEAM, 'tenant_id' => 1, 'sku_id' => 'E3', 'display_name' => 'Office 365 E3']);
LicensePriceBook::flush();

$r = $svc->candidates(TEAM);
check('verfügbar', true, $r['available']);
check('nur der aktive, lange abwesende Träger mit Lizenz', ['B@X.DE'], array_column($r['rows'], 'upn'));
check('Monatsbetrag beider Lizenzen', 42.4, $r['monthly']);
check('Lizenznamen aufgelöst', ['Office 365 E3', 'PBI'], $r['rows'][0]['licenses'] ?? null);
check('kürzere Schwelle erfasst niemanden zusätzlich mit Lizenz', 1, $svc->candidates(TEAM, 30)['count']);

check_summary();
