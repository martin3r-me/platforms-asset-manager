<?php

namespace Platform\AssetManager\Livewire\Employees;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetUserLicense;

class Index extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $filterDept    = '';
    public bool   $onlyActive    = true;
    public int    $perPage       = 25;
    public string $sortField     = 'display_name';
    public string $sortDirection = 'asc';

    protected $queryString = [
        'search'     => ['except' => ''],
        'filterDept' => ['except' => ''],
        'onlyActive' => ['except' => true],
        'perPage'    => ['except' => 25],
    ];

    public function updatingSearch(): void     { $this->resetPage(); }
    public function updatingFilterDept(): void { $this->resetPage(); }
    public function updatingOnlyActive(): void { $this->resetPage(); }

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

        $query = AssetEmployee::where('team_id', $teamId);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('user_principal_name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }
        if ($this->filterDept) $query->where('department', $this->filterDept);
        if ($this->onlyActive) $query->where('is_active', true);

        $employees = $query
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        // Counts pro Employee als Map: [employee_id => [devices, items, licenses]]
        $employeeIds = $employees->pluck('id')->toArray();
        $upns        = $employees->pluck('user_principal_name')->toArray();

        $itemCounts = AssetItem::whereIn('assignee_id', $employeeIds)
            ->select('assignee_id', DB::raw('count(*) as count'))
            ->groupBy('assignee_id')
            ->pluck('count', 'assignee_id');

        $deviceCounts = AssetDevice::where('team_id', $teamId)
            ->whereIn('user_principal_name', $upns)
            ->select('user_principal_name', DB::raw('count(*) as count'))
            ->groupBy('user_principal_name')
            ->pluck('count', 'user_principal_name');

        $licenseCounts = AssetUserLicense::where('team_id', $teamId)
            ->whereIn('user_principal_name', $upns)
            ->select('user_principal_name', DB::raw('count(*) as count'))
            ->groupBy('user_principal_name')
            ->pluck('count', 'user_principal_name');

        $stats = [
            'total'    => AssetEmployee::where('team_id', $teamId)->count(),
            'active'   => AssetEmployee::where('team_id', $teamId)->where('is_active', true)->count(),
            'fromGraph'=> AssetEmployee::where('team_id', $teamId)->where('source', 'graph')->count(),
            'derived'  => AssetEmployee::where('team_id', $teamId)->where('source', 'derived')->count(),
        ];

        $departments = AssetEmployee::where('team_id', $teamId)
            ->whereNotNull('department')
            ->select('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        return view('asset-manager::livewire.employees.index', [
            'employees'     => $employees,
            'itemCounts'    => $itemCounts,
            'deviceCounts'  => $deviceCounts,
            'licenseCounts' => $licenseCounts,
            'stats'         => $stats,
            'departments'   => $departments,
        ])->layout('platform::layouts.app');
    }
}
