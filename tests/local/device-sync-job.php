<?php

/**
 * Geräte-Sync End-to-End mit Graph-Attrappe: {@see \Platform\AssetManager\Jobs\SyncIntuneDevicesJob::handle()}.
 *
 * Bisher lief kein lokaler Test durch handle() — die Fixes vom 2026-10-19 sitzen aber genau dort:
 *  - #945 Doppel-Enrollment: zwei Intune-Einträge mit gleicher Seriennummer → eine Zeile, kein Hin und Her
 *  - #939 Papierkorb-UPN / Schreibweise → Zuordnung an den richtigen Träger
 *  - #963 verschwundenes Gerät: offene Zuordnung geschlossen, Event `removed`; Wiederkehr `restored`
 *  - #965 Zeitstempel in App-Zeitzone
 *  - #978 vorgeladene Nachschlagetabellen liefern dasselbe Ergebnis wie die Einzel-Queries
 *
 * Schema: aus den $fillable der Models erzeugt (alle Spalten nullable) — SQLite ist typlos genug.
 *
 * Aufruf: php tests/local/device-sync-job.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Models;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TenantContext;

// ---- Schema aus den Models ------------------------------------------------------------------
$models = [
    Models\AssetConnectorConfig::class, Models\AssetDeviceSyncLog::class, Models\AssetDevice::class,
    Models\AssetDeviceSource::class, Models\AssetDeviceModel::class, Models\AssetHolder::class,
    Models\AssetAssignment::class, Models\AssetDeviceEvent::class, Models\AssetTeamSetting::class,
    Models\AssetUserLicense::class, Models\AssetTenant::class, Models\AssetHolderEvent::class,
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
TenantContext::forceTenant(TENANT);

// Träger: Papierkorb-UPN und andere Schreibweise müssen auf diese beiden zeigen.
$anna = Models\AssetHolder::withoutTenantScope()->create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'ANNA@BROICH.DE', 'is_active' => true, 'source' => 'graph']);
$ben  = Models\AssetHolder::withoutTenantScope()->create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'ben@broich.de', 'is_active' => true, 'source' => 'graph']);

/** Graph-Attrappe: liefert, was $devices gerade enthält. */
$graph = new class extends IntuneGraphService {
    public array $devices = [];
    public function getManagedDevices($config): ?array { return $this->devices; }
};

$run = function () use ($graph, $config): void {
    (new SyncIntuneDevicesJob($config->id))->handle($graph, app(HolderService::class));
    TenantContext::forceTenant(TENANT);
};

$dev = fn (string $id, string $serial, string $upn, string $sync = '2026-10-18T08:00:00Z') => [
    'id' => $id, 'deviceName' => "NB-{$serial}", 'serialNumber' => $serial, 'userPrincipalName' => $upn,
    'complianceState' => 'compliant', 'lastSyncDateTime' => $sync, 'enrolledDateTime' => '2026-01-01T10:00:00Z',
];

$devices     = fn () => Models\AssetDevice::withoutTenantScope()->withTrashed()->get();
$openFor     = fn (int $deviceId) => Models\AssetAssignment::withoutTenantScope()->where('assignable_type', 'device')
    ->where('assignable_id', $deviceId)->whereNull('returned_at')->get();
$eventsOf    = fn (string $type) => Models\AssetDeviceEvent::withoutTenantScope()->where('event_type', $type)->count();

// ---- Lauf 1: zwei Geräte, eines doppelt enrolled, Papierkorb-UPN ---------------------------
$graph->devices = [
    $dev('alt-1', 'PF1', 'anna@broich.de', '2026-09-01T08:00:00Z'),
    $dev('neu-1', 'pf1', '0b521d5646ee4b158a9c13727799dbd0ANNA@BROICH.DE', '2026-10-18T08:00:00Z'),
    $dev('dev-2', 'PF2', 'Ben@Broich.de'),
];
$run();

check('Lauf 1: zwei Gerätezeilen (Doppel-Enrollment zusammengeführt)', 2, $devices()->count());
$pf1 = $devices()->firstWhere('serial_number', 'pf1') ?? $devices()->firstWhere('serial_number', 'PF1');
check('Doppel-Enrollment: jüngster Eintrag gewinnt', 'neu-1', $pf1?->intune_id);
check('Papierkorb-UPN → Zuordnung an Anna (#939)', [$anna->id], $openFor($pf1->id)->pluck('holder_id')->map(fn ($v) => (int) $v)->all());
$pf2 = $devices()->firstWhere('serial_number', 'PF2');
check('andere Schreibweise → Zuordnung an Ben', [$ben->id], $openFor($pf2->id)->pluck('holder_id')->map(fn ($v) => (int) $v)->all());
check('kein zusätzlicher Träger angelegt', 2, Models\AssetHolder::withoutTenantScope()->count());
check('Zeitstempel in App-Zeitzone (#965)', '2026-10-18 10:00', $pf1->fresh()->last_check_in_at?->format('Y-m-d H:i'));

// ---- Lauf 2: gleiche Daten → kein Hin und Her ----------------------------------------------
$reenrolledBefore = $eventsOf('reenrolled');
$run();
check('Lauf 2: keine erneute Rotation der Intune-ID (#945)', $reenrolledBefore, $eventsOf('reenrolled'));
check('Lauf 2: weiter genau eine offene Zuordnung', 1, $openFor($pf1->id)->count());

// ---- Lauf 3: PF2 verschwindet ---------------------------------------------------------------
$graph->devices = [$dev('neu-1', 'PF1', 'anna@broich.de')];
$run();
check('verschwundenes Gerät soft-gelöscht', true, $devices()->firstWhere('serial_number', 'PF2')->trashed());
check('offene Zuordnung geschlossen (#963)', 0, $openFor($pf2->id)->count());
check('Event removed geschrieben', 1, $eventsOf('removed'));

// ---- Lauf 4: PF2 kehrt mit anderem Nutzer zurück --------------------------------------------
$graph->devices = [$dev('neu-1', 'PF1', 'anna@broich.de'), $dev('dev-2b', 'PF2', 'anna@broich.de')];
$run();
check('Wiederkehr: restored', false, $devices()->firstWhere('serial_number', 'PF2')->trashed());
check('Event restored geschrieben', 1, $eventsOf('restored'));
check('neue Zuordnung an Anna', [$anna->id], $openFor($pf2->id)->pluck('holder_id')->map(fn ($v) => (int) $v)->all());

check_summary();
