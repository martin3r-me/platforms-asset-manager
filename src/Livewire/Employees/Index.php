<?php

namespace Platform\AssetManager\Livewire\Employees;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\CostAggregationService;

class Index extends Component
{
    use WithPagination;

    public string $preset           = 'active'; // all|active|with_license|with_device|with_asset|unassigned|inactive
    public string $search           = '';
    public string $filterDept       = '';
    public string $filterSku        = '';
    public string $filterSource     = '';
    public string $filterCostCenter = '';
    public bool   $filterHasLicense = false;
    public bool   $filterHasDevice  = false;
    public bool   $filterHasAsset   = false;
    public int    $perPage          = 25;
    public string $sortField        = 'display_name';
    public string $sortDirection    = 'asc';
    public ?int   $selectedId       = null; // im rechten Detail-Panel gewählter Mitarbeiter

    protected $queryString = [
        'preset'           => ['except' => 'active'],
        'search'           => ['except' => ''],
        'filterDept'       => ['except' => ''],
        'filterSku'        => ['except' => ''],
        'filterSource'     => ['except' => ''],
        'filterCostCenter' => ['except' => ''],
        'filterHasLicense' => ['except' => false],
        'filterHasDevice'  => ['except' => false],
        'filterHasAsset'   => ['except' => false],
        'perPage'          => ['except' => 25],
    ];

