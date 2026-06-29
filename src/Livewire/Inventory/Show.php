<?php

namespace Platform\AssetManager\Livewire\Inventory;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetHandoverLine;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Support\AssetSubject;

/**
 * Vereinte „Inventar"-Detailseite über beide Hardware-Welten (E1/E3): per Typ-Dispatch wird
 * ENTWEDER ein manuelles Asset (AssetItem) ODER ein Intune-Gerät (AssetDevice) geladen und mit
 * geteiltem Header + Tab-Leiste + Karten gerendert.
 *
 * Phase 3: Edit-/Zuordnen-/Abschreibung-/Notizen-/Lösch-Modals für **manuelle** Assets (alle über
 * AssetWriteService bzw. Item-Policy gated). Geräte-Editier-Modals folgen in Phase 4 — bis dahin
 * verlinkt der Geräte-„Bearbeiten"-Button auf die klassische devices.show.
 *
 * Tenant ist nur Arbeitsfilter (ADR 0003): die Detailseite erzwingt NUR den Team-Check.
 */
class Show extends Component
{
    use ResolvesCurrentTeam;
    use AuthorizesTeamRole;

    public string $type;
    public int    $id;

    public ?AssetItem   $item   = null;
    public ?AssetDevice $device = null;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    public ?string $flash = null;

    // --- Modals (nur manuelle Assets, Phase 3) ---
    public bool $showEdit         = false;
    public bool $showAssign       = false;
    public bool $showDepreciation = false;
    public bool $showNotes        = false;
    public bool $showDelete       = false;

    // Edit (Details)
    public ?int    $eCategoryId   = null;
    public string  $eName         = '';
    public string  $eManufacturer = '';
    public string  $eModel        = '';
    public string  $eSerial       = '';
    public string  $eStatus       = 'in_stock';

    // Zuordnen
    public ?int    $aAssigneeId = null;
    public ?string $aValidFrom  = null;
    public ?string $aValidUntil = null;

    // Abschreibung
    public ?string $dPurchaseDate       = null;
    public ?string $dPurchasePrice      = null;
    public ?int    $dDepreciationMonths = null;

    // Notizen
    public string $nNotes = '';

    // --- Geräte-Modals (nur intune, Phase 4) ---
    public bool $showDeviceEdit  = false;
    public bool $showDeviceCost  = false;
    public bool $showDeviceNotes = false;

    // Lifecycle / Beschaffung
    public ?string $glStatus    = null;
    public ?string $glWarranty  = null;
    public ?string $glLease     = null;
    public ?int    $glVendor    = null;
    public ?string $glOrderNo   = null;
    public ?string $glOrderDate = null;
    public ?string $glLocation  = null;

    // Kosten (Override)
    public ?string $gcMonthly      = null;
    public ?string $gcPurchase     = null;
    public ?int    $gcDep          = null;
    public ?string $gcPurchaseDate = null;
    public ?int    $gcCostType     = null;
    public ?int    $gcCostCenter   = null;

    // Notizen
    public string $gnNotes = '';

    public function mount(string $type, int $id): void
    {
        $this->type = $type;
        $this->id   = $id;

        $teamId = $this->teamId();

        if ($type === 'manual') {
            $this->item = AssetItem::with(['category', 'assignee'])
                ->where('team_id', $teamId)
                ->findOrFail($id);
        } elseif ($type === 'intune') {
            $this->device = AssetDevice::where('team_id', $teamId)->findOrFail($id);
        } else {
            abort(404);
        }
    }

    /** owner/admin im aktiven Team? (steuert Schreib-Controls; Backend gated zusätzlich). */
    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    /** Phase-3-Modals gelten nur für manuelle Assets — gemeinsame Vorbedingung. */
    protected function guardManualWrite(): AssetItem
    {
        abort_unless($this->item !== null, 404);
        Gate::authorize('update', $this->item);

        return $this->item;
    }

    // ---------------- Bearbeiten (Details) ----------------

    public function openEdit(): void
    {
        $item = $this->guardManualWrite();
        $this->eCategoryId   = $item->category_id;
        $this->eName         = $item->name ?? '';
        $this->eManufacturer = $item->manufacturer ?? '';
        $this->eModel        = $item->model ?? '';
        $this->eSerial       = $item->serial_number ?? '';
        $this->eStatus       = $item->status;
        $this->showEdit      = true;
    }

    public function saveEdit(AssetWriteService $writer): void
    {
        $item = $this->guardManualWrite();

        $this->validate([
            'eCategoryId'   => 'required|exists:asset_categories,id',
            'eName'         => 'required|string|max:255',
            'eManufacturer' => 'nullable|string|max:255',
            'eModel'        => 'nullable|string|max:255',
            'eSerial'       => 'nullable|string|max:255',
            'eStatus'       => 'required|in:in_stock,assigned,retired,lost',
        ]);

        $writer->updateItemDetails($item, [
            'categoryId'   => $this->eCategoryId,
            'name'         => $this->eName,
            'manufacturer' => $this->eManufacturer,
            'model'        => $this->eModel,
            'serialNumber' => $this->eSerial,
            'status'       => $this->eStatus,
        ]);

        $this->item->refresh();
        $this->showEdit = false;
        $this->flash = 'Details gespeichert.';
    }

