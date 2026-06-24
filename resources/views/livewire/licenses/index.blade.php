<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lizenzen', 'icon' => 'key'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
                @if($canSync && $config && $config->isConfigured())
                    <x-ui-button variant="primary" size="md" rounded="lg" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">
                        <span wire:loading.remove wire:target="syncNow" class="inline-flex items-center gap-1.5">
                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                            Jetzt synchronisieren
                        </span>
                        <span wire:loading wire:target="syncNow" class="inline-flex items-center gap-1.5">
                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')
                            Startet...
                        </span>
                    </x-ui-button>
                @endif
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
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="SKU suchen..."
                            class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Auslastung</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterUsage" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="unused">Ungenutzt (freie Plätze)</option>
                            <option value="full">Voll / überbucht (≥100%)</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Preis</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterPrice" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="priced">Mit Preis</option>
                            <option value="unpriced">Ohne Preis</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Anzeige</h3>
                    <div class="px-3 pb-2 text-[11px]">
                        <div class="flex items-center justify-between py-1.5">
                            <span class="text-[color:var(--ui-secondary)]">Pro Seite</span>
                            <select wire:model.live="perPage" class="px-2 py-1 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="15">15</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                </section>

                @if($search || $filterUsage || $filterPrice)
                    <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" class="w-full"
                            wire:click="$set('search', ''); $set('filterUsage', ''); $set('filterPrice', '')">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-ui-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Nutzer der aufgeklappten Lizenz --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Lizenz-Nutzer" icon="heroicon-o-users" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                @if($selectedSku)
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)]">Auswahl</span>
                        <button wire:click="clearSku" class="text-[10px] text-[color:var(--ui-secondary)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="flex items-start gap-2">
                            @svg('heroicon-o-key', 'w-5 h-5 text-violet-500 flex-shrink-0')
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-[var(--ui-secondary)] truncate">{{ $selectedSku->display_name ?? $selectedSku->sku_part_number }}</div>
                                <div class="text-[11px] text-[color:var(--ui-secondary)] truncate">{{ $selectedSku->sku_part_number }}</div>
                            </div>
                        </div>
                        @php $pct = $selectedSku->utilizationPercent(); $c = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                        <div class="mt-2 flex items-center justify-between text-[11px]">
                            <span class="text-{{ $c }}-700 font-medium">{{ $pct }}% genutzt</span>
                            <span class="text-[color:var(--ui-secondary)] tabular-nums">{{ $selectedSku->consumed_units }}/{{ $selectedSku->purchased_units }}</span>
                        </div>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Nutzer ({{ $selectedSku->consumed_units }})</h3>
                        @if($assignments->isEmpty())
                            <div class="px-3 pb-3 text-[11px] text-[color:var(--ui-secondary)]">Keine Lizenzzuweisungen gefunden.</div>
                        @else
                            <ul class="divide-y divide-[var(--ui-border)]/30">
                                @foreach($assignments as $a)
                                    <li class="px-3 py-2">
                                        <div class="text-[11px] font-medium text-[var(--ui-secondary)] truncate">{{ $a->display_name ?? '—' }}</div>
                                        <div class="text-[10px] text-[color:var(--ui-secondary)] truncate">{{ $a->user_principal_name }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <x-ui-button variant="primary" size="md" rounded="lg" class="w-full" href="{{ route('asset-manager.licenses.show', $selectedSku) }}" wire:navigate>
                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5')
                        Vollständige Detailseite
                    </x-ui-button>
                @else
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[color:var(--ui-muted)] mb-3')
                        <p class="text-[11px] text-[color:var(--ui-secondary)]">Klicke bei einer Lizenz auf „Nutzer", um die Zuweisungen hier zu sehen.</p>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="space-y-5">

            {{-- Sync-Feedback --}}
            @if($syncResult)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-violet-500/10 border border-violet-500/20">
                    @svg('heroicon-o-arrow-path', 'w-4 h-4 text-violet-500 animate-spin flex-shrink-0')
                    <p class="text-sm text-violet-700 dark:text-violet-400">{{ $syncResult }}</p>
                </div>
            @endif

            {{-- Kein Connector --}}
            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center mb-4">
                        @svg('heroicon-o-wrench-screwdriver', 'w-6 h-6 text-amber-500')
                    </div>
                    <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Connector nicht konfiguriert</h3>
                    <p class="text-xs text-[color:var(--ui-secondary)] mb-4 max-w-xs">Für den Lizenz-Sync werden zusätzlich die Azure Permissions <strong>Organization.Read.All</strong> und <strong>User.Read.All</strong> benötigt.</p>
                    @if($canSync)
                        <x-ui-button variant="primary" size="md" rounded="lg" href="{{ route('asset-manager.setup') }}" wire:navigate>
                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                            Connector einrichten
                        </x-ui-button>
                    @endif
                </div>
            @else

            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)] mb-2">Kosten / Monat</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ $totalMonthlyCost > 0 ? number_format($totalMonthlyCost, 2, ',', '.') . ' €' : '—' }}
                    </div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-0.5">
                        {{ $totalMonthlyCost > 0
                            ? '≈ ' . number_format($totalMonthlyCost * 12, 2, ',', '.') . ' € / Jahr'
                            : 'Basierend auf gepflegten Preisen' }}
                    </div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-amber-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)] mb-2">Ungenutzte Lizenzen</div>
                    <div class="text-2xl font-semibold {{ $unusedLicenses > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ $unusedLicenses }}
                    </div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-0.5">SKUs mit freien Plätzen</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)] mb-2">Letzter Sync</div>
                    @if($lastLog)
                        <div class="text-sm font-medium {{ $lastLog->status === 'success' ? 'text-emerald-700' : ($lastLog->status === 'error' ? 'text-red-700' : 'text-amber-600') }}">
                            @if($lastLog->status === 'success') Erfolgreich
                            @elseif($lastLog->status === 'error') Fehler
                            @else Läuft...
                            @endif
                        </div>
                        <div class="text-xs text-[color:var(--ui-secondary)] mt-0.5">{{ $lastLog->started_at->diffForHumans() }}</div>
                        @if($lastLog->status === 'error' && $lastLog->error_message)
                            <div class="text-xs text-red-700 mt-1 truncate">{{ $lastLog->error_message }}</div>
                        @endif
                    @else
                        <div class="text-sm text-[color:var(--ui-secondary)]">Noch kein Sync</div>
                    @endif
                </div>
            </div>

            {{-- Hinweis: genutzte Lizenzen ohne hinterlegten Preis --}}
            @if($unpricedCount > 0)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/20">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-amber-500 flex-shrink-0')
                    <p class="text-sm text-amber-700 dark:text-amber-400 flex-1">
                        <strong>{{ $unpricedCount }}</strong> genutzte {{ $unpricedCount === 1 ? 'Lizenz hat' : 'Lizenzen haben' }} keinen hinterlegten Preis — sie fehlen in der Kostenrechnung.
                    </p>
                    @if($filterPrice !== 'unpriced')
                        <button wire:click="$set('filterPrice', 'unpriced')" class="text-xs font-medium text-amber-700 dark:text-amber-400 hover:underline flex-shrink-0 whitespace-nowrap">Anzeigen</button>
                    @endif
                </div>
            @endif

            {{-- Tabelle --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>

                @if($skus->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-key', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
                        <p class="text-sm text-[color:var(--ui-secondary)]">
                            @if($search || $filterUsage || $filterPrice)
                                Keine Lizenzen für diese Suche/Filter.
                            @else
                                Noch keine Lizenzen synchronisiert.
                                @if($canSync)
                                    <br><button wire:click="syncNow" class="mt-2 text-violet-500 hover:underline">Jetzt synchronisieren</button>
                                @endif
                            @endif
                        </p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--ui-muted)]">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">
                                    <button wire:click="sortBy('display_name')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Lizenz
                                        @if($sortField === 'display_name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">
                                    <button wire:click="sortBy('consumed_units')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Auslastung
                                        @if($sortField === 'consumed_units') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">Preis/Monat</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">
                                    <button wire:click="sortBy('monthly_cost')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Kosten/Monat
                                        @if($sortField === 'monthly_cost') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">Kosten/Jahr</th>
                                <th class="px-5 py-3 bg-[color:var(--ui-muted-10)]"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--ui-muted)]">
                            @foreach($skus as $sku)
                                @php
                                    $pct   = $sku->utilizationPercent();
                                    $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald');
                                @endphp
                                <tr wire:key="sku-{{ $sku->id }}" class="transition-colors {{ $selectedSkuId === $sku->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : 'hover:bg-[color:var(--ui-muted-10)]' }}">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ $sku->display_name ?? $sku->sku_part_number }}
                                        </div>
                                        <div class="text-xs text-[color:var(--ui-secondary)]">{{ $sku->sku_part_number }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 min-w-[80px]">
                                                <div class="flex justify-between text-xs mb-1">
                                                    <span class="text-{{ $color }}-700 font-medium">{{ $pct }}%</span>
                                                    <span class="text-[color:var(--ui-secondary)]">{{ $sku->consumed_units }}/{{ $sku->purchased_units }}</span>
                                                </div>
                                                <div class="h-1.5 rounded-full bg-gray-200 dark:bg-white/10 overflow-hidden">
                                                    <div class="h-full rounded-full bg-{{ $color }}-500 transition-all" style="width: {{ min(100, $pct) }}%"></div>
                                                </div>
                                            </div>
                                        </div>
                                        @if($sku->available_units > 0)
                                            <div class="text-xs text-amber-600 mt-0.5">{{ $sku->available_units }} frei</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($canSync)
                                            <div class="flex items-center gap-1">
                                                <span class="text-xs text-[color:var(--ui-secondary)]">€</span>
                                                <input
                                                    type="text"
                                                    value="{{ $sku->unit_price !== null ? number_format((float)$sku->unit_price, 2, ',', '') : '' }}"
                                                    wire:blur="updatePrice({{ $sku->id }}, $event.target.value)"
                                                    placeholder="—"
                                                    class="w-20 px-2 py-1 text-sm rounded bg-black/[0.03] dark:bg-white/[0.05] border border-black/10 dark:border-white/10 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-1 focus:ring-violet-500/40"
                                                />
                                            </div>
                                        @else
                                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                                {{ $sku->unit_price !== null ? '€ ' . number_format((float)$sku->unit_price, 2, ',', '.') : '—' }}
                                            </span>
                                        @endif
                                        @if($sku->unit_price === null && $sku->consumed_units > 0)
                                            <div class="text-[10px] text-amber-600 mt-1 whitespace-nowrap">
                                                @svg('heroicon-o-exclamation-triangle', 'w-3 h-3 inline -mt-0.5')
                                                Preis fehlt
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ $sku->monthlyCost() > 0 ? '€ ' . number_format($sku->monthlyCost(), 2, ',', '.') : '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="text-sm text-[color:var(--ui-secondary)]">
                                            {{ $sku->monthlyCost() > 0 ? '€ ' . number_format($sku->annualCost(), 2, ',', '.') : '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button type="button"
                                                wire:click="selectSku({{ $sku->id }})"
                                                @click="$store.ui?.mSet('activity', 'open', true)"
                                                class="inline-flex items-center gap-1 text-xs text-[color:var(--ui-primary)] hover:underline">
                                            Nutzer
                                            @svg('heroicon-o-chevron-right', 'w-3 h-3')
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($skus->hasPages())
                        <div class="px-5 py-3 border-t border-[color:var(--ui-muted)]">
                            {{ $skus->links() }}
                        </div>
                    @endif
                @endif
            </div>

            @endif {{-- Ende: Connector konfiguriert --}}

            </div>
        </div>
    </div>
</x-ui-page>
