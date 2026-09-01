<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\TableViewService;
use Platform\AssetManager\Support\TableViewState;

/**
 * Macht eine Livewire-Tabelle Excel-artig anpassbar: Spaltenbreiten ziehen, Spalten verschieben,
 * Spalten und Zeilen aus-/einblenden, Baum-Gruppen einklappen — pro Nutzer gespeichert
 * (docs/adr/0020).
 *
 * Die einbindende Komponente liefert zwei Dinge: {@see self::tableViewKey()} (Speicher-Schlüssel) und
 * {@see self::tableViewColumnKeys()} (die aktuell existierenden Spalten, damit „verschieben" eine
 * lückenlose Reihenfolge speichern kann). Setzt {@see ResolvesCurrentTeam} voraus.
 *
 * **Der Zustand liegt absichtlich NICHT als Livewire-Property auf dem Draht.** Er wird pro Request aus
 * der Datenbank gelesen (memoisiert, eine Query) und nach jeder Änderung geschrieben. Gründe: der
 * Payload wäre sonst Roundtrip-Ballast in jeder Interaktion der Seite, und die gespeicherte Ansicht
 * bliebe eine Kopie im Browser, die nach dem Zurück-Button wieder auftaucht.
 *
 * Kein `asset-manager.manage`-Gate: das hier ändert keine Daten, nur den eigenen Blick darauf. Jede
 * Methode arbeitet ausschließlich auf (aktueller User × aktuelles Team) — fremde Ansichten sind
 * nicht adressierbar.
 */
trait CustomizesTableView
{
    /** Memo pro Request; nicht serialisiert (protected → kein Livewire-State). */
    protected ?TableViewState $tableViewState = null;

    /** Speicher-Schlüssel der Tabelle, z. B. `costs.allocation`. */
    abstract protected function tableViewKey(): string;

    /**
     * Alle derzeit existierenden Spalten-Schlüssel in ihrer Ursprungsreihenfolge. Basis für das
     * Einsortieren beim Verschieben — ohne sie könnte eine gespeicherte Teil-Reihenfolge nicht
     * lückenlos ergänzt werden.
     *
     * @return array<int,string>
     */
    abstract protected function tableViewColumnKeys(): array;

    /** Der geltende Zustand (memoisiert). */
    protected function tableView(): TableViewState
    {
        return $this->tableViewState ??= $this->tableViewService()
            ->load($this->teamId(), $this->currentUserId(), $this->tableViewKey());
    }

    // --- Aktionen (aus dem Blade aufgerufen) ---------------------------------------------------

    /**
     * Spaltenbreiten übernehmen — gebündelt, nicht pro Pixel: das Ziehen läuft im Browser (Alpine),
     * hier landet nur das Ergebnis beim Loslassen. Ein Roundtrip pro Mausbewegung wäre unbenutzbar.
     *
     * @param  array<string,mixed>  $widths
     */
    public function setColumnWidths(array $widths): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withWidths($widths));
    }

    /** Alle Breiten zurück auf die Defaults; Reihenfolge und Sichtbarkeit bleiben. */
    public function resetColumnWidths(): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withDefaultWidths());
    }

    /** Spalte `$column` vor `$target` einsortieren; `$target = null` heißt „ganz nach hinten". */
    public function moveColumn(string $column, ?string $target = null): void
    {
        $keys = $this->tableViewColumnKeys();

        $this->mutateTableView(
            fn (TableViewState $state) => $state->withColumnMovedBefore($keys, $column, $target),
        );
    }

    public function toggleColumn(string $column): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withColumnToggled($column));
    }

    public function showAllColumns(): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withAllColumnsVisible());
    }

    public function hideRow(string $row): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withRowHidden($row));
    }

    public function showAllRows(): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withAllRowsVisible());
    }

    public function toggleRowGroup(string $row): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withRowGroupToggled($row));
    }

    /** Schnellfilter: `hide_empty_columns` | `hide_empty_rows`. */
    public function toggleTableFlag(string $flag): void
    {
        $this->mutateTableView(fn (TableViewState $state) => $state->withFlagToggled($flag));
    }

    /** Komplett zurück auf Werkszustand. */
    public function resetTableView(): void
    {
        $this->tableViewState = $this->tableViewService()
            ->reset($this->teamId(), $this->currentUserId(), $this->tableViewKey());

        $this->announceTableView();
    }

    // --- intern -------------------------------------------------------------------------------

    /**
     * Zustand ändern, speichern, den geprüften Stand behalten und dem Browser mitteilen.
     *
     * Das Event ist nötig, weil die Breiten im Browser gespiegelt liegen (fürs flüssige Ziehen):
     * setzt der Server sie zurück, muss Alpine seinen Spiegel nachziehen — sonst zeigt die Tabelle
     * weiter die alten Breiten, bis die Seite neu geladen wird.
     *
     * @param  callable(TableViewState): TableViewState  $mutation
     */
    protected function mutateTableView(callable $mutation): void
    {
        $next = $mutation($this->tableView());

        $this->tableViewState = $this->tableViewService()
            ->save($this->teamId(), $this->currentUserId(), $this->tableViewKey(), $next);

        $this->announceTableView();
    }

    protected function announceTableView(): void
    {
        $this->dispatch('am-table-view-updated', widths: $this->tableView()->widths);
    }

    protected function tableViewService(): TableViewService
    {
        return app(TableViewService::class);
    }

    protected function currentUserId(): int
    {
        return (int) Auth::id();
    }
}
