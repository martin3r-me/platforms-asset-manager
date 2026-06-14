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
    public string $sortField      = 'monthly_amount';
    public string $sortDirection  = 'desc';

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
        'sortField'    => ['except' => 'monthly_amount'],
        'sortDirection'=> ['except' => 'desc'],
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

    /** Spaltensortierung umschalten: gleiches Feld → Richtung kippen, sonst aufsteigend. */
    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    /** Sortierung auf die Query anwenden — verknüpfte Spalten alphabetisch per Join. */
    protected function applySort($query): void
    {
        $dir = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        match ($this->sortField) {
            'cost_type' => $query
                ->leftJoin('asset_cost_types', 'asset_cost_types.id', '=', 'asset_cost_lines.cost_type_id')
                ->orderBy('asset_cost_types.name', $dir),
            'cost_center' => $query
                ->leftJoin('asset_cost_centers', 'asset_cost_centers.id', '=', 'asset_cost_lines.cost_center_id')
                ->orderBy('asset_cost_centers.code', $dir),
            'vendor' => $query
                ->leftJoin('asset_vendors', 'asset_vendors.id', '=', 'asset_cost_lines.vendor_id')
                ->orderBy('asset_vendors.name', $dir),
            'label'     => $query->orderBy('asset_cost_lines.label', $dir),
            'amount'    => $query->orderBy('asset_cost_lines.amount', $dir),
            'frequency' => $query->orderBy('asset_cost_lines.frequency', $dir),
            default     => $query->orderBy('asset_cost_lines.monthly_amount', $dir),
        };
    }

    /** Filter zurücksetzen (linke Sidebar). */
    public function resetFilters(): void
    {
        $this->reset(['search', 'filterType', 'filterCenter', 'filterVendor', 'filterActive']);
        $this->resetPage();
    }

    public function newLine(): void
    {
        $this->resetEditor();
        $this->showEditor = true;
        $this->dispatch('open-activity');
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
        $this->dispatch('open-activity');
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
        // Wird gerade diese Zeile im rechten Panel bearbeitet → Editor schließen.
        if ($this->editId === $id) {
            $this->resetEditor();
            $this->showEditor = false;
        }
        $this->flash = 'Kostenposition gelöscht.';
    }

    public function toggleActive(int $id): void
    {
        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $line->update(['active' => !$line->active]);
    }

    /** Editor (rechtes Panel) schließen und Auswahl/Highlight lösen. */
    public function cancelEdit(): void
    {
        $this->resetEditor();
        $this->showEditor = false;
        $this->resetValidation();
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

        // Basis mit Filtern (Spalten qualifiziert, da die Sortierung Joins ergänzen kann)
        $base = AssetCostLine::where('asset_cost_lines.team_id', $teamId);

        if ($this->search)       $base->where('asset_cost_lines.label', 'like', '%' . $this->search . '%');
        if ($this->filterType)   $base->where('asset_cost_lines.cost_type_id', $this->filterType);
        if ($this->filterCenter) $base->where('asset_cost_lines.cost_center_id', $this->filterCenter);
        if ($this->filterVendor) $base->where('asset_cost_lines.vendor_id', $this->filterVendor);
        if ($this->filterActive !== '') $base->where('asset_cost_lines.active', $this->filterActive === '1');

        // Summe ohne Sortier-Join berechnen (Join wäre 1:1, aber so bleibt es robust)
        $monthlySum = round((float) (clone $base)->sum('monthly_amount'), 2);

        // Einmalkosten separat (auditierbar): once-Positionen haben monthly_amount=0 und fließen NICHT in
        // die Monatssumme — ihr Rohbetrag würde sonst still verschwinden. Hier als eigener Topf ausgewiesen.
        $oneTimeSum = round((float) (clone $base)->where('asset_cost_lines.frequency', 'once')->sum('amount'), 2);

        $query = (clone $base)
            ->with(['costType', 'costCenter', 'vendor', 'assignee'])
            ->select('asset_cost_lines.*');
        $this->applySort($query);

        $lines = $query->paginate($this->perPage);

        return view('asset-manager::livewire.cost-lines.index', [
            'lines'       => $lines,
            'costTypes'   => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->get(),
            'costCenters' => AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get(),
            'vendors'     => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
            'monthlySum'  => $monthlySum,
            'oneTimeSum'  => $oneTimeSum,
        ])->layout('platform::layouts.app');
    }
}
