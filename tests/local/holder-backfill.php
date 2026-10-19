<?php

/**
 * Träger-Backfill nach den Syncs (#939): {@see \Platform\AssetManager\Services\HolderService::backfillForTenant()}.
 *
 *  1. Papierkorb-UPN auf einem Gerät legt KEINEN zweiten Träger an.
 *  2. Andere Groß-/Kleinschreibung derselben UPN legt keinen zweiten Träger an.
 *  3. Neu angelegte Träger sind klassifiziert (vorher blieben sie `person`).
 *  4. Zweiter Lauf legt nichts mehr an (idempotent).
 *
 * Aufruf: php tests/local/holder-backfill.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('email')->nullable();
    $t->string('source')->default('graph');
    $t->string('holder_type')->default('person');
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->json('holder_type_rules')->nullable();
    $t->timestamps();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('user_display_name')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name');
    $t->string('display_name')->nullable();
    $t->string('sku_id')->nullable();
    $t->timestamps();
});

const TEAM   = 7;
const TENANT = 1;

TenantContext::forceTenant(TENANT);
HolderClassifier::forget();

DB::table('asset_team_settings')->insert([
    'team_id' => TEAM, 'tenant_id' => TENANT,
    'holder_type_rules' => json_encode([['type' => 'admin', 'field' => 'upn', 'match' => 'prefix', 'value' => 'admin']]),
]);

// Bestand: ein echter Träger in Großschreibung.
AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'F.TUNCHER@BROICH.COM', 'source' => 'graph']);

$device = fn (string $upn, ?string $name = null) => DB::table('asset_devices')->insert(
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => $upn, 'user_display_name' => $name]
);
$license = fn (string $upn, ?string $name = null) => DB::table('asset_user_licenses')->insert(
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => $upn, 'display_name' => $name, 'sku_id' => 'E3']
);

$device('0b521d5646ee4b158a9c13727799dbd0F.TUNCHER@BROICH.COM', 'Tuncher');  // Papierkorb-UPN
$license('f.tuncher@broich.com', 'Tuncher');                                  // andere Schreibweise
$device('neu@broich.com', 'Neu');                                             // wirklich neu
$license('NEU@BROICH.COM');                                                   // dieselbe, andere Schreibweise
$license('admin.it@broich.com', 'Admin IT');                                  // Regel → admin

$svc     = app(HolderService::class);
$created = $svc->backfillForTenant(TEAM, TENANT);

check('genau zwei neu angelegt (neu@, admin.it@)', 2, $created);
check('kein Träger mit Papierkorb-Präfix', 0, AssetHolder::withoutTenantScope()->where('user_principal_name', 'like', '0b521d%')->count());
check('Tuncher genau einmal', 1, AssetHolder::withoutTenantScope()->whereRaw('LOWER(user_principal_name) = ?', ['f.tuncher@broich.com'])->count());
check('neu@ genau einmal', 1, AssetHolder::withoutTenantScope()->whereRaw('LOWER(user_principal_name) = ?', ['neu@broich.com'])->count());
check('Anzeigename übernommen', 'Neu', AssetHolder::withoutTenantScope()->whereRaw('LOWER(user_principal_name) = ?', ['neu@broich.com'])->value('display_name'));
check('Backfill-Träger klassifiziert', 'admin', AssetHolder::withoutTenantScope()->where('user_principal_name', 'admin.it@broich.com')->value('holder_type'));
check('Quelle derived', 'derived', AssetHolder::withoutTenantScope()->where('user_principal_name', 'admin.it@broich.com')->value('source'));

check('zweiter Lauf legt nichts an', 0, $svc->backfillForTenant(TEAM, TENANT));
check('Gesamtzahl Träger', 3, AssetHolder::withoutTenantScope()->count());


// Gastkonto (#969): „#EXT#" in der UPN → extern, auch ohne Regel.
$license('m.partner_agentur.de#EXT#@broich.onmicrosoft.com', 'Partner');
$svc->backfillForTenant(TEAM, TENANT);
check('Gastkonto wird als extern angelegt', 'external', AssetHolder::withoutTenantScope()->where('user_principal_name', 'like', '%#EXT#%')->value('holder_type'));

// Gastkonto laut Graph (userType=Guest) ohne #EXT#-UPN
$guest = AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'gast@extern.de', 'source' => 'graph']);
$svc->applyGraphProfile($guest, ['userType' => 'Guest']);
check('userType=Guest → extern', 'external', $guest->holder_type);
$admin = AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'adm2@broich.com', 'source' => 'graph', 'holder_type' => 'admin']);
$svc->applyGraphProfile($admin, ['userType' => 'Guest']);
check('bewusst gesetzter Typ bleibt', 'admin', $admin->holder_type);

check_summary();
