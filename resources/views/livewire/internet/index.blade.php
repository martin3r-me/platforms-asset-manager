<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Internet', 'icon' => 'wifi'],
        ]" />
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Anschluss / Standort / Anbieter…"
                               class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                @if($providers->isNotEmpty())
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Anbieter</h3>
                        <div class="px-3 pb-3">
                            <select wire:model.live="filterProvider" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="">Alle</option>
                                @foreach($providers as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                            </select>
                        </div>
                    </section>
                @endif

                @if($search || $filterProvider)
                    <button wire:click="resetFilters"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Filter zurücksetzen
                    </button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Detail (read-only) --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Anschluss" icon="heroicon-o-wifi" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                @if($selectedItem)
                    @php
                        $rd       = $selectedItem->raw_data ?? [];
                        $kst      = $selectedLines->map(fn($l) => $l->costCenter?->code)->filter()->unique()->implode(', ');
                        $detTotal = (float) $selectedLines->sum('monthly_amount');
                    @endphp
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Auswahl</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5') Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="text-sm font-semibold text-[var(--ui-secondary)]">{{ $selectedItem->name }}</div>
                        <div class="text-[11px] text-[var(--ui-muted)]">{{ $rd['anbieter'] ?? '—' }}</div>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Eigenschaften</h3>
                        <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                            @foreach([
                                ['Standort',     $rd['standort'] ?? null],
                                ['Anschrift',    $rd['anschrift'] ?? null],
                                ['Anbieter',     $rd['anbieter'] ?? null],
                                ['Kostenstelle', $kst ?: null],
                            ] as [$label, $value])
                                <div class="flex items-start justify-between gap-2 px-3 py-1.5">
                                    <dt class="text-[var(--ui-muted)]">{{ $label }}</dt>
                                    <dd class="text-right text-[var(--ui-secondary)]">{{ $value ?: '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kosten</h3>
                        <div class="px-3 pb-2 text-[11px]">
                            @forelse($selectedLines as $l)
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-[var(--ui-muted)] truncate pr-2">{{ $l->label }}</span>
                                    <span class="tabular-nums text-[var(--ui-secondary)]">{{ number_format((float)$l->monthly_amount, 2, ',', '.') }} €</span>
                                </div>
                            @empty
                                <div class="py-1 text-[var(--ui-muted)]">Keine Kostenpositionen.</div>
                            @endforelse
                            <div class="flex items-center justify-between py-1.5 mt-1 border-t border-[var(--ui-border)]/30 font-semibold">
                                <span class="text-[var(--ui-secondary)]">Summe</span>
                                <span class="tabular-nums text-violet-700">{{ number_format($detTotal, 2, ',', '.') }} € / Monat</span>
                            </div>
                        </div>
                    </section>

                    <a href="{{ route('asset-manager.assets.show', $selectedItem) }}" wire:navigate
                       class="block w-full text-center px-3 py-2 text-xs font-medium text-violet-600 bg-violet-500/5 border border-violet-500/20 rounded-lg hover:bg-violet-500/10">
                        Vollständige Detailseite
                    </a>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-gray-300 mb-3')
                        <p class="text-[11px] text-[var(--ui-muted)]">Einen Anschluss in der Liste anklicken.</p>
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
                @svg('heroicon-o-wifi', 'w-5 h-5 text-[var(--ui-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--ui-secondary)] m-0">Internet</h2>
                <span class="text-xs text-[var(--ui-secondary)] bg-[var(--ui-muted-10)] rounded-full px-2 py-0.5">{{ $items->count() }}</span>
                <span class="flex-1"></span>
                <span class="text-sm text-[var(--ui-secondary)]">Gesamt: <strong class="text-violet-700 tabular-nums">{{ number_format($totalMonthly, 2, ',', '.') }} € / Monat</strong></span>
            </div>

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="p-8 text-center text-sm text-gray-400">Keine Internet-Anschlüsse gefunden.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
                                <th class="text-left px-4 py-3">Anschluss</th>
                                <th class="text-left px-4 py-3">Standort</th>
                                <th class="text-left px-4 py-3">Anbieter</th>
                                <th class="text-right px-4 py-3">€/Monat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03]">
                            @foreach($items as $item)
                                <tr wire:key="in-{{ $item->id }}" wire:click="selectItem({{ $item->id }})"
                                    class="cursor-pointer hover:bg-black/[0.02] {{ $selectedId === $item->id ? 'bg-violet-500/10' : '' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $item->name }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $item->raw_data['standort'] ?? ($item->raw_data['anschrift'] ?? '—') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $item->raw_data['anbieter'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-violet-700">{{ number_format($costByItem[$item->id] ?? 0, 2, ',', '.') }} €</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
