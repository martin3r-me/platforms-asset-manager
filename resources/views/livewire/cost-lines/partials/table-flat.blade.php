{{--
    Flache Kostenpositions-Tabelle. Sortierköpfe kommen aus einer Spaltendefinition statt aus
    sieben handkopierten <th>-Blöcken; die Sort-Icons sind @svg-Heroicons statt eines
    zusammengesetzten SVG-String-HTML wie vor dem Redesign.
--}}
@php
    $sortCols = [
        'label'          => ['Bezeichnung',  'left'],
        'cost_type'      => ['Kostenart',    'left'],
        'cost_center'    => ['KSt',          'left'],
        'assignee'       => ['Träger',       'left'],
        'vendor'         => ['Kreditor',     'left'],
        'amount'         => ['Betrag',       'right'],
        'frequency'      => ['Frequenz',     'left'],
        'monthly_amount' => ['€/Monat',      'right'],
        'valid_from'     => ['Gültigkeit',   'left'],
    ];
@endphp

<x-asset-manager-panel body-class="p-0">
    @if($lines->isEmpty())
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
                    <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                        @can('asset-manager.manage')
                            <th class="w-10 pl-4 pr-1 py-3">
                                {{-- Fuellt/leert $selected ueber updatedSelectPage() --}}
                                <input type="checkbox" wire:model.live="selectPage"
                                       class="rounded border-[color:var(--am-border-strong)] text-[var(--am-accent)]" />
                            </th>
                        @endcan
                        @foreach($sortCols as $field => [$heading, $align])
                            <th class="{{ $align === 'right' ? 'text-right' : 'text-left' }} px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button type="button" wire:click="sortBy('{{ $field }}')"
                                        class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text)]">
                                    {{ $heading }}
                                    @if($sortField === $field)
                                        @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3')
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-3 bg-[var(--am-bg)]"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[color:var(--am-border)]">
                    @foreach($lines as $line)
                        @include('asset-manager::livewire.cost-lines.partials.row-line', ['line' => $line])
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($lines->hasPages())
            <div class="px-4 py-3 border-t border-[color:var(--am-border)]">
                {{ $lines->links() }}
            </div>
        @endif
    @endif
</x-asset-manager-panel>
