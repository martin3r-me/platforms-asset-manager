<?php

namespace Platform\AssetManager\Livewire\Assets;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Concerns\ScopesToTenant;
use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\TenantContext;

class Index extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;
    use ScopesToTenant;

    public string $search          = '';
    public string $filterCategory  = '';
    public string $filterStatus    = '';
    public string $filterSource    = '';
    public ?int   $filterAssignee  = null;
    public int    $perPage         = 25;
    public string $sortField       = 'name';
    public string $sortDirection   = 'asc';

    /** Bulk-Auswahl (Array von AssetItem-IDs als Strings) */
    public array $selected = [];
    public bool  $selectPage = false;

    /** Bulk-Aktions-Inputs */
    public ?int   $bulkAssignee = null;
    public string $bulkStatus   = '';
    public ?string $bulkResult  = null;

    /** Bulk-Anlage */
    public bool    $showBulkCreate     = false;
    public ?int    $bcCategoryId       = null;
    public string  $bcName             = '';
    public string  $bcManufacturer     = '';
    public string  $bcModel            = '';
    public int     $bcQuantity         = 1;
    public ?int    $bcAssigneeId       = null;
    public ?string $bcPurchasePrice    = null;
    public ?int    $bcDepreciationMonths = null;

    protected $queryString = [
        'search'         => ['except' => ''],
        'filterCategory' => ['except' => ''],
        'filterStatus'   => ['except' => ''],
        'filterSource'   => ['except' => ''],
        'filterAssignee' => ['except' => null],
        'perPage'        => ['except' => 25],
    ];

    public function updatingSearch(): void         { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterCategory(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterStatus(): void   { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterSource(): void   { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterAssignee(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingPage(): void           { $this->selectPage = false; }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function clearSelection(): void
    {
        $this->selected   = [];
        $this->selectPage = false;
        $this->bulkResult = null;
    }

    /** Wählt alle aktuell sichtbaren (gefilterten) IDs aus. */
    public function selectAllFiltered(): void
    {
        $this->selected = $this->filteredQuery()
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->toArray();
    }

    public function bulkAssign(): void
    {
        Gate::authorize('create', AssetItem::class);
        if (empty($this->selected) || !$this->bulkAssignee) return;

        $employee = AssetEmployee::where('team_id', $this->teamId())->forTenant($this->selectedTenantId)->find($this->bulkAssignee);
        if (!$employee) return;

        $items = AssetItem::where('team_id', $this->teamId())->forTenant($this->selectedTenantId)->whereIn('id', $this->selected)->get();
        foreach ($items as $item) {
            $item->assignTo($employee);
        }

        $this->bulkResult = count($items) . ' Asset(s) an ' . $employee->name . ' zugewiesen.';
        $this->selected = [];
        $this->selectPage = false;
        $this->bulkAssignee = null;
    }

    public function bulkUnassign(): void
    {
        Gate::authorize('create', AssetItem::class);
        if (empty($this->selected)) return;

        $items = AssetItem::where('team_id', $this->teamId())->forTenant($this->selectedTenantId)->whereIn('id', $this->selected)->get();
        foreach ($items as $item) {
            $item->assignTo(null);
        }

        $this->bulkResult = count($items) . ' Asset(s) ins Lager zurückgelegt.';
        $this->selected = [];
        $this->selectPage = false;
    }

    public function bulkSetStatus(): void
    {
        Gate::authorize('create', AssetItem::class);
        if (empty($this->selected) || !in_array($this->bulkStatus, ['in_stock', 'assigned', 'retired', 'lost'], true)) return;

        $items = AssetItem::where('team_id', $this->teamId())->forTenant($this->selectedTenantId)->whereIn('id', $this->selected)->get();
        foreach ($items as $item) {
            // retired/lost lösen Zuweisung; assigned nur sinnvoll wenn assignee vorhanden
            if (in_array($this->bulkStatus, ['retired', 'lost'], true) && $item->assignee_id) {
                $item->assignments()->whereNull('returned_at')->update(['returned_at' => now(), 'updated_at' => now()]);
                $item->update(['status' => $this->bulkStatus, 'assignee_id' => null, 'assigned_at' => null]);
            } else {
                $item->update(['status' => $this->bulkStatus]);
            }
        }

        $labels = ['in_stock' => 'Lager', 'assigned' => 'Zugewiesen', 'retired' => 'Ausgemustert', 'lost' => 'Verloren'];
        $this->bulkResult = count($items) . ' Asset(s) auf Status "' . $labels[$this->bulkStatus] . '" gesetzt.';
        $this->selected = [];
        $this->selectPage = false;
        $this->bulkStatus = '';
    }

    public function bulkDelete(): void
    {
        if (empty($this->selected)) return;

        // Nur manuelle Items, nur owner/admin
        $items = AssetItem::where('team_id', $this->teamId())->forTenant($this->selectedTenantId)
            ->whereIn('id', $this->selected)
            ->where('source', 'manual')
            ->get();

        $deleted = 0;
        foreach ($items as $item) {
            if (Gate::allows('delete', $item)) {
                $item->delete();
                $deleted++;
            }
        }

        $this->bulkResult = $deleted . ' Asset(s) gelöscht.' . (count($this->selected) > $deleted ? ' (Intune-Items können nicht gelöscht werden.)' : '');
        $this->selected = [];
        $this->selectPage = false;
    }

    public function updatedBcCategoryId(): void
    {
        if ($this->bcCategoryId && !$this->bcDepreciationMonths) {
            $cat = AssetCategory::find($this->bcCategoryId);
            if ($cat && $cat->default_depreciation_months) {
                $this->bcDepreciationMonths = $cat->default_depreciation_months;
            }
        }
    }

    public function createBulk(): void
    {
        Gate::authorize('create', AssetItem::class);

        $this->validate([
            'bcCategoryId'         => 'required|exists:asset_categories,id',
            'bcName'               => 'required|string|max:255',
            'bcManufacturer'       => 'nullable|string|max:255',
            'bcModel'              => 'nullable|string|max:255',
            'bcQuantity'           => 'required|integer|min:1|max:500',
            // Assignee MUSS zum eigenen Team gehören (sonst danglende cross-team FK).
            'bcAssigneeId'         => ['nullable', 'integer', Rule::exists('asset_employees', 'id')->where('team_id', $this->teamId())],
            'bcPurchasePrice'      => 'nullable|numeric|min:0',
            'bcDepreciationMonths' => 'nullable|integer|min:1|max:240',
        ]);

        $teamId     = $this->teamId();
        $tenantId   = TenantContext::resolveForWrite($teamId, (int) Auth::id());
        $status     = $this->bcAssigneeId ? 'assigned' : 'in_stock';
        $assignedAt = $this->bcAssigneeId ? now() : null;

        for ($i = 0; $i < $this->bcQuantity; $i++) {
            $item = AssetItem::create([
                'team_id'             => $teamId,
                'tenant_id'           => $tenantId,
                'category_id'         => $this->bcCategoryId,
                'source'              => 'manual',
                'name'                => $this->bcName,
                'manufacturer'        => $this->bcManufacturer ?: null,
                'model'               => $this->bcModel ?: null,
                'assignee_id'         => $this->bcAssigneeId,
                'assigned_at'         => $assignedAt,
                'status'              => $status,
                'purchase_price'      => $this->bcPurchasePrice ?: null,
                'depreciation_months' => $this->bcDepreciationMonths,
            ]);

            if ($this->bcAssigneeId) {
                AssetAssignment::create([
                    'asset_item_id'   => $item->id,
                    'assignable_type' => AssetAssignment::SUBJECT_ITEM,
                    'assignable_id'   => $item->id,
                    'employee_id'     => $this->bcAssigneeId,
                    'assigned_at'     => now(),
                    'source'          => AssetAssignment::SOURCE_MANUAL,
                ]);
            }
        }

        $this->bulkResult = $this->bcQuantity . '× "' . $this->bcName . '" angelegt.';
        $this->reset(['bcName', 'bcManufacturer', 'bcModel', 'bcQuantity', 'bcAssigneeId', 'bcPurchasePrice', 'bcDepreciationMonths', 'bcCategoryId', 'showBulkCreate']);
        $this->bcQuantity = 1;
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        $query = AssetItem::where('team_id', $this->teamId())->forTenant($this->selectedTenantId);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('manufacturer', 'like', '%' . $this->search . '%')
                  ->orWhere('model', 'like', '%' . $this->search . '%')
                  ->orWhere('serial_number', 'like', '%' . $this->search . '%');
            });
        }
        if ($this->filterCategory) $query->where('category_id', $this->filterCategory);
        if ($this->filterStatus)   $query->where('status', $this->filterStatus);
        if ($this->filterSource)   $query->where('source', $this->filterSource);
        if ($this->filterAssignee) $query->where('assignee_id', $this->filterAssignee);

        return $query;
    }

    public function render()
    {
        $teamId = $this->teamId();

        $items = $this->filteredQuery()
            ->with(['category', 'assignee'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        // Page-Select-Status synchron halten
        $pageIds = $items->pluck('id')->map(fn($id) => (string) $id)->toArray();
        $this->selectPage = !empty($pageIds) && empty(array_diff($pageIds, $this->selected));

        $stats = [
            'total'    => AssetItem::where('team_id', $teamId)->forTenant($this->selectedTenantId)->count(),
            'assigned' => AssetItem::where('team_id', $teamId)->forTenant($this->selectedTenantId)->where('status', 'assigned')->count(),
            'in_stock' => AssetItem::where('team_id', $teamId)->forTenant($this->selectedTenantId)->where('status', 'in_stock')->count(),
            'retired'  => AssetItem::where('team_id', $teamId)->forTenant($this->selectedTenantId)->where('status', 'retired')->count(),
        ];

        $categories = AssetCategory::orderBy('sort_order')->get();
        $employees  = AssetEmployee::where('team_id', $teamId)->forTenant($this->selectedTenantId)->where('is_active', true)->orderBy('display_name')->get();

        return view('asset-manager::livewire.assets.index', [
            'items'        => $items,
            'stats'        => $stats,
            'categories'   => $categories,
            'employees'    => $employees,
            'totalFiltered'=> $this->filteredQuery()->count(),
        ])->layout('platform::layouts.app');
    }

    /** Beim Umschalten der Page-Checkbox alle/keine der Seite auswählen. */
    public function updatedSelectPage($value): void
    {
        $pageIds = $this->filteredQuery()
            ->orderBy($this->sortField, $this->sortDirection)
            ->forPage($this->getPage(), $this->perPage)
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->toArray();

        if ($value) {
            $this->selected = array_values(array_unique(array_merge($this->selected, $pageIds)));
        } else {
            $this->selected = array_values(array_diff($this->selected, $pageIds));
        }
    }
}
