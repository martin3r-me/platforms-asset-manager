<?php

namespace Platform\AssetManager\Livewire\CostCenters;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCompany;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Services\CostAggregationService;

class Index extends Component
{
    public ?int    $editId    = null;
    public string  $eName     = '';
    public ?int    $eCompany  = null;
    public bool    $eActive   = true;

    // Anlage
    public string  $newCode    = '';
    public ?int    $newCompany = null;
    public ?string $flash      = null;

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function edit(int $id): void
    {
        $cc = AssetCostCenter::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId   = $cc->id;
        $this->eName    = $cc->name ?? '';
        $this->eCompany = $cc->company_id;
        $this->eActive  = (bool) $cc->is_active;
    }

    public function saveEdit(): void
    {
        $cc = AssetCostCenter::where('team_id', $this->teamId())->findOrFail($this->editId);
        $cc->update([
            'name'       => $this->eName ?: null,
            'company_id' => $this->eCompany ?: null,
            'is_active'  => $this->eActive,
        ]);
        $this->editId = null;
        $this->flash  = 'Kostenstelle gespeichert.';
    }

    public function create(): void
    {
        $this->validate(['newCode' => 'required|string|max:50']);
        $teamId = $this->teamId();

        AssetCostCenter::firstOrCreate(
            ['team_id' => $teamId, 'code' => trim($this->newCode)],
            ['company_id' => $this->newCompany ?: null]
        );
        $this->reset(['newCode', 'newCompany']);
        $this->flash = 'Kostenstelle angelegt.';
    }

    public function render(CostAggregationService $service)
    {
        $teamId = $this->teamId();

        $companies = AssetCompany::where('team_id', $teamId)->orderBy('sort_order')->get();
        $centers   = AssetCostCenter::where('team_id', $teamId)
            ->withCount('employees')
            ->orderBy('code')->get()
            ->groupBy('company_id');

        return view('asset-manager::livewire.cost-centers.index', [
            'companies' => $companies,
            'centers'   => $centers,
        ])->layout('platform::layouts.app');
    }
}
