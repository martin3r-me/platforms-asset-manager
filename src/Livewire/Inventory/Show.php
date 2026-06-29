<?php

namespace Platform\AssetManager\Livewire\Inventory;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHandoverLine;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Support\AssetSubject;

/**
 * Vereinte „Inventar"-Detailseite über beide Hardware-Welten (E1/E3): per Typ-Dispatch wird
 * ENTWEDER ein manuelles Asset (AssetItem) ODER ein Intune-Gerät (AssetDevice) geladen und mit
 * geteiltem Header + Tab-Leiste + Karten gerendert. Diese Seite ist das Ziel-Einzige (assets.show/
 * devices.show werden später Redirects, Phase 5).
 *
 * Phase 2: read-only Anzeige. Bearbeiten läuft hier noch über Links auf die klassischen Editoren;
 * Edit-/Zuordnen-/Lösch-Modals folgen in Phase 3 (manuell) bzw. Phase 4 (Gerät, via AssetWriteService).
 *
 * Tenant ist nur Arbeitsfilter (ADR 0003): die Detailseite erzwingt NUR den Team-Check (kein
 * Tenant-403) — ein Datensatz eines anderen Tenants desselben Teams bleibt per URL sichtbar.
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
        ])->layout('platform::layouts.app');
    }
}
