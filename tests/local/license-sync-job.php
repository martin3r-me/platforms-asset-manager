<?php

/**
 * Lizenz-Sync End-to-End mit Graph-Attrappe: {@see \Platform\AssetManager\Jobs\SyncLicensesJob::handle()} (#976).
 *
 *  - Reconcile: abgegebene Zuweisungen verschwinden, neue erhalten assigned_at (#978: ohne zweites UPDATE)
 *  - leere Graph-Antwort löscht NICHTS (Empty-Keyset-Guard)
 *  - gesperrte SKU (capabilityStatus Suspended) hat keine freien Plätze; verschwundene SKU steht auf 0 (#968)
 *  - Gastkonto (#EXT#) wird als extern angelegt (#969)
 *  - Seat-Preise werden nach Änderungen neu verteilt (#944) — hier: der Aufruf wirft nicht und läuft durch
 *
 * Aufruf: php tests/local/license-sync-job.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TenantContext;

$models = [
    Models\AssetConnectorConfig::class, Models\AssetLicenseSyncLog::class, Models\AssetLicenseSku::class,
    Models\AssetUserLicense::class, Models\AssetHolder::class, Models\AssetTeamSetting::class, Models\AssetTenant::class,
    Models\AssetDevice::class, Models\AssetHolderEvent::class, Models\AssetLicenseContractLine::class,
];
foreach ($models as $class) {
    $m = new $class();
    Schema::create($m->getTable(), function (Blueprint $t) use ($m) {
        $t->id();
        foreach (array_unique(array_merge($m->getFillable(), ['team_id', 'tenant_id'])) as $col) {
            $t->text($col)->nullable();
        }
        $t->timestamps();
        $t->softDeletes();
    });
}

const TEAM   = 7;
const TENANT = 1;
DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Broich', 'is_default' => 1]);
$config = Models\AssetConnectorConfig::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'enabled' => true,
    'azure_tenant_id' => 'tenant-guid', 'client_id' => 'client', 'client_secret' => 'secret',
]);

$graph = new class extends IntuneGraphService {
    public array $skus = [];
    public array $users = [];
    public function getSubscribedSkus($config): ?array { return $this->skus; }
    public function getUsersWithLicenses($config): ?array { return $this->users; }
};
$run = function () use ($graph, $config): void {
    (new SyncLicensesJob($config->id))->handle($graph, app(HolderService::class));
    TenantContext::forceTenant(TENANT);
};
$assignments = fn () => Models\AssetUserLicense::withoutTenantScope()->get()
    ->map(fn ($l) => strtolower($l->user_principal_name) . ':' . $l->sku_id)->sort()->values()->all();
$sku = fn (string $id) => Models\AssetLicenseSku::withoutTenantScope()->where('sku_id', $id)->first();

// ---- Lauf 1 ---------------------------------------------------------------------------------
$graph->skus = [
    ['skuId' => 'E3', 'skuPartNumber' => 'ENTERPRISEPACK', 'consumedUnits' => 2, 'prepaidUnits' => ['enabled' => 5], 'capabilityStatus' => 'Enabled'],
    ['skuId' => 'OLD', 'skuPartNumber' => 'OLDPLAN', 'consumedUnits' => 0, 'prepaidUnits' => ['enabled' => 10], 'capabilityStatus' => 'Suspended'],
];
$graph->users = [
    ['id' => 'u1', 'userPrincipalName' => 'anna@broich.de', 'displayName' => 'Anna', 'assignedLicenses' => [['skuId' => 'E3']]],
    ['id' => 'u2', 'userPrincipalName' => 'partner_agentur.de#EXT#@broich.onmicrosoft.com', 'displayName' => 'Partner', 'assignedLicenses' => [['skuId' => 'E3']]],
];
$run();

check('Lauf 1: zwei Zuweisungen', ['anna@broich.de:E3', 'partner_agentur.de#ext#@broich.onmicrosoft.com:E3'], $assignments());
check('assigned_at gesetzt (#978)', 0, Models\AssetUserLicense::withoutTenantScope()->whereNull('assigned_at')->count());
check('Suspended-SKU: keine freien Plätze (#968)', 0, (int) $sku('OLD')->available_units);
check('aktive SKU: 3 frei', 3, (int) $sku('E3')->available_units);
check('Gastkonto als extern (#969)', 'external', Models\AssetHolder::withoutTenantScope()->where('user_principal_name', 'like', '%#EXT#%')->value('holder_type'));
check('Lauf erfolgreich protokolliert', 'success', Models\AssetLicenseSyncLog::withoutTenantScope()->latest('id')->value('status'));

// ---- Lauf 2: Anna gibt ab, Ben kommt dazu, OLD verschwindet ---------------------------------
$graph->skus = [['skuId' => 'E3', 'skuPartNumber' => 'ENTERPRISEPACK', 'consumedUnits' => 2, 'prepaidUnits' => ['enabled' => 5]]];
$graph->users = [
    ['id' => 'u2', 'userPrincipalName' => 'partner_agentur.de#EXT#@broich.onmicrosoft.com', 'displayName' => 'Partner', 'assignedLicenses' => [['skuId' => 'E3']]],
    ['id' => 'u3', 'userPrincipalName' => 'ben@broich.de', 'displayName' => 'Ben', 'assignedLicenses' => [['skuId' => 'E3']]],
];
$run();
check('Lauf 2: Anna weg, Ben neu', ['ben@broich.de:E3', 'partner_agentur.de#ext#@broich.onmicrosoft.com:E3'], $assignments());
check('verschwundene SKU auf Menge 0 (#968)', [0, 0], [(int) $sku('OLD')->purchased_units, (int) $sku('OLD')->available_units]);
check('verschwundene SKU bleibt im Katalog', true, $sku('OLD') !== null);

// ---- Lauf 3: leere Antwort löscht nichts ----------------------------------------------------
$graph->users = [];
$run();
check('leere Graph-Antwort: Zuweisungen bleiben (Guard)', 2, Models\AssetUserLicense::withoutTenantScope()->count());

check_summary();
