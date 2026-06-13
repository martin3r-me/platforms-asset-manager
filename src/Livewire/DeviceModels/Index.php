<?php

namespace Platform\AssetManager\Livewire\DeviceModels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetVendor;

class Index extends Component
{
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

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    /** owner/admin im aktiven Team? (analog Devices/Show — Preise pflegen ist eine Verwaltungsaktion) */
    protected function canManage(): bool
    {
        $user = Auth::user();
        $role = $user->teams()
            ->where('team_id', $user->currentTeam?->id)
            ->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin'], true);
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
        $this->validate([
            'eMonthly'  => 'nullable|numeric|min:0',
            'ePurchase' => 'nullable|numeric|min:0',
            'eDep'      => 'nullable|integer|min:1',
        ]);

        $m = AssetDeviceModel::where('team_id', $this->teamId())->findOrFail($this->editId);
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
