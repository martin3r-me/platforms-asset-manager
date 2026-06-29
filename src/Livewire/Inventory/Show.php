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
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetHandoverLine;
use Platform\AssetManager\Models\AssetItem;
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
            'categories'    => AssetCategory::orderBy('sort_order')->get(),
            'employees'     => AssetEmployee::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get(),
        ])->layout('platform::layouts.app');
    }
}
