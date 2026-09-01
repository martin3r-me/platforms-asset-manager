{{--
    Bedienleiste der anpassbaren Pivot-Tabelle (docs/adr/0020).

    Erwartet: $grid (PivotTableShaper::shape) — $grid['columns'] enthält AUCH die ausgeblendeten
    Spalten, damit sie hier wieder erreichbar sind.

    Läuft im x-data der Tabelle: Häkchen, Zähler und Hinweistext kommen aus dem Browser-Zustand, nicht
    aus dem gerenderten HTML. Deshalb reagiert jeder Klick sofort — gespeichert wird im Hintergrund.
--}}
<div class="flex flex-wrap items-center gap-2">

    {{-- Spalten-Menü: Sichtbarkeit je Kostenart --}}
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false">
        <button type="button" x-on:click="open = ! open"
                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-medium rounded-lg border border-[color:var(--am-border)] bg-[var(--am-surface)] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:border-[color:var(--am-border-strong)] transition-colors">
            @svg('heroicon-o-view-columns', 'w-3.5 h-3.5')
            Spalten
            <span class="tabular-nums text-[var(--am-text-muted)]"
                  x-text="visibleCols.length + '/' + cols.length">{{ count($grid['columns']) }}</span>
        </button>

        <div x-show="open" x-cloak x-on:click.outside="open = false"
             x-transition.opacity.duration.100ms
             class="absolute z-40 mt-1 w-72 max-h-80 overflow-y-auto rounded-lg border border-[color:var(--am-border)] bg-[var(--am-surface)] shadow-lg p-1.5">
            <p class="px-2 py-1.5 text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">
                Sichtbare Kostenarten
            </p>
            @foreach($grid['columns'] as $column)
                <button type="button" x-on:click="toggleCol('{{ $column['key'] }}')"
                        class="w-full flex items-center gap-2 px-2 py-1.5 text-xs text-left rounded-md hover:bg-[var(--am-bg)] transition-colors">
                    <span class="w-3.5 h-3.5 shrink-0 rounded border flex items-center justify-center transition-colors"
                          x-bind:class="isColHidden(cols[{{ $loop->index }}])
                              ? 'border-[color:var(--am-border-strong)] text-transparent'
                              : 'border-[color:var(--am-primary)] bg-[var(--am-primary)] text-[var(--am-on-primary)]'">
                        @svg('heroicon-o-check', 'w-3 h-3')
                    </span>
                    <span class="flex-1 truncate"
                          x-bind:class="isColHidden(cols[{{ $loop->index }}]) ? 'text-[var(--am-text-muted)]' : 'text-[var(--am-text)]'">{{ $column['name'] }}</span>
                    @if($column['empty'])
                        <span class="text-[9px] uppercase tracking-wider text-[var(--am-text-muted)]">leer</span>
                    @endif
                </button>
            @endforeach

            <div class="border-t border-[color:var(--am-border)] mt-1.5 pt-1.5 space-y-0.5">
                <button type="button" x-on:click="toggleFlag('hide_empty_columns')"
                        class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-xs rounded-md hover:bg-[var(--am-bg)] transition-colors">
                    <span class="text-[var(--am-text-secondary)]">Leere Spalten ausblenden</span>
                    <span class="text-[10px] font-medium"
                          x-bind:class="view.hide_empty_columns ? 'text-[var(--am-primary)]' : 'text-[var(--am-text-muted)]'"
                          x-text="view.hide_empty_columns ? 'an' : 'aus'">aus</span>
                </button>
                <button type="button" x-on:click="showAllCols()"
                        class="w-full text-left px-2 py-1.5 text-xs rounded-md text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)] transition-colors">
                    Alle Spalten einblenden
                </button>
            </div>

            <p class="px-2 pt-2 pb-1 text-[10px] leading-relaxed text-[var(--am-text-muted)]">
                Spalten am Kopf verschieben (ziehen), Breite am rechten Rand ziehen, Doppelklick =
                Inhaltsbreite. Ausgeblendetes zählt weiter in die Summen.
            </p>
        </div>
    </div>

    {{-- Zeilen-Schnellfilter --}}
    <button type="button" x-on:click="toggleFlag('hide_empty_rows')"
            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-medium rounded-lg border transition-colors"
            x-bind:class="view.hide_empty_rows
                ? 'border-[color:var(--am-primary)] bg-[var(--am-primary)] text-[var(--am-on-primary)]'
                : 'border-[color:var(--am-border)] bg-[var(--am-surface)] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:border-[color:var(--am-border-strong)]'">
        @svg('heroicon-o-funnel', 'w-3.5 h-3.5')
        Nullzeilen ausblenden
    </button>

    {{-- Was gerade fehlt, plus der Weg zurück --}}
    <span x-show="hint !== ''" x-cloak class="text-[11px] text-[var(--am-text-muted)]" x-text="hint"></span>
    <button type="button" x-show="hiddenRowCount > 0" x-cloak x-on:click="showAllRows()"
            class="text-[11px] font-medium text-[var(--am-accent)] hover:underline">
        Zeilen einblenden
    </button>

    <span x-show="customized" x-cloak class="ml-auto inline-flex items-center gap-1.5">
        <button type="button" x-on:click="resetWidths()"
                class="text-[11px] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:underline">
            Breiten zurücksetzen
        </button>
        <span class="text-[var(--am-text-muted)]">·</span>
        <button type="button" wire:click="resetTableView"
                class="text-[11px] font-medium text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:underline">
            Ansicht zurücksetzen
        </button>
    </span>
</div>
