<?php

namespace Platform\AssetManager\Livewire\Devices;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetVendor;

class Show extends Component
{
    use AuthorizesTeamRole;

    public AssetDevice $device;
    public bool $showRawData = false;

    // Kosten-Override am Gerät
    public bool    $editingCost   = false;
    public ?string $oMonthly      = null;
    public ?string $oPurchase     = null;
    public ?int    $oDep          = null;
    public ?string $oPurchaseDate = null;
    public ?int    $oCostType     = null;
    public ?int    $oCostCenter   = null;
    public ?string $flash         = null;

    // Freitext-Notiz am Gerät (operative Annotation, owner/admin)
    public bool    $editingNotes  = false;
    public ?string $oNotes        = null;
    public ?string $notesFlash    = null;

    // Lifecycle / Beschaffung (owner/admin)
    public bool    $editingLifecycle = false;
    public ?string $lStatus        = null;
    public ?string $lWarranty      = null;
    public ?string $lLease         = null;
    public ?int    $lVendor        = null;
    public ?string $lOrderNo       = null;
    public ?string $lOrderDate     = null;
    public ?string $lLocation      = null;
    public ?string $lifecycleFlash = null;

    public function mount(AssetDevice $device): void
    {
        abort_unless(
            $device->team_id === Auth::user()->currentTeam->id,
            403
        );

        $this->device = $device;
        $this->fillCostForm();
        $this->oNotes = $this->device->notes;
        $this->fillLifecycleForm();
    }

    protected function fillLifecycleForm(): void
    {
        $this->lStatus    = $this->device->lifecycle_status;
        $this->lWarranty  = $this->device->warranty_until?->format('Y-m-d');
        $this->lLease     = $this->device->lease_until?->format('Y-m-d');
        $this->lVendor    = $this->device->vendor_id;
        $this->lOrderNo   = $this->device->order_no;
        $this->lOrderDate = $this->device->order_date?->format('Y-m-d');
        $this->lLocation  = $this->device->location;
    }

    protected function fillCostForm(): void
    {
        $this->oMonthly      = $this->device->monthly_cost;
        $this->oPurchase     = $this->device->purchase_price;
        $this->oDep          = $this->device->depreciation_months;
        $this->oPurchaseDate = $this->device->purchase_date?->format('Y-m-d');
        $this->oCostType     = $this->device->cost_type_id;
        $this->oCostCenter   = $this->device->cost_center_id;
    }

    /** owner/admin im aktiven Team? (analog Costs/Import) */
    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    public function toggleRawData(): void
    {
        $this->showRawData = !$this->showRawData;
    }

    public function editCost(): void
    {
        $this->fillCostForm();
        $this->editingCost = true;
    }

    public function cancelCost(): void
    {
        $this->editingCost = false;
        $this->fillCostForm();
    }

    public function saveCost(): void
    {
        abort_unless($this->canManage(), 403);

        foreach (['oMonthly', 'oPurchase', 'oDep'] as $f) {
            if ($this->$f === '') $this->$f = null;
        }
        // Leere FK-Selects (0/'') zu null normalisieren, damit nullable greift (keine id=0-Validierung).
        if (! $this->oCostType)   $this->oCostType   = null;
        if (! $this->oCostCenter) $this->oCostCenter = null;

        // cost_type_id/cost_center_id team-scopen (analog saveLifecycle()): eine Fremd-Team-ID wird als
        // 422 abgelehnt statt roh als danglender cross-team FK geschrieben.
        $this->validate([
            'oMonthly'      => 'nullable|numeric|min:0',
            'oPurchase'     => 'nullable|numeric|min:0',
            'oDep'          => 'nullable|integer|min:1',
            'oPurchaseDate' => 'nullable|date',
            'oCostType'     => ['nullable', 'integer', Rule::exists('asset_cost_types', 'id')->where('team_id', $this->device->team_id)],
            'oCostCenter'   => ['nullable', 'integer', Rule::exists('asset_cost_centers', 'id')->where('team_id', $this->device->team_id)],
        ]);

        $this->device->update([
            'monthly_cost'        => $this->oMonthly !== null ? $this->oMonthly : null,
            'purchase_price'      => $this->oPurchase !== null ? $this->oPurchase : null,
            'depreciation_months' => $this->oDep ?: null,
            'purchase_date'       => $this->oPurchaseDate ?: null,
            'cost_type_id'        => $this->oCostType ?: null,
            'cost_center_id'      => $this->oCostCenter ?: null,
        ]);
        $this->device->refresh();
        $this->editingCost = false;
        $this->flash = 'Geräte-Kosten gespeichert.';
    }

