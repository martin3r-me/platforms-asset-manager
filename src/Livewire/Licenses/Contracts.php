<?php

namespace Platform\AssetManager\Livewire\Licenses;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Services\LicenseInvoiceImportService;
use Platform\AssetManager\Services\LicenseSeatAllocator;
use Platform\AssetManager\Support\LicensePriceBook;

/**
 * Vertragszeilen eines Abrechnungsmonats: was bezahlt wird, wofür, und wo es landet.
 *
 * Drei Aufgaben, die nur hier erledigt werden können:
 *
 * 1. **Offene Positionen zuordnen.** Was die automatische Erkennung nicht sicher trifft, bekommt hier
 *    seine Lizenz — oder wird bewusst ausgeschlossen. Beides wird als Alias gelernt und gilt künftig.
 * 2. **Bindung bestätigen und Kündigungsdatum pflegen.** Beides steht in keiner Rechnung: die Bindung
 *    wird aus Rechnungssignalen vorbelegt, das Kündigungsdatum kann nur ein Mensch kennen.
 * 3. **Die Befunde lesen.** Monats-Seats über dem Bedarf (auf Jahresbindung umstellbar) und bezahlter
 *    Leerstand mit Kündigungsdatum sind die beiden Zahlen, aus denen Einsparungen entstehen.
 */
class Contracts extends Component
{
    use ResolvesCurrentTeam;
    use AuthorizesTeamRole;

    public string $period = '';

    /** Zeile, die gerade zugeordnet wird (Modal). */
    public ?int $assigningLineId = null;

    public ?string $assignSkuId = null;

    /** Zeile, deren Bindung/Kündigungsdatum bearbeitet wird. */
    public ?int $editingLineId = null;

    public string $editBinding = AssetLicenseContractLine::BINDING_MONTHLY;

    public ?string $editCancellableAt = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->period = (string) (AssetLicenseContractLine::where('team_id', $this->teamId())
            ->max('period_label') ?? '');
    }

    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    // ---- Zuordnen ---------------------------------------------------------------------------

    public function startAssign(int $lineId): void
    {
        abort_unless($this->canManage(), 403);

        $line = $this->lineOrFail($lineId);

        $this->assigningLineId = $line->id;
        $this->assignSkuId     = $line->sku_id;
    }

    public function cancelAssign(): void
    {
        $this->assigningLineId = null;
        $this->assignSkuId     = null;
    }

    public function saveAssign(LicenseInvoiceImportService $service): void
    {
        abort_unless($this->canManage(), 403);

        $line = $this->lineOrFail((int) $this->assigningLineId);

        // Leere Auswahl heißt „gehört zu keiner Lizenz" — eine Entscheidung, nicht ein Nichtstun.
        $skuId = $this->assignSkuId === '' ? null : $this->assignSkuId;

        $service->assignSku($this->teamId(), $line->id, $skuId, $this->activeTenantId());

        LicensePriceBook::flush();

        $this->flash = $skuId === null
            ? 'Position ausgeschlossen — sie fließt in keine Kostenstelle.'
            : 'Zugeordnet und gemerkt. Die Mengen sind neu verteilt.';

        $this->cancelAssign();
    }

    // ---- Bindung und Kündigungsdatum --------------------------------------------------------

    public function startEdit(int $lineId): void
    {
        abort_unless($this->canManage(), 403);

        $line = $this->lineOrFail($lineId);

        $this->editingLineId     = $line->id;
        $this->editBinding       = $line->binding;
        $this->editCancellableAt = $line->cancellable_at?->format('Y-m-d');
    }

    public function cancelEdit(): void
    {
        $this->editingLineId     = null;
        $this->editCancellableAt = null;
    }

    public function saveEdit(LicenseSeatAllocator $allocator): void
    {
        abort_unless($this->canManage(), 403);

        $line = $this->lineOrFail((int) $this->editingLineId);

        $this->validate([
            'editBinding'       => 'required|in:' . implode(',', AssetLicenseContractLine::BINDINGS),
            'editCancellableAt' => 'nullable|date',
        ], [], [
            'editBinding'       => 'Bindung',
            'editCancellableAt' => 'Kündigungsdatum',
        ]);

        $bindingChanged = $line->binding !== $this->editBinding;

        $line->update([
            'binding'           => $this->editBinding,
            // Ab jetzt Handpflege: kein Import überschreibt diese Bindung mehr.
            'binding_confirmed' => true,
            'cancellable_at'    => $this->editCancellableAt ?: null,
        ]);

        // Die Bindung steuert die Kaskade — eine Änderung verschiebt Mengen zwischen Trägern und
        // Sammelstellen und muss sofort nachgezogen werden.
        if ($bindingChanged) {
            $allocator->allocateForPeriod(
                $this->teamId(),
                $this->activeTenantId(),
                $line->period_label,
                false,
            );
        }

        LicensePriceBook::flush();

        $this->flash = $bindingChanged
            ? 'Bindung bestätigt — die Mengen sind neu verteilt.'
            : 'Gespeichert.';

        $this->cancelEdit();
    }

    protected function lineOrFail(int $lineId): AssetLicenseContractLine
    {
        return AssetLicenseContractLine::where('team_id', $this->teamId())->findOrFail($lineId);
    }

    public function render()
    {
        $teamId = $this->teamId();

        $periods = AssetLicenseContractLine::where('team_id', $teamId)
            ->distinct()
            ->orderByDesc('period_label')
            ->pluck('period_label');

        if ($this->period === '' && $periods->isNotEmpty()) {
            $this->period = (string) $periods->first();
        }

        $lines = $this->period === ''
            ? collect()
            : AssetLicenseContractLine::where('team_id', $teamId)
                ->forPeriod($this->period)
                ->orderBy('edition')
                ->get();

        $skus = AssetLicenseSku::where('team_id', $teamId)
            ->orderBy('display_name')
            ->get(['sku_id', 'sku_part_number', 'display_name', 'purchased_units', 'consumed_units']);

        $skuNames = $skus->mapWithKeys(fn ($sku) => [
            $sku->sku_id => $sku->display_name ?: $sku->sku_part_number,
        ]);

        return view('asset-manager::livewire.licenses.contracts', [
            'periods'  => $periods,
            'lines'    => $lines,
            'skus'     => $skus,
            'skuNames' => $skuNames,
            'totals'   => [
                'invoice'  => round($lines->sum(fn ($l) => $l->monthlyAmount()), 2),
                'seats'    => round($lines->sum('assigned_quantity'), 2),
                'overflow' => round($lines->sum(fn ($l) => (float) $l->unit_price * (float) $l->overflow_quantity), 2),
                'pool'     => round($lines->sum(fn ($l) => (float) $l->unit_price * (float) $l->pool_quantity), 2),
                'open'     => $lines->filter(fn ($l) => ! $l->isMatched()
                    && $l->match_state !== AssetLicenseContractLine::MATCH_IGNORED)->count(),
            ],
            'canManage' => $this->canManage(),
        ])->layout('platform::layouts.app');
    }
}
