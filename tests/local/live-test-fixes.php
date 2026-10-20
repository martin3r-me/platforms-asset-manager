<?php

/**
 * Befunde aus dem Live-Test vom 2026-10-20 (Doc 361):
 *
 *  - #980 Inventar-Detail: „Zugeordnet" ohne Träger wird abgelehnt — mit Fehlermeldung statt
 *         „Details gespeichert.", und der Status bleibt.
 *  - #981 Träger-Detail: ohne Controlling keine Beträge (pro Monat, Geräte, Lizenzen), Kostendaten
 *         werden gar nicht erst gerechnet; mit Controlling unverändert.
 *  - #982 Geräteausgabe löschen: offene Positionen bekommen ein Gegenereignis „Ausgabe gelöscht" im
 *         Geräteverlauf (die Leiche in device.GET prüft mcp-tools-smoke.php).
 *
 * Schema: aus den $fillable der Models erzeugt (alle Spalten nullable).
 *
 * Aufruf: php tests/local/live-test-fixes.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;

$models = [
    Models\AssetItem::class, Models\AssetCategory::class, Models\AssetAssignment::class, Models\AssetHolder::class,
    Models\AssetDevice::class, Models\AssetDeviceModel::class, Models\AssetDeviceModelCost::class, Models\AssetCostType::class,
    Models\AssetUserLicense::class, Models\AssetLicenseContractLine::class, Models\AssetLicenseSku::class,
    Models\AssetHandover::class, Models\AssetHandoverLine::class, Models\AssetCostLine::class, Models\AssetTelephonySeat::class,
    Models\AssetHolderEvent::class, Models\AssetTeamSetting::class, Models\AssetTenant::class, Models\AssetDeviceEvent::class,
];
foreach ($models as $class) {
    $m = new $class();
    if (Schema::hasTable($m->getTable())) {
        continue;
    }
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
TenantContext::forceTenant(TENANT);
Gate::before(fn (?\Illuminate\Contracts\Auth\Authenticatable $user = null) => true); // Gast darf alles — nur für diesen Test

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Broich', 'is_default' => 1]);
DB::table('asset_categories')->insert(['id' => 1, 'name' => 'Sonstiges']);
DB::table('asset_holders')->insert(['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'anna@k.de', 'display_name' => 'Anna', 'is_active' => 1]);

// ---- #980 ------------------------------------------------------------------------------------
DB::table('asset_items')->insert(['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'category_id' => 1, 'name' => 'Headset', 'status' => 'lost', 'source' => 'manual']);

$show = new class extends Platform\AssetManager\Livewire\Inventory\Show {
    protected function guardManualWrite(): Models\AssetItem { return $this->item; }
};
$show->item          = Models\AssetItem::withoutTenantScope()->find(1);
$show->eCategoryId   = 1;
$show->eName         = 'Headset';
$show->eStatus       = 'assigned';
$show->showEdit      = true;
$show->saveEdit(app(AssetWriteService::class));

check('#980: Fehler am Statusfeld', true, $show->getErrorBag()->has('eStatus'));
check('#980: keine Erfolgsmeldung', null, $show->flash);
check('#980: Modal bleibt offen', true, $show->showEdit);
check('#980: Status unverändert', 'lost', Models\AssetItem::withoutTenantScope()->find(1)->status);

$show->resetErrorBag();
$show->eStatus = 'in_stock';
$show->saveEdit(app(AssetWriteService::class));
check('#980: gültiger Wechsel speichert weiter', ['in_stock', 'Details gespeichert.'], [Models\AssetItem::withoutTenantScope()->find(1)->status, $show->flash]);

// ---- #981 ------------------------------------------------------------------------------------
DB::table('asset_cost_types')->insert(['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'lap', 'name' => 'Lap', 'aggregation_source' => 'asset_device']);
DB::table('asset_devices')->insert(['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'device_name' => 'NB-1', 'user_principal_name' => 'anna@k.de', 'cost_type_id' => 1, 'monthly_cost' => 21.36]);
DB::table('asset_license_skus')->insert(['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'sku_id' => 'E3', 'sku_part_number' => 'E3', 'unit_price' => 15.14]);
DB::table('asset_user_licenses')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'sku_id' => 'E3', 'user_principal_name' => 'anna@k.de', 'sku_part_number' => 'E3']);

$render = function (bool $controlling): array {
    DB::table('asset_team_settings')->delete();
    DB::table('asset_team_settings')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'controlling_enabled' => $controlling ? 1 : 0]);
    ControllingContext::forget();
    // Mandantengrenze braucht den eingeloggten User — hier nicht Gegenstand des Tests.
    $c = new class extends Platform\AssetManager\Livewire\Holders\Show {
        protected function assertTenantBoundary(?Illuminate\Database\Eloquent\Model $model): void {}
    };
    $c->mount(Models\AssetHolder::withoutTenantScope()->find(1));
    $view = $c->render();

    // Livewire reicht die öffentlichen Properties erst beim eigenen Rendern an die View weiter.
    return ['data' => $view->getData(), 'html' => $view->with($c->all())->render()];
};

// Die View baut Links über route() — die Modul-Routen registrieren (ohne Middleware-Gruppe).
Illuminate\Support\Facades\Route::group([], dirname(__DIR__, 2) . '/routes/web.php');
app('router')->getRoutes()->refreshNameLookups();
\Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag());

$on = $render(true);
check('#981 an: Gerätekosten gerechnet', 21.36, round((float) $on['data']['deviceCost'], 2));
check('#981 an: „pro Monat" sichtbar', true, str_contains($on['html'], 'pro Monat'));
check('#981 an: Gerät und Lizenz mit Betrag', true, str_contains($on['html'], '21,36 €') && str_contains($on['html'], '15,14 €'));

$off = $render(false);
check('#981 aus: keine Gerätezeilen gerechnet', 0, count($off['data']['deviceRows']));
check('#981 aus: Summen 0', [0.0, 0.0, 0.0], [(float) $off['data']['deviceCost'], (float) $off['data']['licenseCost'], (float) $off['data']['totalCost']]);
check('#981 aus: kein „pro Monat"', false, str_contains($off['html'], 'pro Monat'));
check('#981 aus: kein Eurobetrag auf der Seite', 0, preg_match_all('/\d+,\d{2}\s*€/u', $off['html']));

// ---- #982 ------------------------------------------------------------------------------------
DB::table('asset_handovers')->insert(['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => 1, 'issued_at' => '2026-10-20', 'status' => 'open']);
DB::table('asset_handover_lines')->insert([
    ['tenant_id' => TENANT, 'handover_id' => 1, 'asset_device_id' => 1, 'returned_at' => null],
]);
$handovers = new class extends Platform\AssetManager\Livewire\Handovers\Index {
    public function teamId(): int { return TEAM; }
};
$handovers->delete(1);

check('#982: Protokoll weich gelöscht', true, Models\AssetHandover::withoutTenantScope()->withTrashed()->find(1)->trashed());
check('#982: Gegenereignis im Geräteverlauf', ['handover_deleted'], DB::table('asset_device_events')->pluck('event_type')->all());
check('#982: Label', 'Ausgabe gelöscht', Models\AssetDeviceEvent::withoutTenantScope()->first()?->eventLabel());

check_summary();
