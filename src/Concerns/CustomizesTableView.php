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
 * **Nur drei Aktionen, und die häufigste rendert nicht neu.** Sichtbarkeit, Faltung und Breiten wendet
 * der Browser selbst an (eine generierte CSS-Regel); {@see self::syncTableView()} speichert den
 * Endzustand und ruft {@see \Livewire\Component::skipRender()}. Ohne das liefe für jeden Klick
 * `render()` und damit die komplette Kostenaggregation — alle Asset-Träger, alle Items, das
 * Lizenz-Preisbuch, die Gerätekosten. Genau das machte die Tabelle träge.
 *
 * Neu gerendert wird nur, wo sich das DOM ändern muss: beim **Verschieben** einer Spalte (CSS kann
 * Tabellenzellen nicht umsortieren) und beim **Zurücksetzen**. Beide rendern normal, und die Ansicht
 * setzt sich dabei über ihren `wire:key`-Fingerprint im Browser neu auf — eine gemorphte
 * Alpine-Komponente wertet ihr `x-data` sonst nicht neu aus und behielte den alten Spiegel.
 *
 * Der Zustand liegt **nicht** als Livewire-Property auf dem Draht, sondern wird pro Request aus der
 * Datenbank gelesen (memoisiert, eine Query) und nach jeder Änderung geschrieben.
 *
 * Kein `asset-manager.manage`-Gate: das hier ändert keine Daten, nur den eigenen Blick darauf. Jede
 * Methode arbeitet ausschließlich auf (aktueller User × aktuelles Team) — fremde Ansichten sind nicht
 * adressierbar. Der Payload kommt aus dem Browser und wird von {@see TableViewState::fromArray()}
 * geprüft, nicht geglaubt.
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

    /**
     * Den im Browser gebauten Zustand speichern — **ohne** Neu-Rendern.
     *
     * Erwartet die Transportform von {@see TableViewState::toArray()} (also genau das, was der Browser
     * beim Rendern bekommen hat). Fehlende Felder behalten ihren gespeicherten Wert; alles Unbekannte
     * fällt beim Prüfen weg. Der Browser bündelt schnelle Klicks und schickt den Endzustand.
     *
     * @param  array<string,mixed>  $view
     */
    public function syncTableView(array $view): void
    {
        $merged = TableViewState::fromArray($view + $this->tableView()->toArray());

        $this->tableViewState = $this->tableViewService()
            ->save($this->teamId(), $this->currentUserId(), $this->tableViewKey(), $merged);

        // Der Browser zeigt den neuen Stand bereits — eine HTML-Antwort wäre reine Rechenzeit.
        $this->skipRender();
    }

    /** Spalte `$column` vor `$target` einsortieren; `$target = null` heißt „ganz nach hinten". */
    public function moveColumn(string $column, ?string $target = null): void
    {
        $keys = $this->tableViewColumnKeys();

        $this->tableViewState = $this->tableViewService()->save(
            $this->teamId(),
            $this->currentUserId(),
            $this->tableViewKey(),
            $this->tableView()->withColumnMovedBefore($keys, $column, $target),
        );
    }

    /** Komplett zurück auf Werkszustand. */
    public function resetTableView(): void
    {
        $this->tableViewState = $this->tableViewService()
            ->reset($this->teamId(), $this->currentUserId(), $this->tableViewKey());
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
