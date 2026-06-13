<?php

namespace Platform\AssetManager\Livewire\Employees;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
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

        $this->employee->update([
            'display_name' => $this->displayName ?: null,
            'email'        => $this->email ?: null,
            'department'   => $this->department ?: null,
            'cost_center'  => $this->costCenter ?: null,
            'job_title'    => $this->jobTitle ?: null,
            'is_active'    => $this->isActive,
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

        // Monatliche Kosten
        $hardwareCost = $items->sum(fn($i) => $i->monthlyCost());
        $deviceCost   = $devices->sum(fn($d) => (float) ($deviceRows[$d->id]['amount'] ?? 0));
        $licenseCost  = 0.0;
        foreach ($licenses as $lic) {
            $sku = $skuMap[$lic->sku_id] ?? null;
            if ($sku && $sku->unit_price !== null) {
                $licenseCost += (float) $sku->unit_price;
            }
        }

        return view('asset-manager::livewire.employees.show', [
            'employee'     => $this->employee,
            'items'        => $items,
            'devices'      => $devices,
            'deviceRows'   => $deviceRows,
            'licenses'     => $licenses,
            'skuMap'       => $skuMap,
            'hardwareCost' => $hardwareCost,
            'deviceCost'   => $deviceCost,
            'licenseCost'  => $licenseCost,
            'totalCost'    => $hardwareCost + $deviceCost + $licenseCost,
        ])->layout('platform::layouts.app');
    }
}
