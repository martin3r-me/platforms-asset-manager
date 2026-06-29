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
                @include('asset-manager::livewire.partials.tenant-selector')
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

    {{-- RECHTS: Detail (read-only) --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Anschluss" icon="heroicon-o-wifi" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                @if($selectedItem)
                    @php
                        $rd       = $selectedItem->raw_data ?? [];
                        $kst      = $selectedLines->map(fn($l) => $l->costCenter?->code)->filter()->unique()->implode(', ');
                        $detTotal = (float) $selectedLines->sum('monthly_amount');
                    @endphp
                    <div class="flex items-center justify-between pb-2 border-b border-[color:var(--am-border)]">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Auswahl</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--am-text-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5') Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                        <div class="text-sm font-semibold text-[var(--am-text)]">{{ $selectedItem->name }}</div>
                        <div class="text-[11px] text-[var(--am-text-secondary)]">{{ $rd['anbieter'] ?? '—' }}</div>
                    </section>

                    <x-asset-manager-panel title="Eigenschaften" body-class="px-4 py-1">
                        <x-asset-manager-detail-list>
                            @foreach([
                                ['Standort',     $rd['standort'] ?? null],
                                ['Anschrift',    $rd['anschrift'] ?? null],
                                ['Anbieter',     $rd['anbieter'] ?? null],
                                ['Kostenstelle', $kst ?: null],
                            ] as [$label, $value])
                                <x-asset-manager-detail-row :label="$label">{{ $value ?: '—' }}</x-asset-manager-detail-row>
                            @endforeach
                        </x-asset-manager-detail-list>
                    </x-asset-manager-panel>

                    <x-asset-manager-panel title="Kosten" body-class="p-0">
                        <div class="px-3 py-2 text-[11px]">
                            @forelse($selectedLines as $l)
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-[var(--am-text-secondary)] truncate pr-2">{{ $l->label }}</span>
                                    <span class="tabular-nums text-[var(--am-text)]">{{ number_format((float)$l->monthly_amount, 2, ',', '.') }} €</span>
                                </div>
                            @empty
                                <div class="py-1 text-[var(--am-text-muted)]">Keine Kostenpositionen.</div>
                            @endforelse
                            <div class="flex items-center justify-between py-1.5 mt-1 border-t border-[color:var(--am-border)] font-semibold">
                                <span class="text-[var(--am-text)]">Summe</span>
                                <span class="tabular-nums text-[var(--am-accent)]">{{ number_format($detTotal, 2, ',', '.') }} € / Monat</span>
                            </div>
                        </div>
                    </x-asset-manager-panel>

                    <x-asset-manager-button variant="secondary" size="sm" class="w-full" href="{{ route('asset-manager.assets.show', $selectedItem) }}" wire:navigate>
                        Vollständige Detailseite
                    </x-asset-manager-button>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[var(--am-text-muted)] mb-3')
                        <p class="text-[11px] text-[var(--am-text-secondary)]">Einen Anschluss in der Liste anklicken.</p>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Öffnet das rechte Panel bei Zeilenklick. --}}
    <div x-data x-on:open-activity.window="$store.ui && $store.ui.mSet('activity', 'open', true)"></div>

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
                                    <th class="text-right px-4 py-3">€/Monat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($items as $item)
                                    <tr wire:key="in-{{ $item->id }}" wire:click="selectItem({{ $item->id }})"
                                        class="cursor-pointer hover:bg-[var(--am-bg)] {{ $selectedId === $item->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : '' }}">
                                        <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">{{ $item->name }}</td>
                                        <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $item->raw_data['standort'] ?? ($item->raw_data['anschrift'] ?? '—') }}</td>
                                        <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $item->raw_data['anbieter'] ?? '—' }}</td>
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
