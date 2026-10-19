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
 * Für paginierte **Listen** (Träger, Geräte — #889/#979) gibt es unten einen zweiten, schlichteren
 * Satz Aktionen ({@see self::toggleTableColumn()}, {@see self::reorderTableColumns()}): dort rendert
 * der Server nur die sichtbaren Spalten, weil eine Listenseite billig ist. Speicherort und Format
 * sind dieselben.
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

    // --- Listen-Tabellen (server-gerendert, #889/#979) -------------------------------------------
    //
    // Die Pivot-Tabelle oben versteckt im Browser, weil ihr render() eine ganze Auswertung kostet.
    // Eine paginierte Liste (Träger, Geräte) rendert dagegen nur eine Seite — dort ist der schlichte
    // Weg der robustere: der Server rendert genau die sichtbaren Spalten in Nutzer-Reihenfolge, und
    // Ausblenden/Verschieben ist ein normaler Livewire-Roundtrip. Gespeichert wird über denselben
    // Service, im selben Format, pro (User × Team × Tabelle). Filter, Sortierung und Trefferzahl
    // fasst nichts davon an (ADR 0020 §2).

    /**
     * Alle Spalten in der Anzeige-Reihenfolge des Nutzers, **inklusive** ausgeblendeter.
     *
     * Anders als {@see TableViewState::orderColumns()} (hängt Unbekanntes hinten an) landet eine neue
     * Spalte hier an ihrer definierten Position: eine Liste hat feste, im Code benannte Spalten, und
     * eine neu eingeführte Spalte soll gerade bei den Nutzern sichtbar werden, die die Tabelle schon
     * angepasst haben — nicht hinter allem.
     *
     * @return array<int,string>
     */
    protected function tableColumnOrder(): array
    {
        return self::mergeColumnOrder($this->tableView()->order, [], $this->tableViewColumnKeys());
    }

    /** @return array<int,string> Spalten in Anzeige-Reihenfolge ohne die ausgeblendeten. */
    protected function visibleTableColumns(): array
    {
        $state = $this->tableView();

        return array_values(array_filter(
            $this->tableColumnOrder(),
            fn (string $key) => ! $state->isColumnHidden($key),
        ));
    }

    /**
     * Spalte aus-/einblenden (rendert neu). Unbekannte Schlüssel werden ignoriert; die letzte
     * sichtbare Spalte bleibt stehen — eine Tabelle ohne Spalten wäre kein Blick, sondern ein Fehler.
     */
    public function toggleTableColumn(string $column): void
    {
        $keys = $this->tableViewColumnKeys();
        if (! in_array($column, $keys, true)) {
            return;
        }

        $state = $this->tableView();
        if (! $state->isColumnHidden($column) && count($this->visibleTableColumns()) <= 1) {
            return;
        }

        $this->tableViewState = $this->tableViewService()->save(
            $this->teamId(),
            $this->currentUserId(),
            $this->tableViewKey(),
            $state->withColumnToggled($column),
        );
    }

    /**
     * Neue Reihenfolge aus dem Drag&Drop am Tabellenkopf (wotz/livewire-sortablejs).
     *
     * Der Browser kennt nur die **gerenderten** Spalten. Was gerade nicht gerendert ist (ausgeblendet,
     * vom Preset oder vom Controlling-Schalter verborgen), behält deshalb seine bisherige Position —
     * sonst würde jedes Umsortieren die unsichtbaren Spalten still verschieben.
     */
    public function reorderTableColumns(array $order): void
    {
        $this->saveTableColumnOrder(self::mergeColumnOrder(
            self::flattenSortablePayload($order),
            $this->tableColumnOrder(),
            $this->tableViewColumnKeys(),
        ));
    }

    /**
     * Eine vollständige Spaltenreihenfolge speichern. Entspricht sie der Ursprungsreihenfolge, wird
     * keine Reihenfolge gespeichert — „angepasst" bleibt eine Frage der Existenz (ADR 0020 §1).
     *
     * @param  array<int,string>  $order
     */
    protected function saveTableColumnOrder(array $order): void
    {
        $keys  = $this->tableViewColumnKeys();
        $order = self::mergeColumnOrder($order, [], $keys);

        $this->tableViewState = $this->tableViewService()->save(
            $this->teamId(),
            $this->currentUserId(),
            $this->tableViewKey(),
            TableViewState::fromArray(['order' => $order === $keys ? [] : $order] + $this->tableView()->toArray()),
        );
    }

    /**
     * wotz/livewire-sortablejs liefert `[['order' => 1, 'value' => 'device'], …]`, ältere Aufrufer
     * eine flache Liste. Beides wird zu einer flachen Liste von Strings; alles andere fällt weg.
     *
     * @return array<int,string>
     */
    protected static function flattenSortablePayload(array $order): array
    {
        $items = array_values($order);

        $wrapped = $items !== [] && array_reduce(
            $items,
            fn (bool $carry, $item) => $carry && is_array($item) && is_numeric($item['order'] ?? null),
            true,
        );
        if ($wrapped) {
            usort($items, fn ($a, $b) => (int) $a['order'] <=> (int) $b['order']);
        }

        $flat = [];
        foreach ($items as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : $item;
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (is_string($value)) {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    /**
     * Die eine Regel für Listen-Spaltenreihenfolgen — rein, ohne Zustand, darum direkt prüfbar.
     *
     * 1. `$incoming` gilt, soweit es echte Spalten sind (Unbekanntes und Dubletten fallen weg — eine
     *    doppelte Spalte würde zweimal gerendert, mit doppeltem `wire:key`).
     * 2. Was in `$incoming` fehlt, aber in `$previous` stand, kommt an seine bisherige Position.
     * 3. Was in beiden fehlt (neu eingeführte Spalte), kommt an seine Position in `$available`.
     *
     * @param  array<int,string>  $incoming
     * @param  array<int,string>  $previous
     * @param  array<int,string>  $available  alle Spalten in Ursprungsreihenfolge
     * @return array<int,string>
     */
    protected static function mergeColumnOrder(array $incoming, array $previous, array $available): array
    {
        $result = [];
        foreach ($incoming as $key) {
            if (in_array($key, $available, true) && ! in_array($key, $result, true)) {
                $result[] = $key;
            }
        }

        $previousIndex = 0;
        foreach ($previous as $key) {
            if (! in_array($key, $available, true)) {
                continue;
            }
            if (! in_array($key, $result, true)) {
                array_splice($result, min($previousIndex, count($result)), 0, [$key]);
            }
            $previousIndex++;
        }

        foreach ($available as $index => $key) {
            if (! in_array($key, $result, true)) {
                array_splice($result, min($index, count($result)), 0, [$key]);
            }
        }

        return $result;
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
