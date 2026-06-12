<?php

namespace Platform\AssetManager\Livewire\CostTypes;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;

class Index extends Component
{
    public ?int   $editId      = null;
    public string $eName       = '';
    public ?int   $eVendor     = null;
    public string $eSystem     = '';
    public string $eFrequency  = 'monthly';
    public bool   $ePerEmployee = false;
    public ?string $flash      = null;

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function edit(int $id): void
    {
        $t = AssetCostType::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId       = $t->id;
        $this->eName        = $t->name;
        $this->eVendor      = $t->vendor_default_id;
        $this->eSystem      = $t->system_default ?? '';
        $this->eFrequency   = $t->frequency_default;
        $this->ePerEmployee = (bool) $t->is_per_employee;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'eName'      => 'required|string|max:255',
            'eFrequency' => 'required|in:monthly,quarterly,yearly,once',
        ]);
        $t = AssetCostType::where('team_id', $this->teamId())->findOrFail($this->editId);
        $t->update([
            'name'              => $this->eName,
            'vendor_default_id' => $this->eVendor ?: null,
            'system_default'    => $this->eSystem ?: null,
            'frequency_default' => $this->eFrequency,
            'is_per_employee'   => $this->ePerEmployee,
        ]);
        $this->editId = null;
        $this->flash  = 'Kostenart gespeichert.';
    }

    public function render()
    {
        $teamId = $this->teamId();

        return view('asset-manager::livewire.cost-types.index', [
            'types'   => AssetCostType::where('team_id', $teamId)->withCount('costLines')->orderBy('sort_order')->get(),
            'vendors' => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
        ])->layout('platform::layouts.app');
    }
}
