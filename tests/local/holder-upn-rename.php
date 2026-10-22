<?php

/**
 * UPN-Umbenennung im AD (#986) — echter Durchlauf von {@see \Platform\AssetManager\Jobs\ImportTenantUsersJob::handle()}.
 *
 * Fall vom 2026-10-22: O.PARADZE (Tippfehler, Träger mit Lizenz) wurde im AD zu O.PERADZE korrigiert; der
 * Geräte-Sync hatte für das neue Handy schon einen zweiten Träger (derived) angelegt.
 *
 *  1. Umbenennung: der Träger mit derselben Entra-ID heißt danach O.PERADZE — keine Dublette, keine Stilllegung.
 *  2. Die Dublette wird hineingeführt: Gerät, Lizenz, Zuordnung, Telefonie-Platz hängen am einen Träger.
 *  3. Bereits gespaltener Fall (der alte Code hat schon eine Nacht gelaufen: zwei Träger mit derselben
 *     Entra-ID, der alte stillgelegt) heilt im nächsten Lauf.
 *  4. Reine Umbenennung ohne Dublette.
 *  5. Gehört die neue UPN einem anderen Entra-Konto, wird nichts zusammengeführt.
 *
 * Aufruf: php tests/local/holder-upn-rename.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Models;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TenantContext;

$models = [
    Models\AssetConnectorConfig::class, Models\AssetHolder::class, Models\AssetTeamSetting::class, Models\AssetTenant::class,
    Models\AssetDevice::class, Models\AssetUserLicense::class, Models\AssetAssignment::class, Models\AssetItem::class,
    Models\AssetCostLine::class, Models\AssetHandover::class, Models\AssetHolderEvent::class, Models\AssetTelephonySeat::class,
    Models\AssetImportRun::class,
];
foreach ($models as $class) {
    $m = new $class();
    Schema::create($m->getTable(), function (Blueprint $t) use ($m) {
        $t->id();
        foreach (array_unique(array_merge($m->getFillable(), ['team_id', 'tenant_id', 'graph_id', 'source', 'is_active', 'synced_at'])) as $col) {
            $t->text($col)->nullable();
        }
        $t->timestamps();
        $t->softDeletes();
    });
}

const TEAM   = 7;
const TENANT = 1;
const OLD    = 'O.PARADZE@BROICHCATERING.COM';
const NEW_   = 'O.PERADZE@BROICHCATERING.COM';
const GID    = 'entra-object-1';

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Broich', 'is_default' => 1]);
$config = Models\AssetConnectorConfig::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'enabled' => true,
    'azure_tenant_id' => 'tenant-guid', 'client_id' => 'client', 'client_secret' => 'secret',
]);

$graph = new class extends IntuneGraphService {
    public array $users = [];
    public function getUsersWithLicenses($config): ?array { return $this->users; }
};
$run = function () use ($graph, $config): void {
    (new ImportTenantUsersJob($config->id))->handle($graph, app(HolderService::class));
    TenantContext::forceTenant(TENANT);
};
$holders = fn () => Models\AssetHolder::withoutTenantScope()->where('team_id', TEAM)->orderBy('id')->get();
$entra   = fn (string $upn, string $gid = GID, string $name = 'OLEKSANDR PERADZE') =>
    ['id' => $gid, 'userPrincipalName' => $upn, 'displayName' => $name, 'accountEnabled' => true, 'department' => 'LOGISTIK'];
$reset = function () {
    foreach (['asset_holders', 'asset_devices', 'asset_user_licenses', 'asset_assignments', 'asset_telephony_seats'] as $t) {
        DB::table($t)->delete();
    }
};
TenantContext::forceTenant(TENANT);

// ---- 1 + 2: Umbenennung mit Dublette aus dem Geräte-Sync -----------------------------------
$old = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => OLD, 'display_name' => 'OLEKSANDR PARADZE',
    'email' => OLD, 'source' => 'graph', 'graph_id' => GID, 'is_active' => 1, 'holder_type' => 'person', 'department' => 'LOGISTIK', 'job_title' => 'HAUSTECHNIKER']);
$dup = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => NEW_, 'display_name' => 'OLEKSANDR PERADZE',
    'email' => NEW_, 'source' => 'derived', 'is_active' => 1, 'holder_type' => 'person']);
DB::table('asset_user_licenses')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => OLD, 'sku_id' => 'SPB']);
DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => NEW_, 'device_name' => 'O.PARADZE_AndroidEnterprise']);
DB::table('asset_assignments')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $dup, 'assignable_type' => 'device', 'assignable_id' => 1]);
DB::table('asset_telephony_seats')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $dup, 'extension' => '123']);

$graph->users = [$entra(NEW_), $entra('ANNA@BROICHCATERING.COM', 'entra-anna', 'ANNA')];
$run();

$h = $holders()->firstWhere('graph_id', GID);
check('ein Träger für die Person (Dublette weg)', 1, $holders()->where('graph_id', GID)->count() + $holders()->where('id', $dup)->count());
check('der ursprüngliche Träger überlebt und heißt jetzt O.PERADZE', [$old, NEW_], [(int) $h->id, $h->user_principal_name]);
check('E-Mail zieht mit (war die alte UPN)', NEW_, $h->email);
check('nicht stillgelegt', true, (bool) $h->is_active);
check('Abteilung/Position bleiben', ['LOGISTIK', 'HAUSTECHNIKER'], [$h->department, $h->job_title]);
check('Lizenz auf die neue UPN gezogen', [NEW_], DB::table('asset_user_licenses')->pluck('user_principal_name')->all());
check('Gerät auf der neuen UPN', [NEW_], DB::table('asset_devices')->pluck('user_principal_name')->all());
check('Zuordnung hängt am überlebenden Träger', [$old], DB::table('asset_assignments')->pluck('holder_id')->map(fn ($v) => (int) $v)->all());
check('Telefonie-Platz hängt am überlebenden Träger', [$old], DB::table('asset_telephony_seats')->pluck('holder_id')->map(fn ($v) => (int) $v)->all());
check('Protokoll nennt Umbenennung und Zusammenführung', ['1', '1'],
    array_values(array_intersect_key(Models\AssetImportRun::withoutTenantScope()->latest('id')->first()->metrics(),
        array_flip(['Umbenannt (Entra-ID)', 'Dubletten zusammengeführt']))));

// Zweiter Lauf: nichts mehr zu tun.
$run();
check('zweiter Lauf: stabil', [1, NEW_], [$holders()->where('graph_id', GID)->count(), $holders()->firstWhere('graph_id', GID)->user_principal_name]);

// ---- 3: bereits gespalten (alter Code lief eine Nacht) ----------------------------------------
$reset();
$old = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => OLD, 'source' => 'graph',
    'graph_id' => GID, 'is_active' => 0, 'holder_type' => 'person', 'cost_center_id' => 181]);
$new = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => NEW_, 'source' => 'graph',
    'graph_id' => GID, 'is_active' => 1, 'holder_type' => 'person']);
DB::table('asset_user_licenses')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => NEW_, 'sku_id' => 'SPB']);
$graph->users = [$entra(NEW_), $entra('ANNA@BROICHCATERING.COM', 'entra-anna', 'ANNA')];
$run();
$h = $holders()->firstWhere('graph_id', GID);
check('gespalten: ein Träger übrig', 1, $holders()->where('graph_id', GID)->count());
check('gespalten: der ältere überlebt, aktiv, mit neuer UPN', [$old, true, NEW_], [(int) $h->id, (bool) $h->is_active, $h->user_principal_name]);
check('gespalten: gepflegte Kostenstelle bleibt', '181', (string) $h->cost_center_id);
check('gespalten: Lizenz bleibt auf der neuen UPN', [NEW_], DB::table('asset_user_licenses')->pluck('user_principal_name')->all());

// ---- 4: reine Umbenennung ohne Dublette ---------------------------------------------------------
$reset();
$only = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => OLD, 'email' => 'privat@example.de',
    'source' => 'graph', 'graph_id' => GID, 'is_active' => 1, 'holder_type' => 'person']);
DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => OLD]);
$run();
$h = $holders()->firstWhere('graph_id', GID);
check('nur umbenannt: gleiche ID, neue UPN', [$only, NEW_], [(int) $h->id, $h->user_principal_name]);
check('nur umbenannt: gepflegte E-Mail bleibt', 'privat@example.de', $h->email);
check('nur umbenannt: Gerät zieht mit', [NEW_], DB::table('asset_devices')->pluck('user_principal_name')->all());

// ---- 5: neue UPN gehört einem anderen Entra-Konto ----------------------------------------------
$reset();
$a = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => OLD, 'source' => 'graph', 'graph_id' => GID, 'is_active' => 1, 'holder_type' => 'person']);
$b = DB::table('asset_holders')->insertGetId(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => NEW_, 'source' => 'graph', 'graph_id' => 'entra-other', 'is_active' => 1, 'holder_type' => 'person']);
$merged = app(\Platform\AssetManager\Services\HolderMergeService::class)->followRename(TEAM, TENANT, GID, NEW_);
check('fremdes Konto: nichts zusammengeführt oder umbenannt', [false, 0, 2], [$merged['renamed'], $merged['merged'], $holders()->count()]);

check_summary();