    public function editNotes(): void
    {
        $this->oNotes = $this->device->notes;
        $this->editingNotes = true;
    }

    public function cancelNotes(): void
    {
        $this->editingNotes = false;
        $this->oNotes = $this->device->notes;
    }

    public function saveNotes(): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate(['oNotes' => 'nullable|string|max:2000']);

        $this->device->update(['notes' => ($this->oNotes !== null && $this->oNotes !== '') ? $this->oNotes : null]);
        $this->device->refresh();
        $this->editingNotes = false;
        $this->notesFlash = 'Notiz gespeichert.';
    }

    public function editLifecycle(): void
    {
        $this->fillLifecycleForm();
        $this->editingLifecycle = true;
    }

    public function cancelLifecycle(): void
    {
        $this->editingLifecycle = false;
        $this->fillLifecycleForm();
    }

    public function saveLifecycle(): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'lStatus'    => ['nullable', Rule::in(AssetDevice::LIFECYCLE_STATUSES)],
            'lWarranty'  => 'nullable|date',
            'lLease'     => 'nullable|date',
            'lVendor'    => ['nullable', 'integer', Rule::exists('asset_vendors', 'id')->where('team_id', $this->device->team_id)],
            'lOrderNo'   => 'nullable|string|max:255',
            'lOrderDate' => 'nullable|date',
            'lLocation'  => 'nullable|string|max:255',
        ]);

        $oldStatus = $this->device->lifecycle_status;

        $this->device->update([
            'lifecycle_status' => $this->lStatus ?: null,
            'warranty_until'   => $this->lWarranty ?: null,
            'lease_until'      => $this->lLease ?: null,
            'vendor_id'        => $this->lVendor ?: null,
            'order_no'         => $this->lOrderNo ?: null,
            'order_date'       => $this->lOrderDate ?: null,
            'location'         => $this->lLocation ?: null,
        ]);
        $this->device->refresh();

        // Audit (Track B 2a): Status-Wechsel mit Akteur festhalten — Intune liefert den Lifecycle nicht,
        // er wird hier manuell gepflegt, also ist „wer/wann" sonst nirgends nachvollziehbar.
        $newStatus = $this->device->lifecycle_status;
        if ($oldStatus !== $newStatus) {
            AssetDeviceEvent::record(
                $this->device,
                'lifecycle_changed',
                'Lifecycle-Status geändert',
                AssetDevice::lifecycleLabelFor($oldStatus),
                AssetDevice::lifecycleLabelFor($newStatus),
                Auth::id(),
            );
        }

        $this->editingLifecycle = false;
        $this->lifecycleFlash = 'Lifecycle gespeichert.';
    }

    public function render()
    {
        $teamId = $this->device->team_id;

        $activities = AssetDeviceSyncLog::where('team_id', $teamId)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $events = AssetDeviceEvent::where('asset_device_id', $this->device->id)
            ->with('actor')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('asset-manager::livewire.devices.show', [
            'device'             => $this->device,
            'activities'         => $activities,
            'events'             => $events,
            'canManage'          => $this->canManage(),
            'resolvedCost'       => $this->device->resolvedMonthlyCost(),
            'resolvedCostTypeId' => $this->device->resolvedCostTypeId(),
            'deviceModel'        => $this->device->deviceModel(),
            'costTypes'          => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->orderBy('name')->get(),
            'costCenters'        => AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get(),
            'vendors'            => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
        ])->layout('platform::layouts.app');
    }
}
