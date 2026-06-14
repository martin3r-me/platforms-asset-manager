<?php

namespace Platform\AssetManager\Livewire\Devices;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;

class Show extends Component
{
    use AuthorizesTeamRole;

    public AssetDevice $device;
    public bool $showRawData = false;

    // Kosten-Override am Gerät
    public bool    $editingCost   = false;
    public ?string $oMonthly      = null;
    public ?string $oPurchase     = null;
    public ?int    $oDep          = null;
    public ?string $oPurchaseDate = null;
    public ?int    $oCostType     = null;
    public ?int    $oCostCenter   = null;
    public ?string $flash         = null;

    public function mount(AssetDevice $device): void
    {
        abort_unless(
            $device->team_id === Auth::user()->currentTeam->id,
            403
        );

        $this->device = $device;
        $this->fillCostForm();
    }

    protected function fillCostForm(): void
    {
        $this->oMonthly      = $this->device->monthly_cost;
        $this->oPurchase     = $this->device->purchase_price;
        $this->oDep          = $this->device->depreciation_months;
        $this->oPurchaseDate = $this->device->purchase_date?->format('Y-m-d');
        $this->oCostType     = $this->device->cost_type_id;
        $this->oCostCenter   = $this->device->cost_center_id;
    }

    /** owner/admin im aktiven Team? (analog Costs/Import) */
    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    public function toggleRawData(): void
    {
        $this->showRawData = !$this->showRawData;
    }

    public function editCost(): void
    {
        $this->fillCostForm();
        $this->editingCost = true;
    }

    public function cancelCost(): void
    {
        $this->editingCost = false;
        $this->fillCostForm();
    }

    public function saveCost(): void
    {
        abort_unless($this->canManage(), 403);

        foreach (['oMonthly', 'oPurchase', 'oDep'] as $f) {
            if ($this->$f === '') $this->$f = null;
        }
        $this->validate([
            'oMonthly'      => 'nullable|numeric|min:0',
            'oPurchase'     => 'nullable|numeric|min:0',
            'oDep'          => 'nullable|integer|min:1',
            'oPurchaseDate' => 'nullable|date',
        ]);

        $this->device->update([
            'monthly_cost'        => $this->oMonthly !== null ? $this->oMonthly : null,
            'purchase_price'      => $this->oPurchase !== null ? $this->oPurchase : null,
            'depreciation_months' => $this->oDep ?: null,
            'purchase_date'       => $this->oPurchaseDate ?: null,
            'cost_type_id'        => $this->oCostType ?: null,
            'cost_center_id'      => $this->oCostCenter ?: null,
        ]);
        $this->device->refresh();
        $this->editingCost = false;
        $this->flash = 'Geräte-Kosten gespeichert.';
    }

    public function render()
    {
        $teamId = $this->device->team_id;

        $activities = AssetDeviceSyncLog::where('team_id', $teamId)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        return view('asset-manager::livewire.devices.show', [
            'device'             => $this->device,
            'activities'         => $activities,
            'canManage'          => $this->canManage(),
            'resolvedCost'       => $this->device->resolvedMonthlyCost(),
            'resolvedCostTypeId' => $this->device->resolvedCostTypeId(),
            'deviceModel'        => $this->device->deviceModel(),
            'costTypes'          => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->orderBy('name')->get(),
            'costCenters'        => AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get(),
        ])->layout('platform::layouts.app');
    }
}
