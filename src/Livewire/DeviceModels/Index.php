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
use Platform\AssetManager\Models\AssetVendor;

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
        $this->editId    = $m->id;
        $this->eMonthly  = $m->monthly_cost;
        $this->ePurchase = $m->purchase_price;
        $this->eDep      = $m->depreciation_months;
        $this->eCostType = $m->cost_type_id;
        $this->eVendor   = $m->vendor_id;
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

        // cost_type_id/vendor_id team-scopen: eine Fremd-Team-ID wird als 422 abgelehnt statt roh
        // als danglender cross-team FK geschrieben (UpsertDeviceModelTool prüft beide ebenso team-scoped).
        $this->validate([
            'eMonthly'  => 'nullable|numeric|min:0',
            'ePurchase' => 'nullable|numeric|min:0',
            'eDep'      => 'nullable|integer|min:1',
            'eCostType' => ['nullable', 'integer', Rule::exists('asset_cost_types', 'id')->where('team_id', $teamId)],
            'eVendor'   => ['nullable', 'integer', Rule::exists('asset_vendors', 'id')->where('team_id', $teamId)],
        ]);

        $m = AssetDeviceModel::where('team_id', $teamId)->findOrFail($this->editId);
        $m->update([
            'monthly_cost'        => $this->eMonthly !== null ? $this->eMonthly : null,
            'purchase_price'      => $this->ePurchase !== null ? $this->ePurchase : null,
            'depreciation_months' => $this->eDep ?: null,
            'cost_type_id'        => $this->eCostType ?: null,
            'vendor_id'           => $this->eVendor ?: null,
        ]);
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

        $models = AssetDeviceModel::where('team_id', $teamId)
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
