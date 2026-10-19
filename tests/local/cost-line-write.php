<?php

/**
 * Schreibweg der manuellen Kostenpositionen (#938): {@see \Platform\AssetManager\Services\CostLineWriteService}.
 *
 * Deckt die Lücken ab, die UI und MCP-Tools auseinanderlaufen ließen:
 *  1. Anlegen setzt tenant_id (vorher scheiterte cost-lines.POST an der NOT-NULL-Spalte).
 *  2. Pflichtfelder, Datumslogik, Fremd-Referenzen (anderer Tenant) werden abgelehnt.
 *  3. Teil-Update prüft den EFFEKTIVEN Stand: Kostenartwechsel bei Minusbetrag, EUR löscht den Kurs.
 *  4. Strikte Kostenart (Tool): fremde aggregation_source wird abgelehnt.
 *
 * Aufruf: php tests/local/cost-line-write.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\CostLineWriteService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->unsignedBigInteger('vendor_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable();
    $t->string('label');
    $t->decimal('amount', 12, 2);
    $t->string('currency', 3)->default('EUR');
    $t->decimal('fx_rate', 12, 6)->nullable();
    $t->string('frequency');
    $t->decimal('monthly_amount', 12, 2)->default(0);
    $t->string('accounting_system')->nullable();
    $t->string('source')->default('manual');
    $t->date('valid_from')->nullable();
    $t->date('valid_to')->nullable();
    $t->boolean('active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('key');
    $t->string('name');
    $t->integer('sort_order')->default(0);
    $t->string('allocation_level')->default('cost_center');
    $t->string('aggregation_source')->default('cost_line');
    $t->boolean('allow_negative')->default(false);
    $t->string('system_default')->nullable();
    $t->unsignedBigInteger('vendor_default_id')->nullable();
    $t->timestamps();
    $t->softDeletes();
});

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

Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('name');
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('display_name')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->string('name');
    $t->boolean('is_default')->default(false);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_tenant_selections', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('selected_tenant_id')->nullable();
    $t->timestamps();
});

const TEAM   = 7;
const TENANT = 1;
const OTHER  = 2;

DB::table('asset_tenants')->insert([
    ['id' => TENANT, 'team_id' => TEAM, 'name' => 'Standard', 'is_default' => true],
    ['id' => OTHER, 'team_id' => TEAM, 'name' => 'Kunde B', 'is_default' => false],
]);
DB::table('asset_vendors')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'name' => 'Vodafone'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => OTHER, 'name' => 'Fremd'],
]);
DB::table('asset_cost_types')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk',
     'aggregation_source' => 'cost_line', 'allow_negative' => 0, 'system_default' => 'HGK', 'vendor_default_id' => 1],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'rabatt', 'name' => 'Rabatt',
     'aggregation_source' => 'cost_line', 'allow_negative' => 1, 'system_default' => null, 'vendor_default_id' => null],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'geraete', 'name' => 'Geräte',
     'aggregation_source' => 'asset_device', 'allow_negative' => 0, 'system_default' => null, 'vendor_default_id' => null],
]);

TenantContext::forceTenant(TENANT);
$svc = app(CostLineWriteService::class);

echo "=== Anlegen ===\n";
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'Handy A', 'amount' => '29.90', 'frequency' => 'monthly']);
check('Anlegen ok', true, $r['ok']);
check('tenant_id gesetzt', TENANT, (int) $r['line']->tenant_id);
check('source manual', 'manual', $r['line']->source);
check('Kreditor-Vorgabe der Kostenart', 1, (int) $r['line']->vendor_id);
check('Buchungssystem der Kostenart', 'HGK', $r['line']->accounting_system);
check('EUR ohne Kurs', null, $r['line']->fx_rate);
$lineId = $r['line']->id;

$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'amount' => 10, 'frequency' => 'monthly']);
check('Bezeichnung Pflicht', true, isset($r['errors']['label']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'x', 'amount' => 10, 'frequency' => 'monthly', 'valid_from' => '2026-05-01', 'valid_to' => '2026-04-01']);
check('bis vor ab abgelehnt', true, isset($r['errors']['valid_to']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'x', 'amount' => 10, 'frequency' => 'monthly', 'valid_from' => 'kein datum']);
check('ungültiges Datum abgelehnt', true, isset($r['errors']['valid_from']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'x', 'amount' => 10, 'frequency' => 'monthly', 'vendor_id' => 2]);
check('Kreditor anderer Tenant abgelehnt', true, isset($r['errors']['vendor_id']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'x', 'amount' => 10, 'frequency' => 'monthly', 'currency' => 'usd']);
check('USD ohne Kurs abgelehnt', true, isset($r['errors']['fx_rate']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'x', 'amount' => -5, 'frequency' => 'monthly']);
check('Minus ohne allow_negative abgelehnt', true, isset($r['errors']['amount']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 3, 'label' => 'x', 'amount' => 5, 'frequency' => 'monthly'], requireCostLineSource: true);
check('strikt: fremde aggregation_source abgelehnt', true, isset($r['errors']['cost_type_id']));
$r = $svc->create(TEAM, TENANT, ['cost_type_id' => 3, 'label' => 'x', 'amount' => 5, 'frequency' => 'monthly']);
check('nicht strikt (UI): erlaubt', true, $r['ok']);

echo "\n=== Teil-Update ===\n";
$rabatt = $svc->create(TEAM, TENANT, ['cost_type_id' => 2, 'label' => 'Gutschrift', 'amount' => -10, 'frequency' => 'monthly'])['line'];
$r = $svc->update($rabatt->fresh(), ['cost_type_id' => 1]);
check('Kostenartwechsel bei Minusbetrag abgelehnt', true, isset($r['errors']['amount']));

$usd = $svc->create(TEAM, TENANT, ['cost_type_id' => 1, 'label' => 'SaaS', 'amount' => 100, 'frequency' => 'monthly', 'currency' => 'USD', 'fx_rate' => 0.92])['line'];
check('USD mit Kurs angelegt', 0.92, (float) $usd->fx_rate);
$r = $svc->update($usd->fresh(), ['currency' => 'EUR']);
check('Wechsel auf EUR ok', true, $r['ok']);
check('Kurs bei EUR gelöscht', null, $r['line']->fresh()->fx_rate);

$line = \Platform\AssetManager\Models\AssetCostLine::find($lineId);
$r = $svc->update($line, ['amount' => 35]);
check('nur Betrag ändern ok', true, $r['ok']);
check('Bezeichnung bleibt', 'Handy A', $r['line']->fresh()->label);
check('Tenant bleibt', TENANT, (int) $r['line']->fresh()->tenant_id);

check_summary();
