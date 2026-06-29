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
                    <x-asset-manager-button variant="primary" size="md" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">
                        <span wire:loading.remove wire:target="syncNow" class="inline-flex items-center gap-1.5">
                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                            Jetzt synchronisieren
                        </span>
                        <span wire:loading wire:target="syncNow" class="inline-flex items-center gap-1.5">
                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')
                            Startet...
                        </span>
                    </x-asset-manager-button>
                @endif
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search" placeholder="SKU suchen..." />
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Auslastung">
                    <x-asset-manager-select size="sm" wire:model.live="filterUsage">
                        <option value="">Alle</option>
                        <option value="unused">Ungenutzt (freie Plätze)</option>
                        <option value="full">Voll / überbucht (≥100%)</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Preis">
                    <x-asset-manager-select size="sm" wire:model.live="filterPrice">
                        <option value="">Alle</option>
                        <option value="priced">Mit Preis</option>
                        <option value="unpriced">Ohne Preis</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Anzeige">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--am-text-secondary)]">Pro Seite</span>
                        <select wire:model.live="perPage" class="px-2 py-1 text-[11px] rounded-md bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] text-[var(--am-text)]">
                            <option value="15">15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </x-asset-manager-filter-section>

                @if($search || $filterUsage || $filterPrice)
                    <x-asset-manager-button variant="ghost" size="sm" class="w-full"
                            wire:click="$set('search', ''); $set('filterUsage', ''); $set('filterPrice', '')">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-asset-manager-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Nutzer der aufgeklappten Lizenz --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Lizenz-Nutzer" icon="heroicon-o-users" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                @if($selectedSku)
                    <div class="flex items-center justify-between pb-2 border-b border-[color:var(--am-border)]">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Auswahl</span>
                        <button wire:click="clearSku" class="text-[10px] text-[var(--am-text-muted)] hover:text-red-600">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                        <div class="flex items-start gap-2">
                            @svg('heroicon-o-key', 'w-5 h-5 text-[var(--am-accent)] flex-shrink-0')
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-[var(--am-text)] truncate">{{ $selectedSku->display_name ?? $selectedSku->sku_part_number }}</div>
                                <div class="text-[11px] text-[var(--am-text-secondary)] truncate">{{ $selectedSku->sku_part_number }}</div>
                            </div>
                        </div>
                        @php $pct = $selectedSku->utilizationPercent(); $c = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                        <div class="mt-2 flex items-center justify-between text-[11px]">
                            <span class="text-{{ $c }}-700 font-medium">{{ $pct }}% genutzt</span>
                            <span class="text-[var(--am-text-secondary)] tabular-nums">{{ $selectedSku->consumed_units }}/{{ $selectedSku->purchased_units }}</span>
                        </div>
                    </section>

                    <section class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-3 pt-3 pb-1.5">Nutzer ({{ $selectedSku->consumed_units }})</h3>
                        @if($assignments->isEmpty())
                            <div class="px-3 pb-3 text-[11px] text-[var(--am-text-secondary)]">Keine Lizenzzuweisungen gefunden.</div>
                        @else
                            <ul class="divide-y divide-[color:var(--am-border)]">
                                @foreach($assignments as $a)
                                    <li class="px-3 py-2">
                                        <div class="text-[11px] font-medium text-[var(--am-text)] truncate">{{ $a->display_name ?? '—' }}</div>
                                        <div class="text-[10px] text-[var(--am-text-secondary)] truncate">{{ $a->user_principal_name }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <x-asset-manager-button variant="primary" size="md" class="w-full" href="{{ route('asset-manager.licenses.show', $selectedSku) }}" wire:navigate>
                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5')
                        Vollständige Detailseite
                    </x-asset-manager-button>
                @else
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[var(--am-text-muted)] mb-3')
                        <p class="text-[11px] text-[var(--am-text-secondary)]">Klicke bei einer Lizenz auf „Nutzer", um die Zuweisungen hier zu sehen.</p>
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
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-sky-50 border border-sky-200">
                    @svg('heroicon-o-arrow-path', 'w-4 h-4 text-sky-600 animate-spin flex-shrink-0')
                    <p class="text-sm text-sky-700">{{ $syncResult }}</p>
                </div>
            @endif

            {{-- Kein Connector --}}
            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center mb-4">
                        @svg('heroicon-o-wrench-screwdriver', 'w-6 h-6 text-amber-600')
                    </div>
                    <h3 class="text-sm font-medium text-[var(--am-text)] mb-1">Connector nicht konfiguriert</h3>
                    <p class="text-xs text-[var(--am-text-secondary)] mb-4 max-w-xs">Für den Lizenz-Sync werden zusätzlich die Azure Permissions <strong>Organization.Read.All</strong> und <strong>User.Read.All</strong> benötigt.</p>
                    @if($canSync)
                        <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.setup') }}" wire:navigate>
                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                            Connector einrichten
                        </x-asset-manager-button>
                    @endif
                </div>
            @else

            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <x-asset-manager-stat-card
                    label="Kosten / Monat"
                    accent="navy"
                    icon="heroicon-o-banknotes"
                    :value="$totalMonthlyCost > 0 ? number_format($totalMonthlyCost, 2, ',', '.') . ' €' : '—'"
                    :sub="$totalMonthlyCost > 0
                        ? '≈ ' . number_format($totalMonthlyCost * 12, 2, ',', '.') . ' € / Jahr'
                        : 'Basierend auf gepflegten Preisen'" />

                <x-asset-manager-stat-card
                    label="Ungenutzte Lizenzen"
                    accent="amber"
                    icon="heroicon-o-inbox-stack"
                    :value="$unusedLicenses"
                    :value-class="$unusedLicenses > 0 ? 'text-amber-600' : ''"
                    sub="SKUs mit freien Plätzen" />

                <x-asset-manager-stat-card label="Letzter Sync" accent="sky" icon="heroicon-o-arrow-path">
                    @if($lastLog)
                        <div class="text-sm font-medium {{ $lastLog->status === 'success' ? 'text-emerald-700' : ($lastLog->status === 'error' ? 'text-red-700' : 'text-amber-600') }}">
                            @if($lastLog->status === 'success') Erfolgreich
                            @elseif($lastLog->status === 'error') Fehler
                            @else Läuft...
                            @endif
                        </div>
                        <div class="text-xs text-[var(--am-text-muted)] mt-0.5 font-normal">{{ $lastLog->started_at->diffForHumans() }}</div>
                        @if($lastLog->status === 'error' && $lastLog->error_message)
                            <div class="text-xs text-red-700 mt-1 truncate font-normal">{{ $lastLog->error_message }}</div>
                        @endif
                    @else
                        <div class="text-sm text-[var(--am-text-muted)] font-normal">Noch kein Sync</div>
                    @endif
                </x-asset-manager-stat-card>
            </div>

            {{-- Hinweis: genutzte Lizenzen ohne hinterlegten Preis --}}
            @if($unpricedCount > 0)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-amber-600 flex-shrink-0')
                    <p class="text-sm text-amber-700 flex-1">
                        <strong>{{ $unpricedCount }}</strong> genutzte {{ $unpricedCount === 1 ? 'Lizenz hat' : 'Lizenzen haben' }} keinen hinterlegten Preis — sie fehlen in der Kostenrechnung.
                    </p>
                    @if($filterPrice !== 'unpriced')
                        <button wire:click="$set('filterPrice', 'unpriced')" class="text-xs font-medium text-amber-700 hover:underline flex-shrink-0 whitespace-nowrap">Anzeigen</button>
                    @endif
                </div>
            @endif

            {{-- Tabelle --}}
            <x-asset-manager-panel body-class="p-0">
                @if($skus->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-key', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                        <p class="text-sm text-[var(--am-text-secondary)]">
                            @if($search || $filterUsage || $filterPrice)
                                Keine Lizenzen für diese Suche/Filter.
                            @else
                                Noch keine Lizenzen synchronisiert.
                                @if($canSync)
                                    <br><button wire:click="syncNow" class="mt-2 text-[var(--am-accent)] hover:underline">Jetzt synchronisieren</button>
                                @endif
                            @endif
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-[var(--am-bg)] border-b border-[color:var(--am-border)]">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                    <button wire:click="sortBy('display_name')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)] transition-colors">
                                        Lizenz
                                        @if($sortField === 'display_name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                    <button wire:click="sortBy('consumed_units')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)] transition-colors">
                                        Auslastung
                                        @if($sortField === 'consumed_units') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Preis/Monat</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                    <button wire:click="sortBy('monthly_cost')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)] transition-colors">
                                        Kosten/Monat
                                        @if($sortField === 'monthly_cost') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Kosten/Jahr</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($skus as $sku)
                                @php
                                    $pct   = $sku->utilizationPercent();
                                    $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald');
                                @endphp
                                <tr wire:key="sku-{{ $sku->id }}" class="transition-colors {{ $selectedSkuId === $sku->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : 'hover:bg-[var(--am-bg)]' }}">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-[var(--am-text)]">
                                            {{ $sku->display_name ?? $sku->sku_part_number }}
                                        </div>
                                        <div class="text-xs text-[var(--am-text-secondary)]">{{ $sku->sku_part_number }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 min-w-[80px]">
                                                <div class="flex justify-between text-xs mb-1">
                                                    <span class="text-{{ $color }}-700 font-medium">{{ $pct }}%</span>
                                                    <span class="text-[var(--am-text-secondary)]">{{ $sku->consumed_units }}/{{ $sku->purchased_units }}</span>
                                                </div>
                                                <div class="h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
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
                                                <span class="text-xs text-[var(--am-text-secondary)]">€</span>
                                                <input
                                                    type="text"
                                                    value="{{ $sku->unit_price !== null ? number_format((float)$sku->unit_price, 2, ',', '') : '' }}"
                                                    wire:blur="updatePrice({{ $sku->id }}, $event.target.value)"
                                                    placeholder="—"
                                                    class="w-20 px-2 py-1 text-sm rounded-md bg-[var(--am-bg)] border border-[color:var(--am-border-strong)] text-[var(--am-text)] placeholder:text-[var(--am-text-muted)] focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)]"
                                                />
                                            </div>
                                        @else
                                            <span class="text-sm text-[var(--am-text-secondary)]">
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
                                        <span class="text-sm font-medium text-[var(--am-text)]">
                                            {{ $sku->monthlyCost() > 0 ? '€ ' . number_format($sku->monthlyCost(), 2, ',', '.') : '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="text-sm text-[var(--am-text-secondary)]">
                                            {{ $sku->monthlyCost() > 0 ? '€ ' . number_format($sku->annualCost(), 2, ',', '.') : '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button type="button"
                                                wire:click="selectSku({{ $sku->id }})"
                                                @click="$store.ui?.mSet('activity', 'open', true)"
                                                class="inline-flex items-center gap-1 text-xs text-[var(--am-accent)] hover:underline">
                                            Nutzer
                                            @svg('heroicon-o-chevron-right', 'w-3 h-3')
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>

                    @if($skus->hasPages())
                        <div class="px-5 py-3 border-t border-[color:var(--am-border)]">
                            {{ $skus->links() }}
                        </div>
                    @endif
                @endif
            </x-asset-manager-panel>

            @endif {{-- Ende: Connector konfiguriert --}}

            </div>
        </div>
    </div>
</x-ui-page>
