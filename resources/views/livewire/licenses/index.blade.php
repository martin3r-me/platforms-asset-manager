<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lizenzen', 'icon' => 'key'],
        ]">
            <x-slot name="actions">
                @if($canSync && $config && $config->isConfigured())
                    <button wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm disabled:opacity-60">
                        <span wire:loading.remove wire:target="syncNow">
                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                            Jetzt synchronisieren
                        </span>
                        <span wire:loading wire:target="syncNow" class="flex items-center gap-1.5">
                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')
                            Startet...
                        </span>
                    </button>
                @endif
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
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
                    <p class="text-xs text-gray-400 mb-4 max-w-xs">Für den Lizenz-Sync werden zusätzlich die Azure Permissions <strong>Organization.Read.All</strong> und <strong>User.Read.All</strong> benötigt.</p>
                    @if($canSync)
                        <a href="{{ route('asset-manager.setup') }}" wire:navigate
                           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg transition-all shadow-sm">
                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                            Connector einrichten
                        </a>
                    @endif
                </div>
            @else

            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Kosten / Monat</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ $totalMonthlyCost > 0 ? number_format($totalMonthlyCost, 2, ',', '.') . ' €' : '—' }}
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">Basierend auf gepflegten Preisen</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-amber-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Ungenutzte Lizenzen</div>
                    <div class="text-2xl font-semibold {{ $unusedLicenses > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ $unusedLicenses }}
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">SKUs mit freien Plätzen</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Letzter Sync</div>
                    @if($lastLog)
                        <div class="text-sm font-medium {{ $lastLog->status === 'success' ? 'text-emerald-600 dark:text-emerald-400' : ($lastLog->status === 'error' ? 'text-red-500' : 'text-amber-500') }}">
                            @if($lastLog->status === 'success') Erfolgreich
                            @elseif($lastLog->status === 'error') Fehler
                            @else Läuft...
                            @endif
                        </div>
                        <div class="text-xs text-gray-400 mt-0.5">{{ $lastLog->started_at->diffForHumans() }}</div>
                        @if($lastLog->status === 'error' && $lastLog->error_message)
                            <div class="text-xs text-red-500 mt-1 truncate">{{ $lastLog->error_message }}</div>
                        @endif
                    @else
                        <div class="text-sm text-gray-400">Noch kein Sync</div>
                    @endif
                </div>
            </div>

            {{-- Suche --}}
            <div class="relative max-w-sm">
                <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                    @svg('heroicon-o-magnifying-glass', 'w-4 h-4 text-gray-400')
                </div>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="SKU suchen..."
                    class="w-full pl-9 pr-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all"
                />
            </div>

            {{-- Tabelle --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>

                @if($skus->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-key', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">
                            @if($search)
                                Keine Lizenzen gefunden.
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
                            <tr class="border-b border-black/5 dark:border-white/5">
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('display_name')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Lizenz
                                        @if($sortField === 'display_name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('consumed_units')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Auslastung
                                        @if($sortField === 'consumed_units') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Preis/Monat</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('available_units')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Gesamtkosten
                                        @if($sortField === 'available_units') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($skus as $sku)
                                @php
                                    $pct   = $sku->utilizationPercent();
                                    $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald');
                                @endphp
                                <tr class="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ $sku->display_name ?? $sku->sku_part_number }}
                                        </div>
                                        <div class="text-xs text-gray-400">{{ $sku->sku_part_number }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 min-w-[80px]">
                                                <div class="flex justify-between text-xs mb-1">
                                                    <span class="text-{{ $color }}-600 dark:text-{{ $color }}-400 font-medium">{{ $pct }}%</span>
                                                    <span class="text-gray-400">{{ $sku->consumed_units }}/{{ $sku->purchased_units }}</span>
                                                </div>
                                                <div class="h-1.5 rounded-full bg-gray-200 dark:bg-white/10 overflow-hidden">
                                                    <div class="h-full rounded-full bg-{{ $color }}-500 transition-all" style="width: {{ min(100, $pct) }}%"></div>
                                                </div>
                                            </div>
                                        </div>
                                        @if($sku->available_units > 0)
                                            <div class="text-xs text-amber-500 mt-0.5">{{ $sku->available_units }} frei</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($canSync)
                                            <div class="flex items-center gap-1">
                                                <span class="text-xs text-gray-400">€</span>
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
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ $sku->monthlyCost() > 0 ? '€ ' . number_format($sku->monthlyCost(), 2, ',', '.') : '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <a href="{{ route('asset-manager.licenses.show', $sku) }}" wire:navigate
                                           class="inline-flex items-center gap-1 text-xs text-violet-600 dark:text-violet-400 hover:underline">
                                            Nutzer
                                            @svg('heroicon-o-chevron-right', 'w-3 h-3')
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($skus->hasPages())
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                            {{ $skus->links() }}
                        </div>
                    @endif
                @endif
            </div>

            @endif {{-- Ende: Connector konfiguriert --}}

        </div>
    </x-ui-page-container>
</x-ui-page>
