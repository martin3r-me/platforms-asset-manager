<?php

namespace Platform\AssetManager\Livewire\Assets;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;

class Index extends Component
{
    use WithPagination;

    public string $search          = '';
    public string $filterCategory  = '';
    public string $filterStatus    = '';
    public string $filterSource    = '';
    public ?int   $filterAssignee  = null;
    public int    $perPage         = 25;
    public string $sortField       = 'name';
    public string $sortDirection   = 'asc';

    protected $queryString = [
        'search'         => ['except' => ''],
        'filterCategory' => ['except' => ''],
        'filterStatus'   => ['except' => ''],
        'filterSource'   => ['except' => ''],
        'filterAssignee' => ['except' => null],
        'perPage'        => ['except' => 25],
    ];

    public function updatingSearch(): void         { $this->resetPage(); }
    public function updatingFilterCategory(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void   { $this->resetPage(); }
    public function updatingFilterSource(): void   { $this->resetPage(); }
    public function updatingFilterAssignee(): void { $this->resetPage(); }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;

        $query = AssetItem::with(['category', 'assignee'])
            ->where('team_id', $teamId);

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

        $items = $query
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        $stats = [
            'total'    => AssetItem::where('team_id', $teamId)->count(),
            'assigned' => AssetItem::where('team_id', $teamId)->where('status', 'assigned')->count(),
            'in_stock' => AssetItem::where('team_id', $teamId)->where('status', 'in_stock')->count(),
            'retired'  => AssetItem::where('team_id', $teamId)->where('status', 'retired')->count(),
        ];

        $categories = AssetCategory::orderBy('sort_order')->get();
        $employees  = AssetEmployee::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get();

        return view('asset-manager::livewire.assets.index', [
            'items'      => $items,
            'stats'      => $stats,
            'categories' => $categories,
            'employees'  => $employees,
        ])->layout('platform::layouts.app');
    }
}
