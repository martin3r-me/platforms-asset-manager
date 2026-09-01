{{--
    Bedienleiste der anpassbaren Pivot-Tabelle (docs/adr/0020).
    Erwartet: $grid (PivotTableShaper::shape) — $grid['columns'] enthält AUCH die ausgeblendeten
    Spalten, damit sie hier wieder erreichbar sind.
--}}
@php
    $stats   = $grid['stats'];
    $columns = $grid['columns'];

    // Kurzfassung dessen, was gerade nicht zu sehen ist — damit niemand vor einer Tabelle sitzt und
    // sich fragt, wo eine Kostenart geblieben ist.
    $notes = [];
    if ($stats['hiddenColumns'] > 0) {
        $notes[] = $stats['hiddenColumns'] . ' ' . ($stats['hiddenColumns'] === 1 ? 'Spalte' : 'Spalten') . ' ausgeblendet';
    }
    if ($stats['hiddenRows'] > 0) {
        $notes[] = $stats['hiddenRows'] . ' ' . ($stats['hiddenRows'] === 1 ? 'Zeile' : 'Zeilen') . ' ausgeblendet';
    }
    if ($stats['collapsedGroups'] > 0) {
        $notes[] = $stats['collapsedGroups'] . ' ' . ($stats['collapsedGroups'] === 1 ? 'Gruppe' : 'Gruppen') . ' eingeklappt';
    }
@endphp

<div class="flex flex-wrap items-center gap-2">

    {{-- Spalten-Menü: Sichtbarkeit je Kostenart --}}
    <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
        <button type="button" @click="open = ! open"
                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-medium rounded-lg border border-[color:var(--am-border)] bg-[var(--am-surface)] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:border-[color:var(--am-border-strong)] transition-colors">
            @svg('heroicon-o-view-columns', 'w-3.5 h-3.5')
            Spalten
            <span class="tabular-nums text-[var(--am-text-muted)]">{{ count($columns) - $stats['hiddenColumns'] }}/{{ count($columns) }}</span>
        </button>

        <div x-show="open" x-cloak @click.outside="open = false"
             x-transition.opacity.duration.100ms
             class="absolute z-30 mt-1 w-72 max-h-80 overflow-y-auto rounded-lg border border-[color:var(--am-border)] bg-[var(--am-surface)] shadow-lg p-1.5">
            <p class="px-2 py-1.5 text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">
                Sichtbare Kostenarten
            </p>
            @foreach($columns as $column)
                <button type="button" wire:click="toggleColumn('{{ $column['key'] }}')"
                        class="w-full flex items-center gap-2 px-2 py-1.5 text-xs text-left rounded-md hover:bg-[var(--am-bg)] transition-colors">
                    <span class="w-3.5 h-3.5 shrink-0 rounded border flex items-center justify-center {{ $column['hidden'] ? 'border-[color:var(--am-border-strong)]' : 'border-[color:var(--am-primary)] bg-[var(--am-primary)] text-[var(--am-on-primary)]' }}">
                        @if(! $column['hidden'])
                            @svg('heroicon-m-check', 'w-3 h-3')
                        @endif
                    </span>
                    <span class="flex-1 truncate {{ $column['hidden'] ? 'text-[var(--am-text-muted)]' : 'text-[var(--am-text)]' }}">{{ $column['name'] }}</span>
                    @if($column['empty'])
                        <span class="text-[9px] uppercase tracking-wider text-[var(--am-text-muted)]">leer</span>
                    @endif
                </button>
            @endforeach

            <div class="border-t border-[color:var(--am-border)] mt-1.5 pt-1.5 space-y-0.5">
                <button type="button" wire:click="toggleTableFlag('hide_empty_columns')"
                        class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-xs rounded-md hover:bg-[var(--am-bg)] transition-colors">
                    <span class="text-[var(--am-text-secondary)]">Leere Spalten ausblenden</span>
                    <span class="text-[10px] font-medium {{ $stats['emptyColumnsFilter'] ? 'text-[var(--am-primary)]' : 'text-[var(--am-text-muted)]' }}">
                        {{ $stats['emptyColumnsFilter'] ? 'an' : 'aus' }}
                    </span>
                </button>
                <button type="button" wire:click="showAllColumns"
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
    <button type="button" wire:click="toggleTableFlag('hide_empty_rows')"
            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-medium rounded-lg border transition-colors {{ $stats['emptyRowsFilter'] ? 'border-[color:var(--am-primary)] bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'border-[color:var(--am-border)] bg-[var(--am-surface)] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:border-[color:var(--am-border-strong)]' }}">
        @svg('heroicon-o-funnel', 'w-3.5 h-3.5')
        Nullzeilen ausblenden
    </button>

    @if($notes !== [])
        <span class="text-[11px] text-[var(--am-text-muted)]">{{ implode(' · ', $notes) }}</span>
        <button type="button" wire:click="showAllRows"
                class="text-[11px] font-medium text-[var(--am-accent)] hover:underline">
            Zeilen einblenden
        </button>
    @endif

    @if($stats['customized'])
        <span class="ml-auto inline-flex items-center gap-1.5">
            <button type="button" wire:click="resetColumnWidths"
                    class="text-[11px] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:underline">
                Breiten zurücksetzen
            </button>
            <span class="text-[var(--am-text-muted)]">·</span>
            <button type="button" wire:click="resetTableView"
                    class="text-[11px] font-medium text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:underline">
                Ansicht zurücksetzen
            </button>
        </span>
    @endif
</div>
