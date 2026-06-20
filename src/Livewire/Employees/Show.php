<?php

namespace Platform\AssetManager\Livewire\Employees;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\CostAggregationService;

class Show extends Component
{
    public AssetEmployee $employee;

    public string  $displayName = '';
    public string  $email       = '';
    public string  $department  = '';
    public string  $costCenter  = '';
    public string  $jobTitle    = '';
    public bool    $isActive    = true;
    public bool    $saved       = false;

    public function mount(AssetEmployee $employee): void
    {
        abort_unless($employee->team_id === Auth::user()->currentTeam->id, 403);
        $this->employee    = $employee;
        $this->displayName = $employee->display_name ?? '';
        $this->email       = $employee->email ?? '';
        $this->department  = $employee->department ?? '';
        $this->costCenter  = $employee->cost_center ?? '';
        $this->jobTitle    = $employee->job_title ?? '';
        $this->isActive    = $employee->is_active;
    }

    public function save(): void
    {
        $this->validate([
            'displayName' => 'nullable|string|max:255',
            'email'       => 'nullable|email|max:255',
            'department'  => 'nullable|string|max:255',
            'costCenter'  => 'nullable|string|max:255',
            'jobTitle'    => 'nullable|string|max:255',
            'isActive'    => 'boolean',
        ]);

        // Kostenstellen-Code team-scoped auflösen und cost_center + cost_center_id KONSISTENT setzen.
        // CostAggregationService gruppiert ausschließlich über cost_center_id — ein reines String-Update
        // (wie bisher) ließe die Kosten im Pivot unter „Ohne Kostenstelle" landen. Muster: UpdateEmployeeTool.
        $code   = trim($this->costCenter);
        $center = $code !== ''
            ? AssetCostCenter::where('team_id', $this->employee->team_id)->where('code', $code)->first()
            : null;

        $this->employee->update([
            'display_name'   => $this->displayName ?: null,
            'email'          => $this->email ?: null,
            'department'     => $this->department ?: null,
            'cost_center'    => $center?->code ?? ($code !== '' ? $code : null),
            'cost_center_id' => $center?->id,
            'job_title'      => $this->jobTitle ?: null,
            'is_active'      => $this->isActive,
        ]);

        $this->saved = true;
    }

    public function render()
    {
        $teamId = $this->employee->team_id;
        $upn    = $this->employee->user_principal_name;

        // Manuelle/Intune-Items (über assignee_id)
        $items = AssetItem::with('category')
            ->where('team_id', $teamId)
            ->where('assignee_id', $this->employee->id)
            ->orderBy('name')
            ->get();

        // Intune-Devices (legacy, über UPN)
        $devices = AssetDevice::where('team_id', $teamId)
            ->where('user_principal_name', $upn)
            ->orderBy('device_name')
            ->get();

        // Lizenzen (über UPN)
        $licenses = AssetUserLicense::where('team_id', $teamId)
            ->where('user_principal_name', $upn)
            ->orderBy('sku_part_number')
            ->get();

        // SKU-Lookup für Kosten
        $skuIds = $licenses->pluck('sku_id')->unique()->toArray();
        $skuMap = AssetLicenseSku::where('team_id', $teamId)
            ->whereIn('sku_id', $skuIds)
            ->get()
            ->keyBy('sku_id');

        // Geräte-Kosten: gated (nur Kostenart aggregation_source='asset_device') + N+1-frei über den
        // zentralen Aggregator — damit der Mitarbeiter-Total mit Dashboard/Pivot übereinstimmt und
        // nicht doppelt zählt. Keyed nach device_id für den Per-Gerät-Betrag in der Liste.
        $deviceRows = app(CostAggregationService::class)->deviceCostRows($teamId)->keyBy('device_id');

        // Monatliche Kosten: gemeinsame Quelle der Wahrheit (identisch mit dem Panel der Liste).
        // $deviceRows wird durchgereicht, damit deviceCostRows() nicht ein zweites Mal läuft.
        $cost = app(CostAggregationService::class)->employeeCost($teamId, $this->employee, $deviceRows);

        return view('asset-manager::livewire.employees.show', [
            'employee'     => $this->employee,
            'items'        => $items,
            'devices'      => $devices,
            'deviceRows'   => $deviceRows,
            'licenses'     => $licenses,
            'skuMap'       => $skuMap,
            'hardwareCost' => $cost['hardware'],
            'deviceCost'   => $cost['device'],
            'licenseCost'  => $cost['license'],
            'totalCost'    => $cost['total'],
        ])->layout('platform::layouts.app');
    }
}
