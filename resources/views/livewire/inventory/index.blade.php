<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Inventar', 'icon' => 'rectangle-group'],
        ]">
            <x-slot name="actions">
                <div class="flex items-center gap-1.5 text-xs text-gray-500">
                    <span>Pro Seite</span>
                    <select wire:model.live="perPage" class="px-2 py-1 text-xs rounded-md bg-black/[0.04] dark:bg-white/[0.06] border border-black/10 dark:border-white/10">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Name, Hersteller, Seriennr., Nutzer..."
                            class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Typ</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterType" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            <option value="manual">Manuelle Assets</option>
                            <option value="intune">Intune-Geräte</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Zuweisung</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterAssignment" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            <option value="assigned">Zugewiesen</option>
                            <option value="unassigned">Nicht zugewiesen</option>
                        </select>
                    </div>
                </section>

                @if($search || $filterType || $filterAssignment)
                    <button wire:click="resetFilters"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10 transition-colors">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Filter zurücksetzen
                    </button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Quellen --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Quellen" icon="heroicon-o-square-2-stack" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                <p class="text-xs text-gray-500">
                    Diese Sicht führt manuelle Assets und Intune-Geräte zusammen (nur Anzeige). Bearbeitet wird in der jeweiligen Quelle:
                </p>
                <a href="{{ route('asset-manager.assets.index') }}" wire:navigate
                   class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 bg-white border border-[var(--ui-border)]/40 rounded-lg hover:bg-[var(--ui-muted-5)] transition-all">
                    @svg('heroicon-o-cube-transparent', 'w-4 h-4')
                    Nur manuelle Assets
                </a>
                <a href="{{ route('asset-manager.devices.index') }}" wire:navigate
                   class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 bg-white border border-[var(--ui-border)]/40 rounded-lg hover:bg-[var(--ui-muted-5)] transition-all">
                    @svg('heroicon-o-computer-desktop', 'w-4 h-4')
                    Nur Intune-Geräte
                </a>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $counts['total'] }}</div>
                    <div class="text-xs text-gray-400">Hardware gesamt</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-600 dark:text-gray-300">{{ $counts['manual'] }}</div>
                    <div class="text-xs text-gray-400">Manuelle Assets</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-violet-600 dark:text-violet-400">{{ $counts['intune'] }}</div>
                    <div class="text-xs text-gray-400">Intune-Geräte</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $counts['assigned'] }}</div>
                    <div class="text-xs text-gray-400">Zugewiesen</div>
                </div>
            </div>

            {{-- Tabelle --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-rectangle-group', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">
                            @if($search || $filterType || $filterAssignment)
                                Keine Hardware für diese Filter.
                            @else
                                Noch keine Hardware erfasst — weder manuelle Assets noch Intune-Geräte.
                            @endif
                        </p>
                    </div>
                @else
                    <div class="px-5 pt-3 text-[11px] text-gray-400">{{ $totalFiltered }} Einträge</div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 dark:border-white/5">
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-gray-600">
                                        Name
                                        @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('type')" class="flex items-center gap-1 hover:text-gray-600">
                                        Typ
                                        @if($sortField === 'type') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Seriennr.</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('assignedTo')" class="flex items-center gap-1 hover:text-gray-600">
                                        Zugewiesen an
                                        @if($sortField === 'assignedTo') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('status')" class="flex items-center gap-1 hover:text-gray-600">
                                        Status
                                        @if($sortField === 'status') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-right px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('monthlyCost')" class="flex items-center gap-1 hover:text-gray-600 ml-auto"
                                            title="AfA/Leasing je Objekt — keine Kostenstellen-Zuteilung">
                                        Monatskosten
                                        @if($sortField === 'monthlyCost') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($items as $row)
                                <tr wire:key="inv-{{ $row->type }}-{{ $row->id }}" class="transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.02]">
                                    <td class="px-5 py-3">
                                        <a href="{{ $row->detailRoute }}" wire:navigate
                                           class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400">
                                            {{ $row->name }}
                                        </a>
                                        @if($row->manufacturer || $row->model)
                                            <div class="text-xs text-gray-400">{{ trim($row->manufacturer . ' ' . $row->model) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($row->type === 'intune')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-500/10 text-violet-600 dark:text-violet-400">
                                                @svg('heroicon-o-cloud', 'w-3 h-3')
                                                Intune
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-gray-500/10 text-gray-600 dark:text-gray-400">
                                                @svg('heroicon-o-wrench-screwdriver', 'w-3 h-3')
                                                Manuell
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-500 dark:text-gray-400 text-xs">
                                        {{ $row->serialNumber ?: '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-gray-700 dark:text-gray-300">
                                        {{ $row->assignedTo ?: '—' }}
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($row->statusLabel === '—')
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @else
                                            @php $c = $row->statusColor; @endphp
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-{{ $c }}-500/10 text-{{ $c }}-600 dark:text-{{ $c }}-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-{{ $c }}-500"></span>
                                                {{ $row->statusLabel }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if($row->monthlyCost > 0)
                                            <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row->monthlyCost, 2, ',', '.') }} €</span>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($items->hasPages())
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                            {{ $items->links() }}
                        </div>
                    @endif
                @endif
            </div>

            <p class="text-[11px] text-gray-400 px-1">
                Monatskosten = AfA/Leasing je Objekt (rohe Gerätekosten) — nicht die kostenstellen-zugeteilte Summe aus der Kostenaufteilung.
            </p>
        </div>
    </div>
</x-ui-page>
