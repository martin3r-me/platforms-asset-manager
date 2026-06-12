<?php

namespace Platform\AssetManager\Livewire\CostLines;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\CostBootstrapService;

class Index extends Component
{
    use WithPagination;

    public string $search        = '';
    public ?int   $filterType     = null;
    public ?int   $filterCenter   = null;
    public ?int   $filterVendor   = null;
    public string $filterActive   = '';  // ''|'1'|'0'
    public int    $perPage        = 30;

    // Editor (Create/Update)
    public bool    $showEditor    = false;
    public ?int    $editId        = null;
    public ?int    $fCostType     = null;
    public string  $fCostCenter   = '';   // Code
    public ?int    $fVendor       = null;
    public string  $fLabel        = '';
    public string  $fAmount       = '';
    public string  $fFrequency    = 'monthly';
    public bool    $fActive       = true;
    public ?string $flash         = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'filterType'   => ['except' => null],
        'filterCenter' => ['except' => null],
        'filterVendor' => ['except' => null],
        'filterActive' => ['except' => ''],
    ];

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterType(): void   { $this->resetPage(); }
    public function updatingFilterCenter(): void { $this->resetPage(); }
    public function updatingFilterVendor(): void { $this->resetPage(); }
    public function updatingFilterActive(): void { $this->resetPage(); }

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function newLine(): void
    {
        $this->resetEditor();
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId      = $line->id;
        $this->fCostType   = $line->cost_type_id;
        $this->fCostCenter = $line->costCenter?->code ?? '';
        $this->fVendor     = $line->vendor_id;
        $this->fLabel      = $line->label;
        $this->fAmount     = (string) $line->amount;
        $this->fFrequency  = $line->frequency;
        $this->fActive     = (bool) $line->active;
        $this->showEditor  = true;
    }

    public function save(CostBootstrapService $bootstrap): void
    {
        $this->validate([
            'fCostType'  => 'required|exists:asset_cost_types,id',
            'fLabel'     => 'required|string|max:255',
            'fAmount'    => 'required|numeric',
            'fFrequency' => 'required|in:monthly,quarterly,yearly,once',
            'fVendor'    => 'nullable|exists:asset_vendors,id',
        ]);

        $teamId = $this->teamId();
        $type   = AssetCostType::where('team_id', $teamId)->find($this->fCostType);
        $center = $bootstrap->resolveCostCenter($teamId, $this->fCostCenter ?: null);

        $data = [
            'team_id'           => $teamId,
            'cost_type_id'      => $this->fCostType,
            'vendor_id'         => $this->fVendor ?: $type?->vendor_default_id,
            'cost_center_id'    => $center?->id,
            'label'             => $this->fLabel,
            'amount'            => (float) $this->fAmount,
            'currency'          => 'EUR',
            'frequency'         => $this->fFrequency,
            'accounting_system' => $type?->system_default,
            'active'            => $this->fActive,
        ];

        if ($this->editId) {
            $line = AssetCostLine::where('team_id', $teamId)->findOrFail($this->editId);
            $line->update($data);
            $this->flash = 'Kostenposition aktualisiert.';
        } else {
            $data['source'] = 'manual';
            AssetCostLine::create($data);
            $this->flash = 'Kostenposition angelegt.';
        }

        $this->resetEditor();
        $this->showEditor = false;
    }

    public function delete(int $id): void
    {
        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $line->delete();
        $this->flash = 'Kostenposition gelöscht.';
    }

    public function toggleActive(int $id): void
    {
        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $line->update(['active' => !$line->active]);
    }

    protected function resetEditor(): void
    {
        $this->reset(['editId', 'fCostType', 'fCostCenter', 'fVendor', 'fLabel', 'fAmount']);
        $this->fFrequency = 'monthly';
        $this->fActive    = true;
    }

    public function render()
    {
        $teamId = $this->teamId();

        $query = AssetCostLine::where('team_id', $teamId)
            ->with(['costType', 'costCenter', 'vendor', 'assignee']);

        if ($this->search)       $query->where('label', 'like', '%' . $this->search . '%');
        if ($this->filterType)   $query->where('cost_type_id', $this->filterType);
        if ($this->filterCenter) $query->where('cost_center_id', $this->filterCenter);
        if ($this->filterVendor) $query->where('vendor_id', $this->filterVendor);
        if ($this->filterActive !== '') $query->where('active', $this->filterActive === '1');

        $lines = $query->orderByDesc('monthly_amount')->paginate($this->perPage);

        return view('asset-manager::livewire.cost-lines.index', [
            'lines'       => $lines,
            'costTypes'   => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->get(),
            'costCenters' => AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get(),
            'vendors'     => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
            'monthlySum'  => round((float) $query->sum('monthly_amount'), 2),
        ])->layout('platform::layouts.app');
    }
}