    // ---------------- Zuordnen ----------------

    public function openAssign(): void
    {
        $item = $this->guardManualWrite();
        $this->aAssigneeId = $item->assignee_id;
        $this->aValidFrom  = now()->format('Y-m-d');
        $this->aValidUntil = null;
        $this->showAssign  = true;
    }

    public function saveAssign(AssetWriteService $writer): void
    {
        $item = $this->guardManualWrite();

        $this->validate([
            'aAssigneeId' => ['nullable', 'integer', Rule::exists('asset_employees', 'id')->where('team_id', $item->team_id)],
            'aValidFrom'  => 'nullable|date',
            'aValidUntil' => 'nullable|date|after_or_equal:aValidFrom',
        ]);

        $employee = $this->aAssigneeId
            ? AssetEmployee::where('team_id', $item->team_id)->find($this->aAssigneeId)
            : null;

        $writer->assignItem($item, $employee, $this->aValidFrom, $this->aValidUntil);

        $this->item->refresh();
        $this->showAssign = false;
        $this->flash = $employee ? 'Zuordnung gespeichert.' : 'Ins Lager zurückgebucht.';
    }

    // ---------------- Abschreibung ----------------

    public function openDepreciation(): void
    {
        $item = $this->guardManualWrite();
        $this->dPurchaseDate       = $item->purchase_date?->format('Y-m-d');
        $this->dPurchasePrice      = $item->purchase_price !== null ? (string) $item->purchase_price : null;
        $this->dDepreciationMonths = $item->depreciation_months;
        $this->showDepreciation    = true;
    }

    public function saveDepreciation(AssetWriteService $writer): void
    {
        $item = $this->guardManualWrite();

        $this->validate([
            'dPurchaseDate'       => 'nullable|date',
            'dPurchasePrice'      => 'nullable|numeric|min:0',
            'dDepreciationMonths' => 'nullable|integer|min:1|max:240',
        ]);

        $writer->updateItemDepreciation($item, [
            'purchaseDate'       => $this->dPurchaseDate,
            'purchasePrice'      => $this->dPurchasePrice,
            'depreciationMonths' => $this->dDepreciationMonths,
        ]);

        $this->item->refresh();
        $this->showDepreciation = false;
        $this->flash = 'Abschreibung gespeichert.';
    }

    // ---------------- Notizen ----------------

    public function openNotes(): void
    {
        $item = $this->guardManualWrite();
        $this->nNotes    = $item->notes ?? '';
        $this->showNotes = true;
    }

    public function saveNotes(AssetWriteService $writer): void
    {
        $item = $this->guardManualWrite();

        $this->validate(['nNotes' => 'nullable|string|max:2000']);

        $writer->updateItemNotes($item, $this->nNotes);

        $this->item->refresh();
        $this->showNotes = false;
        $this->flash = 'Notiz gespeichert.';
    }

    // ---------------- Löschen ----------------

    public function openDelete(): void
    {
        $this->guardManualWrite();
        $this->showDelete = true;
    }

    public function deleteItem()
    {
        abort_unless($this->item !== null, 404);
        Gate::authorize('delete', $this->item);

        $this->item->delete();

        return redirect()->route('asset-manager.inventory.index');
    }

    // ---------------- Gerät: Lifecycle / Beschaffung / Kosten / Notizen (Phase 4) ----------------

    /** Geräte-Schreibpfade sind owner/admin-gated (kein AssetDevicePolicy::update — wie Devices/Show). */
    protected function guardDeviceWrite(): AssetDevice
    {
        abort_unless($this->device !== null, 404);
        abort_unless($this->canManage(), 403);

        return $this->device;
    }

    public function openDeviceEdit(): void
    {
        $d = $this->guardDeviceWrite();
        $this->glStatus    = $d->lifecycle_status;
        $this->glWarranty  = $d->warranty_until?->format('Y-m-d');
        $this->glLease     = $d->lease_until?->format('Y-m-d');
        $this->glVendor    = $d->vendor_id;
        $this->glOrderNo   = $d->order_no;
        $this->glOrderDate = $d->order_date?->format('Y-m-d');
        $this->glLocation  = $d->location;
        $this->showDeviceEdit = true;
    }

