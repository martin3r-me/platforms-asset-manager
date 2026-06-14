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
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    use WithPagination;

    public string $preset           = 'all'; // all|no_user|inactive|noncompliant|issues|expiring
    public string $search           = '';
    public string $filterCompliance = '';
    public string $filterOs         = '';
    public string $filterLifecycle  = '';
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

    /** Schwellwert (Tage ohne Check-in), ab dem ein Gerät als inaktiv gilt. */
    public const INACTIVE_DAYS = 30;

    public const PRESETS = ['all', 'no_user', 'inactive', 'noncompliant', 'issues', 'expiring', 'unencrypted'];

    protected $queryString = [
        'preset'           => ['except' => 'all'],
        'search'           => ['except' => ''],
        'filterCompliance' => ['except' => ''],
        'filterOs'         => ['except' => ''],
        'filterLifecycle'  => ['except' => ''],
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

    public function updatingSearch(): void           { $this->resetPage(); }
    public function updatingFilterCompliance(): void { $this->resetPage(); }
    public function updatingFilterOs(): void         { $this->resetPage(); }
    public function updatingFilterLifecycle(): void  { $this->resetPage(); }

    public function setPreset(string $preset): void
    {
        $this->preset = in_array($preset, self::PRESETS, true) ? $preset : 'all';
        $this->resetPage();
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

    /** Team-Query inkl. Suche, Compliance-/OS-Filter und Schnellfilter-Preset (geteilt von Liste + Export). */
    protected function filteredQuery()
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

        if ($this->filterLifecycle) {
            $query->where('lifecycle_status', $this->filterLifecycle);
        }

        $this->applyPreset($query);

        return $query;
    }

    /** Schnellfilter-Preset auf die Query anwenden (Daten liegen bereits vor — nur Sicht). */
    protected function applyPreset($query): void
    {
        switch ($this->preset) {
            case 'no_user':
                $query->where(function ($q) {
                    $q->whereNull('user_principal_name')->orWhere('user_principal_name', '');
                });
                return;
            case 'inactive':
                $query->where(function ($q) {
                    $q->whereNull('last_check_in_at')
                      ->orWhere('last_check_in_at', '<', now()->subDays(self::INACTIVE_DAYS));
                });
                return;
            case 'noncompliant':
                $query->where('compliance_state', 'noncompliant');
                return;
            case 'issues':
                $query->whereIn('compliance_state', ['error', 'conflict']);
                return;
            case 'expiring':
                $t = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);
                $query->where(function ($q) use ($t) {
                    $q->where(function ($w) use ($t) { $w->whereNotNull('warranty_until')->where('warranty_until', '<=', $t); })
                      ->orWhere(function ($w) use ($t) { $w->whereNotNull('lease_until')->where('lease_until', '<=', $t); });
                });
                return;
            case 'unencrypted':
                $query->where('is_encrypted', false);
                return;
            case 'all':
            default:
                // keine Einschränkung
        }
    }

    /** CSV-Export der aktuell gefilterten Geräteliste (UTF-8 BOM + ;-Delimiter für Excel). */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->filteredQuery()->with('vendor')->orderBy($this->sortField, $this->sortDirection);

        $filename = 'intune-geraete-' . now()->format('Y-m-d') . '.csv';

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

            fputcsv($out, [
                'Gerät', 'Hersteller', 'Modell', 'Seriennr.', 'Betriebssystem', 'OS-Version',
                'Compliance', 'Management', 'Typ', 'Nutzer', 'UPN', 'Enrollt am', 'Letztes Check-In',
                'Lifecycle', 'Garantie bis', 'Leasing bis', 'Standort', 'Lieferant', 'Bestell-Nr.', 'Verschlüsselt',
            ], ';');

            foreach ($rows->cursor() as $d) {
                fputcsv($out, [
                    $d->device_name,
                    $d->manufacturer,
                    $d->model,
                    $d->serial_number,
                    $d->operating_system,
                    $d->os_version,
                    $d->complianceLabel(),
                    $d->management_state,
                    $d->device_type,
                    $d->user_display_name,
                    $d->user_principal_name,
                    $d->enrolled_at?->format('Y-m-d'),
                    $d->last_check_in_at?->format('Y-m-d H:i'),
                    $d->lifecycleLabel(),
                    $d->warranty_until?->format('Y-m-d'),
                    $d->lease_until?->format('Y-m-d'),
                    $d->location,
                    optional($d->vendor)->name,
                    $d->order_no,
                    $d->encryptionLabel(),
                ], ';');
            }

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function render()
    {
        $team    = Auth::user()->currentTeam;
        $devices = $this->filteredQuery()
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        $stats = [
            'total'        => AssetDevice::where('team_id', $team->id)->count(),
            'compliant'    => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'compliant')->count(),
            'noncompliant' => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'noncompliant')->count(),
            'unknown'      => AssetDevice::where('team_id', $team->id)->whereIn('compliance_state', ['unknown', 'error', 'conflict'])->count(),
        ];

        // Zähler für die Schnellfilter-Chips (immer team-weit, nicht durch Suche/Filter eingeschränkt)
        $presetCounts = [
            'all'          => $stats['total'],
            'no_user'      => AssetDevice::where('team_id', $team->id)
                                ->where(function ($q) { $q->whereNull('user_principal_name')->orWhere('user_principal_name', ''); })
                                ->count(),
            'inactive'     => AssetDevice::where('team_id', $team->id)
                                ->where(function ($q) {
                                    $q->whereNull('last_check_in_at')
                                      ->orWhere('last_check_in_at', '<', now()->subDays(self::INACTIVE_DAYS));
                                })->count(),
            'noncompliant' => $stats['noncompliant'],
            'issues'       => AssetDevice::where('team_id', $team->id)->whereIn('compliance_state', ['error', 'conflict'])->count(),
            'expiring'     => AssetDevice::where('team_id', $team->id)
                                ->where(function ($q) {
                                    $t = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);
                                    $q->where(function ($w) use ($t) { $w->whereNotNull('warranty_until')->where('warranty_until', '<=', $t); })
                                      ->orWhere(function ($w) use ($t) { $w->whereNotNull('lease_until')->where('lease_until', '<=', $t); });
                                })->count(),
            'unencrypted'  => AssetDevice::where('team_id', $team->id)->where('is_encrypted', false)->count(),
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
            'presetCounts'        => $presetCounts,
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
