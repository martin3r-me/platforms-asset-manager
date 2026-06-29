<?php

namespace Platform\AssetManager\Livewire\Inventory;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Concerns\ScopesToTenant;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\InventoryService;

/**
 * Vereinte „Inventar"-Hauptliste über manuelle Assets (asset_items) UND Intune-Geräte
 * (asset_devices) — das kanonische Dach über beide Hardware-Welten (E1). Die Liste selbst ist
 * read-only (Zeilen-Klick führt über InventoryRow::detailRoute auf die typ-richtige Detailseite);
 * der EINZIGE Schreibpfad hier ist das Anlage-Modal für **manuelle** Assets — Geräte kommen
 * ausschließlich aus dem Intune-Sync und werden nie manuell angelegt (E7).
 */
class Index extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;
    use ScopesToTenant;

    public string $search           = '';
    public string $filterType       = '';   // '' | 'manual' | 'intune'
    public string $filterAssignment = '';   // '' | 'assigned' | 'unassigned'
    public string $filterStatus     = '';   // '' | item-status | device-lifecycle (statusSortKey)
    public ?int   $filterCategory   = null; // nur manuelle Assets
    public ?int   $filterCostCenter = null; // nur Geräte
    public int    $perPage          = 25;
    public string $sortField        = 'name';
    public string $sortDirection    = 'asc';

    // --- Anlage-Modal (nur manuelle Assets, E7) ---
    public bool    $showCreate          = false;
    public ?int    $cCategoryId         = null;
    public string  $cName               = '';
    public string  $cManufacturer       = '';
    public string  $cModel              = '';
    public string  $cSerialNumber       = '';
    public ?int    $cAssigneeId         = null;
    public string  $cStatus             = 'in_stock';
    public ?string $cPurchaseDate       = null;
    public ?string $cPurchasePrice      = null;
    public ?int    $cDepreciationMonths = null;
    public string  $cNotes              = '';
    public ?string $flash               = null;

    protected $queryString = [
        'search'           => ['except' => ''],
        'filterType'       => ['except' => ''],
        'filterAssignment' => ['except' => ''],
        'filterStatus'     => ['except' => ''],
        'filterCategory'   => ['except' => null],
        'filterCostCenter' => ['except' => null],
        'perPage'          => ['except' => 25],
        'sortField'        => ['except' => 'name'],
        'sortDirection'    => ['except' => 'asc'],
    ];

    public function mount(): void
    {
        // Deep-Link von der alten /assets/create-Route (Redirect mit ?create=1) öffnet das Modal.
        if (request()->boolean('create') && Gate::allows('create', AssetItem::class)) {
            $this->showCreate = true;
        }
    }

    public function updatingSearch(): void           { $this->resetPage(); }
    public function updatingFilterType(): void       { $this->resetPage(); }
    public function updatingFilterAssignment(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void     { $this->resetPage(); }
    public function updatingFilterCategory(): void   { $this->resetPage(); }
    public function updatingFilterCostCenter(): void { $this->resetPage(); }
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
        $this->reset(['search', 'filterType', 'filterAssignment', 'filterStatus', 'filterCategory', 'filterCostCenter']);
        $this->resetPage();
    }

    /** Sind irgendwelche Filter aktiv? (steuert „Zurücksetzen" + Empty-State-Text in der View) */
    public function getHasFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->filterType !== ''
            || $this->filterAssignment !== ''
            || $this->filterStatus !== ''
            || $this->filterCategory !== null
            || $this->filterCostCenter !== null;
    }

    // ---------------------------------------------------------------------
    // Anlage-Modal (manuelle Assets)
    // ---------------------------------------------------------------------

    public function openCreate(): void
    {
        Gate::authorize('create', AssetItem::class);
        $this->resetCreateForm();
        $this->showCreate = true;
    }

    public function updatedCCategoryId(): void
    {
        // AfA-Default aus der Kategorie übernehmen, wenn noch nicht gesetzt (wie Assets/Create).
        if ($this->cCategoryId && ! $this->cDepreciationMonths) {
            $cat = AssetCategory::find($this->cCategoryId);
            if ($cat && $cat->default_depreciation_months) {
                $this->cDepreciationMonths = $cat->default_depreciation_months;
            }
        }
    }

    public function createItem(AssetWriteService $writer): void
    {
        Gate::authorize('create', AssetItem::class);

        $teamId = $this->teamId();

        $this->validate([
            'cCategoryId'         => 'required|exists:asset_categories,id',
            'cName'               => 'required|string|max:255',
            'cManufacturer'       => 'nullable|string|max:255',
            'cModel'              => 'nullable|string|max:255',
            'cSerialNumber'       => 'nullable|string|max:255',
            // Mitarbeiter MUSS zum eigenen Team gehören (sonst danglende cross-team FK).
            'cAssigneeId'         => ['nullable', 'integer', Rule::exists('asset_employees', 'id')->where('team_id', $teamId)],
            'cStatus'             => 'required|in:in_stock,assigned,retired,lost',
            'cPurchaseDate'       => 'nullable|date',
            'cPurchasePrice'      => 'nullable|numeric|min:0',
            'cDepreciationMonths' => 'nullable|integer|min:1|max:240',
            'cNotes'              => 'nullable|string',
        ]);

        $item = $writer->createItem([
            'categoryId'         => $this->cCategoryId,
            'name'               => $this->cName,
            'manufacturer'       => $this->cManufacturer,
            'model'              => $this->cModel,
            'serialNumber'       => $this->cSerialNumber,
            'assigneeId'         => $this->cAssigneeId,
            'status'             => $this->cStatus,
            'purchaseDate'       => $this->cPurchaseDate,
            'purchasePrice'      => $this->cPurchasePrice,
            'depreciationMonths' => $this->cDepreciationMonths,
            'notes'              => $this->cNotes,
        ], $teamId, (int) Auth::id());

        $this->showCreate = false;
        $this->resetCreateForm();
        $this->resetPage();
        $this->flash = "Asset „{$item->name}“ angelegt.";
    }

    protected function resetCreateForm(): void
    {
        $this->reset([
            'cCategoryId', 'cName', 'cManufacturer', 'cModel', 'cSerialNumber',
            'cAssigneeId', 'cStatus', 'cPurchaseDate', 'cPurchasePrice', 'cDepreciationMonths', 'cNotes',
        ]);
    }

    public function render(InventoryService $inventory)
    {
        $teamId = $this->teamId();

        $rows     = $inventory->rows($teamId, $this->selectedTenantId);
        $filtered = $inventory->filter(
            $rows,
            $this->search,
            $this->filterType,
            $this->filterAssignment,
            $this->filterStatus,
            $this->filterCategory,
            $this->filterCostCenter,
        );
        $sorted   = $inventory->sort($filtered, $this->sortField, $this->sortDirection);
        $items    = $inventory->paginate($sorted, $this->perPage, $this->getPage());

        return view('asset-manager::livewire.inventory.index', [
            'items'         => $items,
            'counts'        => $inventory->counts($teamId, $this->selectedTenantId),
            'totalFiltered' => $sorted->count(),
            'categories'    => AssetCategory::orderBy('sort_order')->get(),
            'employees'     => AssetEmployee::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get(),
            'costCenters'   => AssetCostCenter::where('team_id', $teamId)->where('is_active', true)->orderBy('name')->get(),
        ])->layout('platform::layouts.app');
    }
}
