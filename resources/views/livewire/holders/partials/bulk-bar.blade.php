{{--
    Auswahl-Leiste für die Sammelaktionen. Erscheint nur, wenn etwas ausgewählt ist.

    Beide Wege führen bewusst über eine Vorschau statt über ein `wire:confirm`:

    - Der Wechsel weg von „Funktionskonto" löscht das zugehörige System ohne Undo, verschiebt über
      die Verteilungskaskade Mengen zwischen Trägern und Sammel-Kostenstellen (ADR 0019), und ein
      Zurücksetzen auf „Person" kann der nächste Sync stillschweigend rückgängig machen.
    - Die Abrechnungs-Ausnahme verändert die Menge auf einer Kundenrechnung (ADR 0024).

    Ein Ja/Nein-Dialog könnte in keinem der beiden Fälle die Zahlen nennen, auf die es ankommt.

    Die Auswahl überlebt Blättern (nicht aber einen Filterwechsel) — „die zwölf Admin-Konten von
    zwei Seiten" soll man zusammensammeln können.
--}}
@if(count($selected) > 0)
    <div class="sticky top-0 z-10 flex flex-col gap-3 px-4 py-3 rounded-xl bg-[var(--am-accent-surface)] border border-[color:var(--am-accent)] shadow-sm">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium text-[var(--am-text)]">
                {{ count($selected) }} Asset-Träger ausgewählt
            </span>

            @if(count($selected) < $holders->total())
                <button type="button" wire:click="selectAllFiltered"
                        class="text-xs font-medium text-[var(--am-accent)] underline hover:opacity-80">
                    Alle {{ number_format(min($holders->total(), $bulkMax), 0, ',', '.') }} gefilterten auswählen
                </button>
            @endif

            @if($this->selectionCapped())
                <span class="text-xs text-amber-700">
                    Auswahl auf {{ $bulkMax }} begrenzt — Filter enger setzen und erneut anwenden.
                </span>
            @endif

            <span class="flex-1"></span>

            <div class="flex items-center gap-2">
                <label class="text-xs text-[var(--am-text-secondary)]">Typ setzen auf</label>
                {{-- `.live` ist Pflicht, nicht Geschmack: der Vorschau-Knopf daneben haengt per
                     :disabled an $bulkType. Deferred gebunden erfaehrt der Server die Auswahl erst beim
                     naechsten Roundtrip — der Knopf bliebe gesperrt und der Klick wirkungslos. --}}
                <select wire:model.live="bulkType"
                        class="px-2 py-1.5 text-xs rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] text-[var(--am-text)] cursor-pointer focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)]">
                    <option value="">– Träger-Typ wählen –</option>
                    @foreach($holderTypes as $type)
                        <option value="{{ $type }}">{{ \Platform\AssetManager\Models\AssetHolder::holderTypeLabelFor($type) }}</option>
                    @endforeach
                </select>

                <x-asset-manager-button variant="primary" size="sm" wire:click="openBulkType"
                                        :disabled="! $bulkType" wire:loading.attr="disabled">
                    @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                    Vorschau
                </x-asset-manager-button>

                <x-asset-manager-button variant="ghost" size="sm" wire:click="clearSelection">
                    Auswahl aufheben
                </x-asset-manager-button>
            </div>
        </div>

        {{-- Zweite Zeile: Weiterberechnung (ADR 0024). Bewusst abgesetzt und nicht in dieselbe
             Zeile gedrängt — sie fragt nach einem Grund, und ein Pflichtfeld braucht Platz. Der
             Grund steht links vom Knopf, weil er die Voraussetzung ist, nicht ein Zusatz. --}}
        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-[color:var(--am-accent)]/30">
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-[var(--am-text-secondary)]">
                @svg('heroicon-o-receipt-percent', 'w-3.5 h-3.5')
                Weiterberechnung
            </span>

            {{-- Der Wrapper traegt die Flex-Klassen, nicht das Feld: die Komponente merged ihr
                 eigenes `w-full` dazu, und ein `w-full` direkt am Flex-Kind wuerde die Zeile
                 sprengen und die Knoepfe umbrechen. --}}
            <div class="flex-1 min-w-[240px]">
                <x-asset-manager-input size="sm" type="text" wire:model.blur="bulkReason"
                                       placeholder="Grund der Ausnahme (Pflicht) – z. B. „Geschäftsführung, laut Vertrag nicht berechnet“" />
            </div>

            <x-asset-manager-button variant="primary" size="sm" wire:click="openBulkBilling(true)"
                                    wire:loading.attr="disabled">
                @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                Ausnehmen – Vorschau
            </x-asset-manager-button>

            {{-- Aufheben braucht keinen Grund: es stellt den Regelzustand wieder her, und der
                 begründet sich selbst. --}}
            <x-asset-manager-button variant="secondary" size="sm" wire:click="openBulkBilling(false)"
                                    wire:loading.attr="disabled">
                @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5')
                Ausnahme aufheben
            </x-asset-manager-button>
        </div>
    </div>
@endif
