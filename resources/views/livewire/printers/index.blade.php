<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Drucker', 'icon' => 'printer'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Modell / Seriennr.…"
                               class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                @if($niederlassungen->isNotEmpty())
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Niederlassung</h3>
                        <div class="px-3 pb-3">
                            <select wire:model.live="filterNiederlassung" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="">Alle</option>
                                @foreach($niederlassungen as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                            </select>
                        </div>
                    </section>
                @endif

                @if($search || $filterNiederlassung)
                    <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" class="w-full" wire:click="resetFilters">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-ui-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Detail (read-only) --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Drucker" icon="heroicon-o-printer" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                @if($selectedItem)
                    @php
                        $rd       = $selectedItem->raw_data ?? [];
                        $kst      = $selectedLines->map(fn($l) => $l->costCenter?->code)->filter()->unique()->implode(', ');
                        $detTotal = (float) $selectedLines->sum('monthly_amount');
                    @endphp
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)]">Auswahl</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[color:var(--ui-secondary)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5') Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="text-sm font-semibold text-[var(--ui-secondary)]">{{ $selectedItem->name }}</div>
                        <div class="text-[11px] text-[color:var(--ui-secondary)]">{{ $selectedItem->model ?? '—' }}</div>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Eigenschaften</h3>
                        <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                            @foreach([
                                ['Seriennr.',     $selectedItem->serial_number],
                                ['Niederlassung', $rd['niederlassung'] ?? null],
                                ['Standort',      $rd['server_standort'] ?? null],
                                ['Kostenstelle',  $kst ?: null],
                                ['GRENKE',        $rd['grenke_protect'] ?? null],
                            ] as [$label, $value])
                                <div class="flex items-start justify-between gap-2 px-3 py-1.5">
                                    <dt class="text-[color:var(--ui-secondary)]">{{ $label }}</dt>
                                    <dd class="text-right text-[var(--ui-secondary)]">{{ $value ?: '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Kosten</h3>
                        <div class="px-3 pb-2 text-[11px]">
                            @forelse($selectedLines as $l)
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-[color:var(--ui-secondary)] truncate pr-2">{{ $l->label }}</span>
                                    <span class="tabular-nums text-[var(--ui-secondary)]">{{ number_format((float)$l->monthly_amount, 2, ',', '.') }} €</span>
                                </div>
                            @empty
                                <div class="py-1 text-[color:var(--ui-secondary)]">Keine Kostenpositionen.</div>
                            @endforelse
                            <div class="flex items-center justify-between py-1.5 mt-1 border-t border-[var(--ui-border)]/30 font-semibold">
                                <span class="text-[var(--ui-secondary)]">Summe</span>
                                <span class="tabular-nums text-[color:var(--ui-primary)]">{{ number_format($detTotal, 2, ',', '.') }} € / Monat</span>
                            </div>
                        </div>
                    </section>

                    <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" class="w-full" href="{{ route('asset-manager.assets.show', $selectedItem) }}" wire:navigate>
                        Vollständige Detailseite
                    </x-ui-button>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[color:var(--ui-muted)] mb-3')
                        <p class="text-[11px] text-[color:var(--ui-secondary)]">Einen Drucker in der Liste anklicken.</p>
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
                @svg('heroicon-o-printer', 'w-5 h-5 text-[var(--ui-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--ui-secondary)] m-0">Drucker</h2>
                <span class="text-xs text-[var(--ui-secondary)] bg-[var(--ui-muted-10)] rounded-full px-2 py-0.5">{{ $items->count() }}</span>
                <span class="flex-1"></span>
                <span class="text-sm text-[var(--ui-secondary)]">Gesamt: <strong class="text-[color:var(--ui-primary)] tabular-nums">{{ number_format($totalMonthly, 2, ',', '.') }} € / Monat</strong></span>
            </div>

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="p-8 text-center text-sm text-[color:var(--ui-secondary)]">Keine Drucker gefunden.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--ui-muted)] text-xs uppercase tracking-wider text-[color:var(--ui-body-color)] font-semibold">
                                <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Drucker</th>
                                <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Niederlassung</th>
                                <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Seriennr.</th>
                                <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">KSt</th>
                                <th class="text-right px-4 py-3 bg-[color:var(--ui-muted-10)]">€/Monat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--ui-muted)]">
                            @foreach($items as $item)
                                <tr wire:key="pr-{{ $item->id }}" wire:click="selectItem({{ $item->id }})"
                                    class="cursor-pointer hover:bg-[color:var(--ui-muted-10)] {{ $selectedId === $item->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : '' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $item->name }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $item->raw_data['niederlassung'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)] font-mono">{{ $item->serial_number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $kstByItem[$item->id] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-[color:var(--ui-primary)]">{{ number_format($costByItem[$item->id] ?? 0, 2, ',', '.') }} €</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
