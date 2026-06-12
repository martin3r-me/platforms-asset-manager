<?php

namespace Platform\AssetManager\Livewire\Devices;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;

class Index extends Component
{
    use WithPagination;

    public string $search           = '';
    public string $filterCompliance = '';
    public string $filterOs         = '';
    public int    $perPage          = 25;
    public string $sortField        = 'device_name';
    public string $sortDirection    = 'asc';

    public bool    $syncing    = false;
    public ?string $syncResult = null;

    /** Master-Detail: 'device' | 'employee' | null  */
    public ?string $detailType = null;
    public ?int    $detailId   = null;

    public array $columnOrder = ['device', 'user', 'os', 'status', 'lastCheckIn'];

    public const COLUMN_KEYS = ['device', 'user', 'os', 'status', 'lastCheckIn'];

    protected $queryString = [
        'search'           => ['except' => ''],
        'filterCompliance' => ['except' => ''],
        'filterOs'         => ['except' => ''],
        'perPage'          => ['except' => 25],
    ];

    public function mount(): void
    {
        $stored = session('asset-manager.devices.columnOrder');
        if (is_array($stored)) {
            $this->columnOrder = $this->normalizeColumnOrder($stored);
        }
    }

    public function reorderColumns(array $order): void
    {
        $this->columnOrder = $this->normalizeColumnOrder($order);
        session(['asset-manager.devices.columnOrder' => $this->columnOrder]);
    }

    public function resetColumnOrder(): void
    {
        $this->columnOrder = self::COLUMN_KEYS;
        session()->forget('asset-manager.devices.columnOrder');
    }

    protected function normalizeColumnOrder(array $order): array
    {
        // wotz/livewire-sortablejs liefert ggf. [['order' => 0, 'value' => 'device'], ...]
        $flat = array_map(
            fn($i) => is_array($i) ? ($i['value'] ?? null) : $i,
            $order
        );
        $valid = array_values(array_intersect($flat, self::COLUMN_KEYS));

        // Fehlende Spalten ans Ende anhängen, damit nichts verschwindet
        foreach (self::COLUMN_KEYS as $key) {
            if (!in_array($key, $valid, true)) {
                $valid[] = $key;
            }
        }
        return $valid;
    }

    public function updatingSearch(): void          { $this->resetPage(); }
    public function updatingFilterCompliance(): void { $this->resetPage(); }
    public function updatingFilterOs(): void         { $this->resetPage(); }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function syncNow(): void
    {
        Gate::authorize('sync', AssetDevice::class);

        $team = Auth::user()->currentTeam;

        SyncIntuneDevicesJob::dispatch($team->id);

        $this->syncResult = 'Sync gestartet — Geräte werden im Hintergrund synchronisiert.';
    }

    public function selectDevice(int $deviceId): void
    {
        $this->detailType = 'device';
        $this->detailId   = $deviceId;
    }

    public function selectEmployeeByUpn(string $upn): void
    {
        $team     = Auth::user()->currentTeam;
        $employee = AssetEmployee::where('team_id', $team->id)
            ->where('user_principal_name', $upn)
            ->first();

        if ($employee) {
            $this->detailType = 'employee';
            $this->detailId   = $employee->id;
        }
    }

    public function clearSelection(): void
    {
        $this->detailType = null;
        $this->detailId   = null;
    }

    public function render()
    {
        $team  = Auth::user()->currentTeam;
        $query = AssetDevice::where('team_id', $team->id);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('device_name', 'like', '%' . $this->search . '%')
                  ->orWhere('user_display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('user_principal_name', 'like', '%' . $this->search . '%')
                  ->orWhere('serial_number', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterCompliance) {
            $query->where('compliance_state', $this->filterCompliance);
        }

        if ($this->filterOs) {
            $query->where('operating_system', $this->filterOs);
        }

        $devices = $query
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        $stats = [
            'total'        => AssetDevice::where('team_id', $team->id)->count(),
            'compliant'    => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'compliant')->count(),
            'noncompliant' => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'noncompliant')->count(),
            'unknown'      => AssetDevice::where('team_id', $team->id)->whereIn('compliance_state', ['unknown', 'error', 'conflict'])->count(),
        ];

        $osList = AssetDevice::where('team_id', $team->id)
            ->select('operating_system')
            ->distinct()
            ->orderBy('operating_system')
            ->pluck('operating_system')
            ->filter()
            ->values();

        $config  = AssetConnectorConfig::where('team_id', $team->id)->first();
        $lastLog = AssetDeviceSyncLog::where('team_id', $team->id)
            ->orderBy('started_at', 'desc')
            ->first();

        $activities = AssetDeviceSyncLog::where('team_id', $team->id)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        // Verteilungen für Bottom-Panel
        $osBreakdown = AssetDevice::where('team_id', $team->id)
            ->selectRaw('COALESCE(operating_system, "Unbekannt") as os, count(*) as count')
            ->groupBy('os')
            ->orderByDesc('count')
            ->get();

        $complianceBreakdown = AssetDevice::where('team_id', $team->id)
            ->selectRaw('compliance_state, count(*) as count')
            ->groupBy('compliance_state')
            ->orderByDesc('count')
            ->get();

        $canSync = Gate::allows('sync', AssetDevice::class);

        // Master-Detail-Daten laden
        $selectedDevice   = null;
        $selectedEmployee = null;
        $employeeDevices  = collect();
        $employeeLicenses = collect();

        if ($this->detailType === 'device' && $this->detailId) {
            $selectedDevice = AssetDevice::where('team_id', $team->id)
                ->where('id', $this->detailId)
                ->first();
        } elseif ($this->detailType === 'employee' && $this->detailId) {
            $selectedEmployee = AssetEmployee::where('team_id', $team->id)
                ->where('id', $this->detailId)
                ->first();

            if ($selectedEmployee) {
                $employeeDevices = AssetDevice::where('team_id', $team->id)
                    ->where('user_principal_name', $selectedEmployee->user_principal_name)
                    ->get();
                $employeeLicenses = AssetUserLicense::where('team_id', $team->id)
                    ->where('user_principal_name', $selectedEmployee->user_principal_name)
                    ->get();
            }
        }

        return view('asset-manager::livewire.devices.index', [
            'devices'             => $devices,
            'stats'               => $stats,
            'osList'              => $osList,
            'config'              => $config,
            'lastLog'             => $lastLog,
            'activities'          => $activities,
            'osBreakdown'         => $osBreakdown,
            'complianceBreakdown' => $complianceBreakdown,
            'canSync'             => $canSync,
            'columns'             => $this->columnOrder,
            'selectedDevice'      => $selectedDevice,
            'selectedEmployee'    => $selectedEmployee,
            'employeeDevices'     => $employeeDevices,
            'employeeLicenses'    => $employeeLicenses,
        ])->layout('platform::layouts.app');
    }
}
