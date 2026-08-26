{{--
    Gruppierte Ansicht: ~20 Gruppenzeilen statt 575 Einzelzeilen auf 20 Seiten.

    Bewusst OHNE Pagination — je Achse gibt es maximal ein paar Dutzend Gruppen. Nur ein
    Overflow-Hinweis als Schutz für ein künftig sehr großes Team (Index::MAX_GROUPS).
--}}
@php
    // Muss zu den Spalten der Gruppenzeile passen: eine falsche Zahl zerlegt den
    // aufgeklappten Block lautlos.
    $groupCols = 8;

    $sumMonthly = $groups->sum('monthly');
    $sumCount   = $groups->sum('count');
@endphp

<x-asset-manager-panel body-class="p-0">
    @if($groups->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center">
            @svg('heroicon-o-banknotes', 'w-10 h-10 text-[var(--am-text-disabled)] mb-3')
            <p class="text-sm text-[var(--am-text-secondary)]">
                @if($hasFilters)
                    Keine Kostenpositionen für diese Filter.
                @else
                    Noch keine Kostenpositionen erfasst — anlegen oder aus Excel importieren.
                @endif
            </p>
            @if($hasFilters)
                <x-asset-manager-button variant="ghost" size="sm" class="mt-3" wire:click="resetFilters">
                    @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                    Filter zurücksetzen
                </x-asset-manager-button>
            @endif
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)] text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                        <th class="w-8 pl-4 pr-1 py-3"></th>
                        <th class="text-left px-2 py-3">
                            <button type="button" wire:click="sortGroupsBy('label')"
                                    class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text)]">
                                {{ $axes[$groupBy] }}
                                @if($groupSort === 'label') @svg('heroicon-o-chevron-up', 'w-3 h-3') @endif
                            </button>
                        </th>
                        <th class="px-4 py-3"></th>
                        <th class="text-right px-4 py-3">
                            <button type="button" wire:click="sortGroupsBy('count')"
                                    class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text)]">
                                Pos.
                                @if($groupSort === 'count') @svg('heroicon-o-chevron-down', 'w-3 h-3') @endif
                            </button>
                        </th>
                        <th class="text-right px-4 py-3">
                            <button type="button" wire:click="sortGroupsBy('amount')"
                                    class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text)]">
                                €/Monat
                                @if($groupSort === 'amount') @svg('heroicon-o-chevron-down', 'w-3 h-3') @endif
                            </button>
                        </th>
                        <th class="text-left px-4 py-3 w-40">Anteil</th>
                        <th class="px-4 py-3"></th>
                        <th class="pr-4 pl-2 py-3"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-[color:var(--am-border)]">
                    @foreach($groups as $g)
                        @include('asset-manager::livewire.cost-lines.partials.row-group', ['g' => $g, 'groupCols' => $groupCols])
                    @endforeach
                </tbody>

                <tfoot>
                    <tr class="border-t-2 border-[color:var(--am-border-strong)] bg-[var(--am-bg)] font-semibold">
                        <td class="w-8 pl-4 pr-1 py-3"></td>
                        <td class="px-2 py-3 text-[var(--am-text)]">Summe</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right tabular-nums text-[var(--am-text-secondary)]">{{ $sumCount }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-[var(--am-text)]">{{ number_format($sumMonthly, 2, ',', '.') }} €</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3"></td>
                        <td class="pr-4 pl-2 py-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if($groupsOverflow)
            <div class="px-4 py-2.5 text-xs text-amber-700 bg-amber-50 border-t border-amber-200">
                Es gibt mehr Gruppen als angezeigt werden — die Liste ist auf die größten begrenzt.
                Filter setzen oder eine andere Achse wählen.
            </div>
        @endif
    @endif
</x-asset-manager-panel>
