<?php

namespace Platform\AssetManager\Livewire\CostTypes;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;

class Index extends Component
{
    public ?int   $editId       = null;
    public string $eName        = '';
    public ?int   $eVendor      = null;
    public string $eSystem      = '';
    public string $eFrequency   = 'monthly';
    public bool   $ePerEmployee = false;
    public string $eAggSource   = 'cost_line';

    // Anlage
    public string $newName      = '';
    public string $newFrequency = 'monthly';
    public string $newAggSource = 'cost_line';

    public ?string $flash       = null;

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function create(): void
    {
        $this->validate([
            'newName'      => 'required|string|max:255',
            'newFrequency' => 'required|in:monthly,quarterly,yearly,once',
            'newAggSource' => 'required|in:cost_line,hardware_afa,ms_license,asset_device',
        ]);
        $teamId = $this->teamId();

        AssetCostType::create([
            'team_id'            => $teamId,
            'key'                => $this->uniqueKey($teamId, $this->newName),
            'name'               => trim($this->newName),
            'sort_order'         => (int) ((AssetCostType::where('team_id', $teamId)->max('sort_order') ?? 0) + 10),
            'frequency_default'  => $this->newFrequency,
            'aggregation_source' => $this->newAggSource,
            'is_per_employee'    => false,
        ]);
        $this->reset(['newName', 'newFrequency', 'newAggSource']);
        $this->flash = 'Kostenart angelegt.';
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
        $this->eAggSource   = $t->aggregation_source;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'eName'      => 'required|string|max:255',
            'eFrequency' => 'required|in:monthly,quarterly,yearly,once',
            'eAggSource' => 'required|in:cost_line,hardware_afa,ms_license,asset_device',
        ]);
        $t = AssetCostType::where('team_id', $this->teamId())->findOrFail($this->editId);
        $t->update([
            'name'               => $this->eName,
            'vendor_default_id'  => $this->eVendor ?: null,
            'system_default'     => $this->eSystem ?: null,
            'frequency_default'  => $this->eFrequency,
            'is_per_employee'    => $this->ePerEmployee,
            'aggregation_source' => $this->eAggSource,
        ]);
        $this->editId = null;
        $this->flash  = 'Kostenart gespeichert.';
    }

    /** Eindeutigen Slug-Key je Team aus dem Namen ableiten (key ist intern, NOT NULL). */
    protected function uniqueKey(int $teamId, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'kostenart';
        $key  = $base;
        $i    = 2;
        while (AssetCostType::where('team_id', $teamId)->where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }
        return $key;
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
