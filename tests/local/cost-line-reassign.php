<?php

/**
 * CostLineReassignService - die Umbuchungs-Logik, die UI und MCP-Tool teilen.
 *
 * Wichtigste Punkte: dry_run darf NICHT schreiben, monthly_amount muss beim Umbuchen
 * unveraendert bleiben, und beide Team-Grenzen muessen halten (fremde Ziel-Kostenstelle
 * abgelehnt, fremde Zeile gilt als missing).
 *
 * Aufruf: php tests/local/cost-line-reassign.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Services\CostLineReassignService;
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
    $t->string('source')->default('manual');
    $t->date('valid_from')->nullable();
    $t->date('valid_to')->nullable();
    $t->boolean('active')->default(true);
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

const TEAM = 7;
const OTHER_TEAM = 8;
const TENANT = 1;
TenantContext::forceTenant(TENANT);

DB::table('asset_cost_centers')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'depth' => 0, 'code' => '1520', 'name' => 'Verwaltung'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'depth' => 0, 'code' => '9920', 'name' => null],
    ['id' => 3, 'team_id' => OTHER_TEAM, 'tenant_id' => TENANT, 'depth' => 0, 'code' => 'fremd', 'name' => 'Fremd-Team'],
]);

function line(array $attr): AssetCostLine
{
    return AssetCostLine::create(array_merge([
        'team_id'   => TEAM,
        'tenant_id' => TENANT,
        'currency'  => 'EUR',
        'frequency' => 'quarterly',
        'amount'    => 300,
        'active'    => true,
        'source'    => 'excel_import',
    ], $attr));
}

$a = line(['cost_center_id' => 1, 'label' => 'A']);              // monthly 100
$b = line(['cost_center_id' => 1, 'label' => 'B']);              // monthly 100
$c = line(['cost_center_id' => 2, 'label' => 'C (schon Ziel)']); // monthly 100

$svc   = app(CostLineReassignService::class);

echo "=== Vorschau (dry_run) ===\n";
$r = $svc->reassignCostCenter(TEAM, [$a->id, $b->id, $c->id, 99999], 2, null, true);
check('ok', true, $r['ok']);
check('dry_run gemeldet', true, $r['dry_run']);
check('wuerde 2 umziehen', 2, $r['summary']['updated']);
check('1 haengt schon dort', 1, $r['summary']['unchanged']);
check('1 unbekannte ID', 1, $r['summary']['missing']);
check('missing_ids', [99999], $r['missing_ids']);
check('Status would_update', 'would_update', $r['results'][0]['status']);
check('Ziel-Label (Code, name NULL)', '9920', $r['target']);
check('DRY RUN HAT NICHT GESCHRIEBEN', 1, (int) $a->fresh()->cost_center_id);

echo "\n=== Echte Umbuchung ===\n";
$monthlyBefore = (float) $a->fresh()->monthly_amount;
$r = $svc->reassignCostCenter(TEAM, [$a->id, $b->id, $c->id], 2, null, false);
check('2 umgezogen', 2, $r['summary']['updated']);
check('Status updated', 'updated', $r['results'][0]['status']);
check('Herkunft mitgeliefert', '1520 — Verwaltung', $r['results'][0]['from']);
check('A liegt jetzt auf 9920', 2, (int) $a->fresh()->cost_center_id);
check('monthly_amount unveraendert', $monthlyBefore, (float) $a->fresh()->monthly_amount);
check('updated_at wurde gesetzt', true, $a->fresh()->updated_at !== null);

echo "\n=== Ziel per Code ===\n";
$r = $svc->reassignCostCenter(TEAM, [$a->id], null, '1520', false);
check('per Code aufgeloest', 1, $r['summary']['updated']);
check('A wieder auf 1520', 1, (int) $a->fresh()->cost_center_id);
$r = $svc->reassignCostCenter(TEAM, [$a->id], null, '  1520  ', true);
check('Code wird getrimmt', true, $r['ok']);

echo "\n=== Fehlerfaelle ===\n";
$r = $svc->reassignCostCenter(TEAM, [], 2);
check('keine IDs -> blockiert', false, $r['ok']);
check('keine IDs -> code', 'VALIDATION_ERROR', $r['code']);
check('keine IDs -> reason', 'no_lines', $r['reason']);

$r = $svc->reassignCostCenter(TEAM, [$a->id], null, null);
check('kein Ziel -> reason', 'no_target', $r['reason']);

$r = $svc->reassignCostCenter(TEAM, [$a->id], null, 'gibtsnicht');
check('unbekannter Code -> code', 'COST_CENTER_NOT_FOUND', $r['code']);
check('unbekannter Code -> reason', 'center_not_found', $r['reason']);

$r = $svc->reassignCostCenter(TEAM, [$a->id], 999);
check('unbekannte Ziel-ID -> code', 'COST_CENTER_NOT_FOUND', $r['code']);

echo "\n=== Team-Grenze ===\n";
// Kostenstelle eines FREMDEN Teams darf nicht als Ziel taugen.
$r = $svc->reassignCostCenter(TEAM, [$a->id], 3);
check('fremde Ziel-KSt abgelehnt', 'COST_CENTER_NOT_FOUND', $r['code']);
check('A unveraendert auf 1520', 1, (int) $a->fresh()->cost_center_id);

// Zeile eines fremden Teams gilt als "missing", wird nicht angefasst.
$foreign = AssetCostLine::create([
    'team_id' => OTHER_TEAM, 'tenant_id' => TENANT, 'label' => 'Fremd',
    'amount' => 10, 'currency' => 'EUR', 'frequency' => 'monthly', 'cost_center_id' => 3,
]);
$r = $svc->reassignCostCenter(TEAM, [$foreign->id], 2);
check('fremde Zeile -> missing', 1, $r['summary']['missing']);
check('fremde Zeile unveraendert', 3, (int) $foreign->fresh()->cost_center_id);

echo "\n=== Doppelte IDs ===\n";
$r = $svc->reassignCostCenter(TEAM, [$b->id, $b->id, $b->id], 1, null, true);
check('Duplikate einmal gezaehlt', 1, $r['summary']['total']);

echo "\n";
check_summary();
