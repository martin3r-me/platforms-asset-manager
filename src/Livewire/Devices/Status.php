<?php

namespace Platform\AssetManager\Livewire\Devices;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ScopesToTenant;
use Platform\AssetManager\Models\AssetDevice;

/**
 * Tenant-reine Geräte-Status-Sicht (M4): Lifecycle-Status auf einen Blick — Stat-Kacheln je Status
 * (in_use/spare/repair/defect/retired/lost + „ohne Status") plus eine filterbare Geräteliste.
 * Read-only; Statuswechsel laufen weiter über Devices/Show bzw. die Bulk-Aktion in Devices/Index.
 * Über den Tenant-Selektor (Concerns\ScopesToTenant) tenant-rein gefiltert.
 */
class Status extends Component
{
    use WithPagination;
    use ScopesToTenant;

    /** Aktiver Status-Filter: '' = alle · 'none' = ohne Lifecycle · sonst ein LIFECYCLE_STATUSES-Wert. */
    public string $status  = '';
    public int    $perPage = 25;

    protected $queryString = [
        'status'  => ['except' => ''],
        'perPage' => ['except' => 25],
    ];

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        // Eine frische, tenant-gefilterte Basis-Query je Aufruf (Counts laufen unabhängig vom Listen-Filter).
        $base = fn () => AssetDevice::where('team_id', $teamId)->forTenant($this->selectedTenantId);

        $counts = ['all' => $base()->count()];
        foreach (AssetDevice::LIFECYCLE_STATUSES as $s) {
            $counts[$s] = $base()->where('lifecycle_status', $s)->count();
        }
        $counts['none'] = $base()->where(function ($q) {
            $q->whereNull('lifecycle_status')->orWhere('lifecycle_status', '');
        })->count();

        $query = $base();
        if ($this->status === 'none') {
            $query->where(function ($q) {
                $q->whereNull('lifecycle_status')->orWhere('lifecycle_status', '');
            });
        } elseif (in_array($this->status, AssetDevice::LIFECYCLE_STATUSES, true)) {
            $query->where('lifecycle_status', $this->status);
        }

        $devices = $query->orderBy('device_name')->paginate($this->perPage);

        return view('asset-manager::livewire.devices.status', [
            'counts'  => $counts,
            'devices' => $devices,
        ])->layout('platform::layouts.app');
    }
}
