<?php

namespace Platform\AssetManager\Concerns;

/**
 * Klemmt den Listen-Zustand (perPage, Sortierung) auf erlaubte Werte (#957).
 *
 * Warum es das gibt: `perPage`, `sortField` und `sortDirection` kommen aus dem Query-String und aus
 * direkt aufrufbaren Livewire-Methoden — also ungeprüft vom Client. `/inventory?perPage=0` endete in
 * einem DivisionByZeroError, eine unbekannte Sortierspalte in einem SQL-Fehler. Beides war ein 500er.
 *
 * Die Komponente nennt, was erlaubt ist:
 *  - `listPerPageOptions()` — erlaubte Seitengrößen; der erste Wert ist der Rückfall,
 *  - `listSortable()` — erlaubte Sortierspalten; die erste ist der Rückfall.
 *
 * Geklemmt wird nach dem Hydrieren, nach jeder Property-Änderung und vor jedem Render — so greift es
 * für Deep-Links, für `wire:model` und für direkte Methodenaufrufe gleichermaßen.
 */
trait ClampsListState
{
    /** @return list<int> */
    abstract protected function listPerPageOptions(): array;

    /** @return list<string> */
    abstract protected function listSortable(): array;

    public function hydrateClampsListState(): void
    {
        $this->clampListState();
    }

    public function updatedClampsListState(): void
    {
        $this->clampListState();
    }

    public function renderingClampsListState(): void
    {
        $this->clampListState();
    }

    protected function clampListState(): void
    {
        $options = $this->listPerPageOptions();
        if (property_exists($this, 'perPage') && $options !== [] && ! in_array((int) $this->perPage, $options, true)) {
            $this->perPage = in_array(25, $options, true) ? 25 : $options[0];
        }

        $sortable = $this->listSortable();
        if (property_exists($this, 'sortField') && $sortable !== [] && ! in_array($this->sortField, $sortable, true)) {
            $this->sortField = $sortable[0];
        }

        if (property_exists($this, 'sortDirection') && ! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = 'asc';
        }
    }
}
