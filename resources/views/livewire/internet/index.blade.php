<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Internet', 'icon' => 'wifi'],
        ]">
            <x-slot name="actions">
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search"
                        placeholder="Anschluss / Standort / Anbieter…" class="w-full" />
                </x-asset-manager-filter-section>

                @if($providers->isNotEmpty())
                    <x-asset-manager-filter-section title="Anbieter">
                        <x-asset-manager-select size="sm" wire:model.live="filterProvider" class="w-full">
                            <option value="">Alle</option>
                            @foreach($providers as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                        </x-asset-manager-select>
                    </x-asset-manager-filter-section>
                @endif

                @if($search || $filterProvider)
                    <x-asset-manager-button variant="ghost" size="sm" class="w-full" wire:click="resetFilters">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-asset-manager-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            <div class="flex items-center gap-2">
                @svg('heroicon-o-wifi', 'w-5 h-5 text-[var(--am-text-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--am-text)] m-0">Internet</h2>
                <span class="text-xs text-[var(--am-text-secondary)] bg-[var(--am-bg)] rounded-full px-2 py-0.5">{{ $items->count() }}</span>
                <span class="flex-1"></span>
                <span class="text-sm text-[var(--am-text-secondary)]">Gesamt: <strong class="text-[var(--am-accent)] tabular-nums">{{ number_format($totalMonthly, 2, ',', '.') }} € / Monat</strong></span>
            </div>

            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">Keine Internet-Anschlüsse gefunden.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-[var(--am-bg)] text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                    <th class="text-left px-4 py-3">Anschluss</th>
                                    <th class="text-left px-4 py-3">Standort</th>
                                    <th class="text-left px-4 py-3">Anbieter</th>
                                    <th class="text-left px-4 py-3">KSt</th>
                                    <th class="text-right px-4 py-3">€/Monat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($items as $item)
                                    {{-- Zeilenklick öffnete früher ein rechtes Vorschau-Panel; seit S8b führt der
                                         Name direkt auf die vereinte Detailseite (ADR 0012). Die Kostenstelle, die
                                         nur im Panel stand, ist jetzt eine Spalte. --}}
                                    <tr wire:key="in-{{ $item->id }}" class="transition-colors hover:bg-[var(--am-bg)]">
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('asset-manager.inventory.show', ['type' => 'manual', 'id' => $item->id]) }}" wire:navigate
                                               class="font-medium text-[var(--am-text)] hover:text-[var(--am-accent)]">
                                                {{ $item->name }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $item->raw_data['standort'] ?? ($item->raw_data['anschrift'] ?? '—') }}</td>
                                        <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $item->raw_data['anbieter'] ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $kstByItem[$item->id] ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-[var(--am-accent)]">{{ number_format($costByItem[$item->id] ?? 0, 2, ',', '.') }} €</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
