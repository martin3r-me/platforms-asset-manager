{{--
    Auswahl-Leiste. Erscheint nur, wenn etwas ausgewählt ist.

    Die Auswahl überlebt Blättern und Ansichtswechsel bewusst — "die 7 necta-Zeilen umbuchen"
    soll nicht daran scheitern, dass man zwischendurch die Achse gewechselt hat. Damit trotzdem
    niemand blind umbucht, führt der Weg über eine Dry-Run-Vorschau statt über ein wire:confirm.
--}}
@if(count($selected) > 0)
    <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-[var(--am-accent-surface)] border border-[color:var(--am-accent)] shadow-sm">
        <span class="text-sm font-medium text-[var(--am-text)]">
            {{ count($selected) }} {{ count($selected) === 1 ? 'Position' : 'Positionen' }} ausgewählt
        </span>

        @if(count($selected) < $totalFiltered)
            <button type="button" wire:click="selectAllFiltered"
                    class="text-xs font-medium text-[var(--am-accent)] underline hover:opacity-80">
                Alle {{ number_format($totalFiltered, 0, ',', '.') }} gefilterten auswählen
            </button>
        @endif

        <span class="flex-1"></span>

        <div class="flex items-center gap-2">
            <label class="text-xs text-[var(--am-text-secondary)]">Umbuchen auf</label>
            {{-- `.live`: der Vorschau-Knopf haengt per :disabled an $bulkCenter. Deferred gebunden
                 erfuhr der Server die Auswahl erst beim naechsten Roundtrip — bis dahin blieb der
                 Knopf gesperrt und der Klick wirkungslos. --}}
            <select wire:model.live="bulkCenter"
                    class="px-2 py-1.5 text-xs rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] text-[var(--am-text)] cursor-pointer focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)]">
                <option value="">– Kostenstelle wählen –</option>
                @foreach($costCenters as $c)
                    <option value="{{ $c->id }}">{{ $c->tree_label }}</option>
                @endforeach
            </select>

            <x-asset-manager-button variant="primary" size="sm" wire:click="openBulkReassign"
                                    :disabled="! $bulkCenter" wire:loading.attr="disabled">
                @svg('heroicon-o-arrows-right-left', 'w-3.5 h-3.5')
                Vorschau
            </x-asset-manager-button>

            <x-asset-manager-button variant="ghost" size="sm" wire:click="clearSelection">
                Auswahl aufheben
            </x-asset-manager-button>
        </div>
    </div>
@endif