    public function updatingPreset(): void           { $this->resetPage(); }
    public function updatingSearch(): void           { $this->resetPage(); }
    public function updatingFilterDept(): void       { $this->resetPage(); }
    public function updatingFilterSku(): void        { $this->resetPage(); }
    public function updatingFilterSource(): void     { $this->resetPage(); }
    public function updatingFilterCostCenter(): void { $this->resetPage(); }
    public function updatingFilterHasLicense(): void { $this->resetPage(); }
    public function updatingFilterHasDevice(): void  { $this->resetPage(); }
    public function updatingFilterHasAsset(): void   { $this->resetPage(); }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->preset           = 'active';
        $this->search           = '';
        $this->filterDept       = '';
        $this->filterSku        = '';
        $this->filterSource     = '';
        $this->filterCostCenter = '';
        $this->filterHasLicense = false;
        $this->filterHasDevice  = false;
        $this->filterHasAsset   = false;
        $this->resetPage();
    }

    /** Mitarbeiter im rechten Detail-Panel selektieren (nur eigenes Team) und Panel aufklappen. */
    public function selectEmployee(int $id): void
    {
        $teamId = Auth::user()->currentTeam->id;

        // Fremd-/ungültige IDs ignorieren, sonst zeigt das Panel nichts (render lädt team-scoped).
        if (! AssetEmployee::where('team_id', $teamId)->whereKey($id)->exists()) {
            return;
        }

        $this->selectedId = $id;
        $this->dispatch('open-activity'); // kollabierte rechte Sidebar aufklappen (Listener im Blade)
    }

    public function clearSelection(): void
    {
        $this->selectedId = null;
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    /** UPNs aller User mit mindestens einer Lizenz im Team (optional gefiltert nach sku_id). */
    protected function upnsWithLicense(int $teamId, ?string $skuId = null)
    {
        $q = AssetUserLicense::where('team_id', $teamId);
        if ($skuId) $q->where('sku_id', $skuId);
        return $q->distinct()->pluck('user_principal_name');
    }

    /** UPNs aller User mit mindestens einem Intune-Gerät im Team. */
    protected function upnsWithDevice(int $teamId)
    {
        return AssetDevice::where('team_id', $teamId)
            ->whereNotNull('user_principal_name')
            ->distinct()
            ->pluck('user_principal_name');
    }

    /** Employee-IDs mit mindestens einem zugewiesenen Asset. */
    protected function employeeIdsWithAsset(int $teamId)
    {
        return AssetItem::where('team_id', $teamId)
            ->whereNotNull('assignee_id')
            ->distinct()
            ->pluck('assignee_id');
    }

    /**
     * Wendet das Preset auf die Query an.
     * Preset 'inactive' überschreibt is_active-Filter — sonst implizit is_active=true.
     */
    protected function applyPreset($query, int $teamId): void
    {
        $licenseUpns = $this->upnsWithLicense($teamId);
        $deviceUpns  = $this->upnsWithDevice($teamId);
        $assetIds    = $this->employeeIdsWithAsset($teamId);
        $allUpns     = $licenseUpns->merge($deviceUpns)->unique()->values();

        switch ($this->preset) {
            case 'inactive':
                $query->where('is_active', false);
                return;
            case 'with_license':
                $query->where('is_active', true)->whereIn('user_principal_name', $licenseUpns);
                return;
            case 'with_device':
                $query->where('is_active', true)->whereIn('user_principal_name', $deviceUpns);
                return;
            case 'with_asset':
                $query->where('is_active', true)->whereIn('id', $assetIds);
                return;
            case 'active':
                $query->where('is_active', true)
                    ->where(function ($q) use ($allUpns, $assetIds) {
                        $q->whereIn('user_principal_name', $allUpns)
                          ->orWhereIn('id', $assetIds);
                    });
                return;
            case 'unassigned':
                $query->where('is_active', true)
                    ->whereNotIn('user_principal_name', $allUpns)
                    ->whereNotIn('id', $assetIds);
                return;
            case 'all':
            default:
                // keine Einschränkung
        }
    }

    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;

        // --- Counts für Chips (immer "echt", nicht durch Sidebar gefiltert) ---
        $licenseUpns = $this->upnsWithLicense($teamId);
        $deviceUpns  = $this->upnsWithDevice($teamId);
        $assetIds    = $this->employeeIdsWithAsset($teamId);
        $allUpns     = $licenseUpns->merge($deviceUpns)->unique()->values();

        $base = AssetEmployee::where('team_id', $teamId);

        $counts = [
            'all'          => (clone $base)->count(),
            'active'       => (clone $base)->where('is_active', true)
                                ->where(function ($q) use ($allUpns, $assetIds) {
                                    $q->whereIn('user_principal_name', $allUpns)
                                      ->orWhereIn('id', $assetIds);
                                })->count(),
            'with_license' => (clone $base)->where('is_active', true)->whereIn('user_principal_name', $licenseUpns)->count(),
            'with_device'  => (clone $base)->where('is_active', true)->whereIn('user_principal_name', $deviceUpns)->count(),
            'with_asset'   => (clone $base)->where('is_active', true)->whereIn('id', $assetIds)->count(),
            'unassigned'   => (clone $base)->where('is_active', true)
                                ->whereNotIn('user_principal_name', $allUpns)
                                ->whereNotIn('id', $assetIds)->count(),
            'inactive'     => (clone $base)->where('is_active', false)->count(),
        ];

        // --- Hauptquery ---
        $query = AssetEmployee::where('team_id', $teamId);
        $this->applyPreset($query, $teamId);

        // Sidebar-Filter (kombiniert mit Preset)
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('user_principal_name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }
        if ($this->filterDept)       $query->where('department', $this->filterDept);
        if ($this->filterSource)     $query->where('source', $this->filterSource);
        if ($this->filterCostCenter !== '') $query->where('cost_center_id', $this->filterCostCenter);

        if ($this->filterHasLicense || $this->filterSku) {
            $query->whereIn('user_principal_name', $this->upnsWithLicense($teamId, $this->filterSku ?: null));
        }
        if ($this->filterHasDevice) {
            $query->whereIn('user_principal_name', $deviceUpns);
        }
        if ($this->filterHasAsset) {
            $query->whereIn('id', $assetIds);
        }

        $employees = $query
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        // --- Counts pro angezeigtem Employee ---
        $pageEmpIds = $employees->pluck('id')->toArray();
        $pageUpns   = $employees->pluck('user_principal_name')->toArray();

        $itemCounts = AssetItem::whereIn('assignee_id', $pageEmpIds)
            ->select('assignee_id', DB::raw('count(*) as count'))
            ->groupBy('assignee_id')
            ->pluck('count', 'assignee_id');

        $deviceCounts = AssetDevice::where('team_id', $teamId)
            ->whereIn('user_principal_name', $pageUpns)
            ->select('user_principal_name', DB::raw('count(*) as count'))
            ->groupBy('user_principal_name')
            ->pluck('count', 'user_principal_name');

        $licenseCounts = AssetUserLicense::where('team_id', $teamId)
            ->whereIn('user_principal_name', $pageUpns)
            ->select('user_principal_name', DB::raw('count(*) as count'))
            ->groupBy('user_principal_name')
            ->pluck('count', 'user_principal_name');

        // --- Dropdowns ---
        $departments = AssetEmployee::where('team_id', $teamId)
            ->whereNotNull('department')
            ->select('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        $skus = AssetLicenseSku::where('team_id', $teamId)
            ->orderBy('display_name')
            ->get(['id', 'sku_id', 'sku_part_number', 'display_name']);

        $costCenters = AssetCostCenter::where('team_id', $teamId)
            ->orderBy('company_id')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        // --- Rechtes Detail-Panel: gewählter Mitarbeiter + Zähler + Monatskosten ---
        $selectedEmployee = null;
        $selDeviceCount   = 0;
        $selAssetCount    = 0;
        $selLicenseCount  = 0;
        $selectedCost     = ['hardware' => 0.0, 'device' => 0.0, 'license' => 0.0, 'total' => 0.0];

        if ($this->selectedId) {
            $selectedEmployee = AssetEmployee::where('team_id', $teamId)->find($this->selectedId);

            if ($selectedEmployee) {
                $upn = $selectedEmployee->user_principal_name;

                // Assets hängen an assignee_id; Geräte/Lizenzen am UPN (ohne UPN → 0).
                $selAssetCount = AssetItem::where('team_id', $teamId)
                    ->where('assignee_id', $selectedEmployee->id)->count();

                if ($upn) {
                    $selDeviceCount  = AssetDevice::where('team_id', $teamId)
                        ->where('user_principal_name', $upn)->count();
                    $selLicenseCount = AssetUserLicense::where('team_id', $teamId)
                        ->where('user_principal_name', $upn)->count();
                }

                // Identische Zahl wie die Profil-Seite (gemeinsame Methode).
                $selectedCost = app(CostAggregationService::class)->employeeCost($teamId, $selectedEmployee);
            } else {
                $this->selectedId = null; // Auswahl ins Leere (gelöscht / Team-Wechsel) → zurücksetzen
            }
        }

        return view('asset-manager::livewire.employees.index', [
            'employees'        => $employees,
            'itemCounts'       => $itemCounts,
            'deviceCounts'     => $deviceCounts,
            'licenseCounts'    => $licenseCounts,
            'counts'           => $counts,
            'departments'      => $departments,
            'skus'             => $skus,
            'costCenters'      => $costCenters,
            'selectedEmployee' => $selectedEmployee,
            'selDeviceCount'   => $selDeviceCount,
            'selAssetCount'    => $selAssetCount,
            'selLicenseCount'  => $selLicenseCount,
            'selectedCost'     => $selectedCost,
        ])->layout('platform::layouts.app');
    }
}