    public function saveDeviceEdit(AssetWriteService $writer): void
    {
        $d = $this->guardDeviceWrite();

        $this->validate([
            'glStatus'    => ['nullable', Rule::in(AssetDevice::LIFECYCLE_STATUSES)],
            'glWarranty'  => 'nullable|date',
            'glLease'     => 'nullable|date',
            'glVendor'    => ['nullable', 'integer', Rule::exists('asset_vendors', 'id')->where('team_id', $d->team_id)],
            'glOrderNo'   => 'nullable|string|max:255',
            'glOrderDate' => 'nullable|date',
            'glLocation'  => 'nullable|string|max:255',
        ]);

        $writer->updateDeviceLifecycle($d, [
            'status'    => $this->glStatus,
            'warranty'  => $this->glWarranty,
            'lease'     => $this->glLease,
            'vendor'    => $this->glVendor,
            'orderNo'   => $this->glOrderNo,
            'orderDate' => $this->glOrderDate,
            'location'  => $this->glLocation,
        ], (int) Auth::id());

        $this->device->refresh();
        $this->showDeviceEdit = false;
        $this->flash = 'Lifecycle / Beschaffung gespeichert.';
    }

    public function openDeviceCost(): void
    {
        $d = $this->guardDeviceWrite();
        $this->gcMonthly      = $d->monthly_cost;
        $this->gcPurchase     = $d->purchase_price;
        $this->gcDep          = $d->depreciation_months;
        $this->gcPurchaseDate = $d->purchase_date?->format('Y-m-d');
        $this->gcCostType     = $d->cost_type_id;
        $this->gcCostCenter   = $d->cost_center_id;
        $this->showDeviceCost = true;
    }

    public function saveDeviceCost(AssetWriteService $writer): void
    {
        $d = $this->guardDeviceWrite();

        if (! $this->gcCostType)   $this->gcCostType   = null;
        if (! $this->gcCostCenter) $this->gcCostCenter = null;

        $this->validate([
            'gcMonthly'      => 'nullable|numeric|min:0',
            'gcPurchase'     => 'nullable|numeric|min:0',
            'gcDep'          => 'nullable|integer|min:1',
            'gcPurchaseDate' => 'nullable|date',
            'gcCostType'     => ['nullable', 'integer', Rule::exists('asset_cost_types', 'id')->where('team_id', $d->team_id)],
            'gcCostCenter'   => ['nullable', 'integer', Rule::exists('asset_cost_centers', 'id')->where('team_id', $d->team_id)],
        ]);

        $writer->updateDeviceCost($d, [
            'monthly'      => $this->gcMonthly,
            'purchase'     => $this->gcPurchase,
            'dep'          => $this->gcDep,
            'purchaseDate' => $this->gcPurchaseDate,
            'costType'     => $this->gcCostType,
            'costCenter'   => $this->gcCostCenter,
        ]);

        $this->device->refresh();
        $this->showDeviceCost = false;
        $this->flash = 'Geräte-Kosten gespeichert.';
    }

    public function openDeviceNotes(): void
    {
        $d = $this->guardDeviceWrite();
        $this->gnNotes = $d->notes ?? '';
        $this->showDeviceNotes = true;
    }

    public function saveDeviceNotes(AssetWriteService $writer): void
    {
        $d = $this->guardDeviceWrite();

        $this->validate(['gnNotes' => 'nullable|string|max:2000']);

        $writer->updateDeviceNotes($d, $this->gnNotes);

        $this->device->refresh();
        $this->showDeviceNotes = false;
        $this->flash = 'Notiz gespeichert.';
    }

    public function render()
    {
        $teamId = $this->teamId();

        if ($this->item) {
            $subject       = AssetSubject::fromItem($this->item);
            $assignments   = $this->item->assignments()->with('employee')->limit(20)->get();
            $handoverLines = collect();
        } else {
            $subject       = AssetSubject::fromDevice($this->device);
            $assignments   = $this->device->assignments()->with('employee')->limit(20)->get();

            // Geräteausgabe-Zeilen dieses Geräts (E6) — gleiche Query wie Devices/Show.
            $handoverLines = AssetHandoverLine::with('handover.employee')
                ->whereHas('handover', fn ($q) => $q->where('team_id', $teamId))
                ->where('asset_device_id', $this->device->id)
                ->orderByDesc('id')
                ->get();
        }

        return view('asset-manager::livewire.inventory.show', [
            'subject'       => $subject,
            'item'          => $this->item,
            'device'        => $this->device,
            'assignments'   => $assignments,
            'handoverLines' => $handoverLines,
            'canManage'     => $this->canManage(),
            // Manuell: Kategorie/Mitarbeiter-Selects. Gerät: Kostenart/-stelle/Kreditor-Selects.
            'categories'    => $this->item ? AssetCategory::orderBy('sort_order')->get() : collect(),
            'employees'     => $this->item ? AssetEmployee::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get() : collect(),
            'costTypes'     => $this->device ? AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->orderBy('name')->get() : collect(),
            'costCenters'   => $this->device ? AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get() : collect(),
            'vendors'       => $this->device ? AssetVendor::where('team_id', $teamId)->orderBy('name')->get() : collect(),
        ])->layout('platform::layouts.app');
    }
}
