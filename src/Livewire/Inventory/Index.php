<?php

namespace Platform\AssetManager\Livewire\Inventory;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Concerns\ScopesToTenant;
use Platform\AssetManager\Services\InventoryService;

/**
 * Gemeinsame, read-only „Inventar"-Sicht über manuelle Assets (asset_items) UND Intune-Geräte
 * (asset_devices). Eine Liste „alle Hardware"; Zeilen-Klick führt auf die jeweils richtige
 * bestehende Detailseite (Assets/Show bzw. Devices/Show). Keine Schreib-/Bulk-Pfade.
 */
class Index extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;
    use ScopesToTenant;

    public string $search           = '';
    public string $filterType       = '';   // '' | 'manual' | 'intune'
    public string $filterAssignment = '';   // '' | 'assigned' | 'unassigned'
    public int    $perPage          = 25;
    public string $sortField        = 'name';
    public string $sortDirection    = 'asc';

    protected $queryString = [
        'search'           => ['except' => ''],
        'filterType'       => ['except' => ''],
        'filterAssignment' => ['except' => ''],
        'perPage'          => ['except' => 25],
        'sortField'        => ['except' => 'name'],
        'sortDirection'    => ['except' => 'asc'],
    ];

    public function updatingSearch(): void           { $this->resetPage(); }
    public function updatingFilterType(): void       { $this->resetPage(); }
    public function updatingFilterAssignment(): void { $this->resetPage(); }
    public function updatingPerPage(): void          { $this->resetPage(); }

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

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterType', 'filterAssignment']);
        $this->resetPage();
    }

    public function render(InventoryService $inventory)
    {
        $teamId = $this->teamId();

        $rows     = $inventory->rows($teamId, $this->selectedTenantId);
        $filtered = $inventory->filter($rows, $this->search, $this->filterType, $this->filterAssignment);
        $sorted   = $inventory->sort($filtered, $this->sortField, $this->sortDirection);
        $items    = $inventory->paginate($sorted, $this->perPage, $this->getPage());

        return view('asset-manager::livewire.inventory.index', [
            'items'         => $items,
            'counts'        => $inventory->counts($teamId, $this->selectedTenantId),
            'totalFiltered' => $sorted->count(),
        ])->layout('platform::layouts.app');
    }
}
