<?php

namespace Platform\AssetManager\Livewire\Devices;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
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

    protected $queryString = [
        'search'           => ['except' => ''],
        'filterCompliance' => ['except' => ''],
        'filterOs'         => ['except' => ''],
        'perPage'          => ['except' => 25],
    ];

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

        $canSync = Gate::allows('sync', AssetDevice::class);

        return view('asset-manager::livewire.devices.index', [
            'devices'  => $devices,
            'stats'    => $stats,
            'osList'   => $osList,
            'config'   => $config,
            'lastLog'  => $lastLog,
            'canSync'  => $canSync,
        ])->layout('platform::layouts.app');
    }
}
