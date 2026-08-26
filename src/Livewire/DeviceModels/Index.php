<?php

namespace Platform\AssetManager\Livewire\DeviceModels;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetDeviceModelCost;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\TenantContext;

/**
 * Hardware-Katalog + tenant-eigene Kosten.
 *
 * Der Katalog (Hersteller/Modell) ist **team-weit** — ein MacBook Air M2 ist bei jedem Kunden dasselbe
 * Gerät. Die Kosten (Leasingrate, Kaufpreis, Kostenart, Kreditor) sind pro Kunde verhandelt und liegen
 * in `asset_device_model_costs` je Tenant (docs/adr/0016). Diese Seite zeigt und pflegt die Kosten des
 * **aktiven** Tenants; `depreciation_months` bleibt am Modell (Nutzungsdauer = Hardware-Eigenschaft).
 */
class Index extends Component
{
    use ResolvesCurrentTeam;
    use AuthorizesTeamRole;

    public ?int    $editId    = null;
    public ?string $eMonthly  = null;
    public ?string $ePurchase = null;
    public ?int    $eDep      = null;
    public ?int    $eCostType = null;
    public ?int    $eVendor   = null;

    // Anlage (für Modelle, die der Sync noch nicht gebracht hat)
    public string  $newManufacturer = '';
    public string  $newModel        = '';
    public ?string $flash           = null;

    /** owner/admin im aktiven Team? (analog Devices/Show — Preise pflegen ist eine Verwaltungsaktion) */
    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    public function edit(int $id): void
    {
        $m = AssetDeviceModel::where('team_id', $this->teamId())->findOrFail($id);
        $cost = $m->costFor($this->activeTenantId());

        $this->editId    = $m->id;
        $this->eMonthly  = $cost?->monthly_cost;
        $this->ePurchase = $cost?->purchase_price;
        $this->eDep      = $m->depreciation_months;
        $this->eCostType = $cost?->cost_type_id;
        $this->eVendor   = $cost?->vendor_id;
    }

    /** Tenant, dessen Kosten diese Seite pflegt. */
    protected function activeTenantId(): int
    {
        return TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());
    }

    public function saveEdit(): void
    {
        abort_unless($this->canManage(), 403);

        foreach (['eMonthly', 'ePurchase', 'eDep'] as $f) {
            if ($this->$f === '') $this->$f = null;
        }
        // Leere FK-Selects (0/'') zu null normalisieren, damit nullable greift (keine id=0-Validierung).
        if (! $this->eCostType) $this->eCostType = null;
        if (! $this->eVendor)   $this->eVendor   = null;

        $teamId = $this->teamId();

        $tenantId = $this->activeTenantId();

        // cost_type_id/vendor_id TENANT-scopen: Kostenarten und Kreditoren sind seit ADR 0016
        // tenant-gebunden — eine Fremd-Tenant-ID wird als 422 abgelehnt statt roh als
        // grenzüberschreitender FK geschrieben (UpsertDeviceModelTool prüft ebenso).
        $this->validate([
            'eMonthly'  => 'nullable|numeric|min:0',
            'ePurchase' => 'nullable|numeric|min:0',
            'eDep'      => 'nullable|integer|min:1',
            'eCostType' => ['nullable', 'integer', Rule::exists('asset_cost_types', 'id')->where('tenant_id', $tenantId)],
            'eVendor'   => ['nullable', 'integer', Rule::exists('asset_vendors', 'id')->where('tenant_id', $tenantId)],
        ]);

        $m = AssetDeviceModel::where('team_id', $teamId)->findOrFail($this->editId);

        // Nutzungsdauer bleibt am Modell (team-weit), die Preise wandern in die Tenant-Zeile.
        $m->update(['depreciation_months' => $this->eDep ?: null]);

        AssetDeviceModelCost::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'device_model_id' => $m->id],
            [
                'team_id'        => $teamId,
                'monthly_cost'   => $this->eMonthly !== null ? $this->eMonthly : null,
                'purchase_price' => $this->ePurchase !== null ? $this->ePurchase : null,
                'cost_type_id'   => $this->eCostType ?: null,
                'vendor_id'      => $this->eVendor ?: null,
            ],
        );

        $this->editId = null;
        $this->flash  = 'Geräte-Modell gespeichert.';
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate(['newModel' => 'required|string|max:255']);
        AssetDeviceModel::firstOrCreate([
            'team_id'      => $this->teamId(),
            'manufacturer' => trim($this->newManufacturer) ?: null,
            'model'        => trim($this->newModel),
        ]);
        $this->reset(['newManufacturer', 'newModel']);
        $this->flash = 'Geräte-Modell angelegt.';
    }

    public function delete(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $m = AssetDeviceModel::where('team_id', $this->teamId())->findOrFail($id);
        $m->delete();
        if ($this->editId === $id) $this->editId = null;
        $this->flash = 'Geräte-Modell gelöscht (der Sync legt es bei Bedarf neu an).';
    }

    public function render()
    {
        $teamId = $this->teamId();

        // Geräte je (Hersteller, Modell) zählen
        $counts = AssetDevice::where('team_id', $teamId)
            ->selectRaw('manufacturer, model, COUNT(*) as c')
            ->groupBy('manufacturer', 'model')->get()
            ->keyBy(fn($r) => mb_strtolower(trim((string) $r->manufacturer)) . '|' . mb_strtolower(trim((string) $r->model)));

        // Kosten des aktiven Tenants eager laden — die cost-Relation trägt den Global Scope, filtert
        // also von sich aus auf den aktiven Tenant. Bewusst als Relation und NICHT als transiente
        // Attribute am Modell: die Spalten existieren dort nicht mehr, ein späteres save() würde
        // versuchen, sie zu schreiben.
        // costType/vendor mit vorladen: die Liste zeigt beide Namen je Zeile (index.blade.php) und
        // ist bewusst unpaginiert — ohne das wären es zwei Queries je Modell (80 Modelle ≈ 160).
        $models = AssetDeviceModel::where('team_id', $teamId)
            ->with(['cost.costType', 'cost.vendor'])
            ->orderBy('manufacturer')->orderBy('model')->get();

        $models->each(function ($m) use ($counts) {
            $key = mb_strtolower(trim((string) $m->manufacturer)) . '|' . mb_strtolower(trim((string) $m->model));
            $m->device_count = (int) ($counts[$key]->c ?? 0);
        });

        return view('asset-manager::livewire.device-models.index', [
            'models'    => $models,
            'costTypes' => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->orderBy('name')->get(),
            'vendors'   => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
            'canManage' => $this->canManage(),
        ])->layout('platform::layouts.app');
    }
}
