<?php

namespace Platform\AssetManager\Livewire\Employees;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Concerns\GuardsTenantBoundary;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\EmployeeService;

class Show extends Component
{
    use GuardsTenantBoundary;

    public AssetEmployee $employee;

    public string  $displayName = '';
    public string  $email       = '';
    public string  $department  = '';
    public string  $costCenter  = '';
    public string  $jobTitle    = '';
    public bool    $isActive    = true;
    public bool    $saved       = false;
    public bool    $anonymized  = false;

    // Mobilfunk (ADR 0014): Rufnummern aus Entra + manuelle Metadaten am Mitarbeiter (1:1, kein eigenes Modell).
    public string  $mobilePhone    = '';
    public string  $businessPhone  = '';
    public string  $simNumber      = '';
    public string  $contractNumber = '';
    public string  $dataVolume     = '';
    // Toggle: true = Rufnummern werden aus Entra gepflegt; false = manuell übersteuert (phone_overridden).
    public bool    $phoneFromEntra = true;
    // Mobilfunk-Bearbeiten-Modal (Trigger am Mobilfunk-Block im Content, nicht mehr in der Profil-Leiste).
    public bool    $showMobilfunk  = false;

    public function mount(AssetEmployee $employee): void
    {
        // Team → 403, fremder Tenant → 404 (ADR 0016).
        $this->assertTenantBoundary($employee);
        $this->employee    = $employee;
        $this->displayName = $employee->display_name ?? '';
        $this->email       = $employee->email ?? '';
        $this->department  = $employee->department ?? '';
        $this->costCenter  = $employee->cost_center ?? '';
        $this->jobTitle    = $employee->job_title ?? '';
        $this->isActive    = $employee->is_active;

        $this->mobilePhone    = $employee->mobile_phone ?? '';
        $this->businessPhone  = $employee->business_phone ?? '';
        $this->simNumber      = $employee->sim_number ?? '';
        $this->contractNumber = $employee->contract_number ?? '';
        $this->dataVolume     = $employee->data_volume ?? '';
        $this->phoneFromEntra = ! $employee->phone_overridden;
    }

    public function save(): void
    {
        Gate::authorize('asset-manager.manage');

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

    /**
     * DSGVO-Einzel-Anonymisierung (E2 / ADR 0005): pseudonymisiert die PII dieses Mitarbeiters und der
     * über die UPN verknüpften Geräte/Lizenzen. Owner/Admin-gated, team-scoped. KEINE Auto-Anonymisierung.
     */
    public function anonymize(EmployeeService $service): void
    {
        Gate::authorize('asset-manager.manage');
        $this->assertTenantBoundary($this->employee);

        $service->anonymize($this->employee);

        $this->employee->refresh();
        $this->displayName = $this->employee->display_name ?? '';
        $this->email       = '';
        $this->anonymized  = true;
        $this->saved       = false;
    }

    /** Öffnet das Mobilfunk-Bearbeiten-Modal und lädt die Felder aus dem aktuellen Stand. */
    public function openMobilfunk(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->mobilePhone    = $this->employee->mobile_phone ?? '';
        $this->businessPhone  = $this->employee->business_phone ?? '';
        $this->simNumber      = $this->employee->sim_number ?? '';
        $this->contractNumber = $this->employee->contract_number ?? '';
        $this->dataVolume     = $this->employee->data_volume ?? '';
        $this->phoneFromEntra = ! $this->employee->phone_overridden;
        $this->showMobilfunk  = true;
    }

    /** Speichert die Mobilfunk-Stammdaten (ADR 0014). phone_overridden schützt manuelle Nummern vor dem Sync. */
    public function saveMobilfunk(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->validate([
            'mobilePhone'    => 'nullable|string|max:64',
            'businessPhone'  => 'nullable|string|max:64',
            'simNumber'      => 'nullable|string|max:64',
            'contractNumber' => 'nullable|string|max:64',
            'dataVolume'     => 'nullable|string|max:64',
            'phoneFromEntra' => 'boolean',
        ]);

        $this->employee->update([
            'mobile_phone'     => $this->mobilePhone ?: null,
            'business_phone'   => $this->businessPhone ?: null,
            'phone_overridden' => ! $this->phoneFromEntra,
            'sim_number'       => $this->simNumber ?: null,
            'contract_number'  => $this->contractNumber ?: null,
            'data_volume'      => $this->dataVolume ?: null,
        ]);

        $this->showMobilfunk = false;
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

        // Geräteausgaben dieses Mitarbeiters (Übergabeprotokolle, read-only; Anlegen von der Geräteseite).
        $handovers = AssetHandover::with('lines')
            ->where('team_id', $teamId)
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
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

        // Mobilfunk-Kosten dieses Mitarbeiters (ADR 0014): Summe der aktiven Mobilfunk-Kostenpositionen
        // (cost_line, cost_type.key='mobilfunk'). Nur wenn Controlling aktiv — sonst kein Preis im Block.
        // Der Preis lebt in der Kostenposition, NICHT am Mitarbeiter (keine Doppelpflege).
        $controllingEnabled = app(ControllingContext::class)->enabledFor($teamId);
        $mobileCost = $controllingEnabled
            ? (float) AssetCostLine::active()->validOn(now())
                ->where('team_id', $teamId)
                ->where('assignee_id', $this->employee->id)
                ->whereHas('costType', fn ($q) => $q->where('key', 'mobilfunk'))
                ->sum('monthly_amount')
            : 0.0;

        return view('asset-manager::livewire.employees.show', [
            'employee'           => $this->employee,
            'items'              => $items,
            'devices'            => $devices,
            'handovers'          => $handovers,
            'deviceRows'         => $deviceRows,
            'licenses'           => $licenses,
            'skuMap'             => $skuMap,
            'hardwareCost'       => $cost['hardware'],
            'deviceCost'         => $cost['device'],
            'licenseCost'        => $cost['license'],
            'totalCost'          => $cost['total'],
            'controllingEnabled' => $controllingEnabled,
            'mobileCost'         => $mobileCost,
            'canManage'          => Gate::allows('asset-manager.manage'),
        ])->layout('platform::layouts.app');
    }
}
