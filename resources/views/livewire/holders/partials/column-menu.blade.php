{{--
    Spalten-Menü der Trägerliste (ADR 0020, #889).

    Erwartet: $columnMenu (Holders\Index::tableColumnMenuFor — alle in dieser Sicht geltenden
    Spalten, auch ausgeblendete), $columns (die gerenderten), $tableCustomized.

    Jeder Klick ist ein normaler Livewire-Roundtrip: die Liste rendert nur eine Seite, der Server
    liefert genau die sichtbaren Spalten. Das Menü bleibt dabei offen (Alpine-Zustand überlebt den
    Morph), damit man mehrere Spalten nacheinander umschalten kann.
--}}
@php
    $hiddenCount = count(array_filter($columnMenu, fn ($c) => $c['hidden']));
@endphp
<div class="flex flex-wrap items-center justify-end gap-2">
    @if($hiddenCount > 0)
        <span class="text-[11px] text-[var(--am-text-muted)]">
            {{ $hiddenCount }} {{ $hiddenCount === 1 ? 'Spalte' : 'Spalten' }} ausgeblendet
        </span>
    @endif

    <div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false">
        <button type="button" x-on:click="open = ! open"
                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-medium rounded-lg border border-[color:var(--am-border)] bg-[var(--am-surface)] text-[var(--am-text-secondary)] hover:text-[var(--am-text)] hover:border-[color:var(--am-border-strong)] transition-colors">
            @svg('heroicon-o-view-columns', 'w-3.5 h-3.5')
            Spalten
            <span class="tabular-nums text-[var(--am-text-muted)]">{{ count($columns) }}/{{ count($columnMenu) }}</span>
        </button>

        <div x-show="open" x-cloak x-on:click.outside="open = false"
             x-transition.opacity.duration.100ms
             class="absolute right-0 z-40 mt-1 w-64 max-h-80 overflow-y-auto rounded-lg border border-[color:var(--am-border)] bg-[var(--am-surface)] shadow-lg p-1.5">
            <p class="px-2 py-1.5 text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">
                Sichtbare Spalten
            </p>

            @foreach($columnMenu as $column)
                <button type="button" wire:click="toggleTableColumn('{{ $column['key'] }}')" wire:key="holder-colmenu-{{ $column['key'] }}"
                        class="w-full flex items-center gap-2 px-2 py-1.5 text-xs text-left rounded-md hover:bg-[var(--am-bg)] transition-colors">
                    <span class="w-3.5 h-3.5 shrink-0 rounded border flex items-center justify-center transition-colors {{ $column['hidden'] ? 'border-[color:var(--am-border-strong)] text-transparent' : 'border-[color:var(--am-primary)] bg-[var(--am-primary)] text-[var(--am-on-primary)]' }}">
                        @svg('heroicon-o-check', 'w-3 h-3')
                    </span>
                    <span class="flex-1 truncate {{ $column['hidden'] ? 'text-[var(--am-text-muted)]' : 'text-[var(--am-text)]' }}">{{ $column['label'] }}</span>
                </button>
            @endforeach

            <p class="px-2 pt-2 pb-1 text-[10px] leading-relaxed text-[var(--am-text-muted)]">
                Name bleibt immer sichtbar. Spalten am Kopf verschieben (ziehen). Ausblenden ändert
                weder Filter noch Trefferzahl; der CSV-Export enthält immer alle Spalten.
            </p>

            @if($tableCustomized)
                <div class="border-t border-[color:var(--am-border)] mt-1.5 pt-1.5">
                    <button type="button" wire:click="resetTableView"
                            class="w-full text-left px-2 py-1.5 text-xs rounded-md text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)] transition-colors">
                        Ansicht zurücksetzen
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
