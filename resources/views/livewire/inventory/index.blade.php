<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Inventar', 'icon' => 'rectangle-group'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg"
                             href="{{ route('asset-manager.assets.index') }}" wire:navigate>
                    @svg('heroicon-o-cube-transparent', 'w-3.5 h-3.5')
                    Nur manuelle Assets
                </x-ui-button>
                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg"
                             href="{{ route('asset-manager.devices.index') }}" wire:navigate>
                    @svg('heroicon-o-computer-desktop', 'w-3.5 h-3.5')
                    Nur Intune-Geräte
                </x-ui-button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- Voll-Breite-Übersicht: keine in-page-Sidebars (wie compliance/reports), Filter inline. --}}
    <x-ui-page-container padding="p-6" spacing="space-y-5">

        <p class="text-[11px] text-[color:var(--ui-secondary)]">
            Nur-Anzeige: manuelle Assets + Intune-Geräte zusammengeführt — bearbeitet wird in der jeweiligen Quelle (oben rechts).
        </p>

        {{-- Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $counts['total'] }}</div>
                <div class="text-xs text-[color:var(--ui-secondary)]">Hardware gesamt</div>
            </div>
            <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                <div class="text-2xl font-semibold text-gray-600 dark:text-gray-300">{{ $counts['manual'] }}</div>
                <div class="text-xs text-[color:var(--ui-secondary)]">Manuelle Assets</div>
            </div>
            <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                <div class="text-2xl font-semibold text-[color:var(--ui-primary)]">{{ $counts['intune'] }}</div>
                <div class="text-xs text-[color:var(--ui-secondary)]">Intune-Geräte</div>
            </div>
            <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-400">{{ $counts['assigned'] }}</div>
                <div class="text-xs text-[color:var(--ui-secondary)]">Zugewiesen</div>
            </div>
        </div>

        {{-- Filter-Toolbar --}}
        <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-3 flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[12rem]">
                @svg('heroicon-o-magnifying-glass', 'w-4 h-4 text-[color:var(--ui-secondary)] absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none')
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="Name, Hersteller, Seriennr., Nutzer..."
                       class="w-full pl-8 pr-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
            </div>
            <select wire:model.live="filterType" class="px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                <option value="">Alle Typen</option>
                <option value="manual">Manuelle Assets</option>
                <option value="intune">Intune-Geräte</option>
            </select>
            <select wire:model.live="filterAssignment" class="px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                <option value="">Alle Zuweisungen</option>
                <option value="assigned">Zugewiesen</option>
                <option value="unassigned">Nicht zugewiesen</option>
            </select>
            @if($search || $filterType || $filterAssignment)
                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="resetFilters">
                    @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                    Zurücksetzen
                </x-ui-button>
            @endif
            <div class="ml-auto flex items-center gap-1.5 text-xs text-[color:var(--ui-secondary)]">
                <span>Pro Seite</span>
                <select wire:model.live="perPage" class="px-2 py-1 text-xs rounded-md bg-black/[0.04] dark:bg-white/[0.06] border border-black/10 dark:border-white/10">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        {{-- Tabelle --}}
        <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
            @if($items->isEmpty())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    @svg('heroicon-o-rectangle-group', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
                    <p class="text-sm text-[color:var(--ui-secondary)]">
                        @if($search || $filterType || $filterAssignment)
                            Keine Hardware für diese Filter.
                        @else
                            Noch keine Hardware erfasst — weder manuelle Assets noch Intune-Geräte.
                        @endif
                    </p>
                </div>
            @else
                <div class="px-5 pt-3 text-[11px] text-[color:var(--ui-secondary)]">{{ $totalFiltered }} Einträge</div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[color:var(--ui-muted)] bg-[color:var(--ui-muted-10)]">
                            <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">
                                <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-[color:var(--ui-primary)]">
                                    Name
                                    @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">
                                <button wire:click="sortBy('type')" class="flex items-center gap-1 hover:text-[color:var(--ui-primary)]">
                                    Typ
                                    @if($sortField === 'type') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Seriennr.</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">
                                <button wire:click="sortBy('assignedTo')" class="flex items-center gap-1 hover:text-[color:var(--ui-primary)]">
                                    Zugewiesen an
                                    @if($sortField === 'assignedTo') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">
                                <button wire:click="sortBy('status')" class="flex items-center gap-1 hover:text-[color:var(--ui-primary)]">
                                    Status
                                    @if($sortField === 'status') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">
                                <button wire:click="sortBy('monthlyCost')" class="flex items-center gap-1 hover:text-[color:var(--ui-primary)] ml-auto"
                                        title="AfA/Leasing je Objekt — keine Kostenstellen-Zuteilung">
                                    Monatskosten
                                    @if($sortField === 'monthlyCost') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[color:var(--ui-muted)]">
                        @foreach($items as $row)
                            <tr wire:key="inv-{{ $row->type }}-{{ $row->id }}" class="transition-colors hover:bg-[color:var(--ui-muted-10)]">
                                <td class="px-5 py-3">
                                    <a href="{{ $row->detailRoute }}" wire:navigate
                                       class="font-medium text-gray-900 dark:text-gray-100 hover:text-[color:var(--ui-primary)]">
                                        {{ $row->name }}
                                    </a>
                                    @if($row->manufacturer || $row->model)
                                        <div class="text-xs text-[color:var(--ui-secondary)]">{{ trim($row->manufacturer . ' ' . $row->model) }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if($row->type === 'intune')
                                        <x-asset-manager-badge color="violet" size="sm" icon="heroicon-o-cloud">Intune</x-asset-manager-badge>
                                    @else
                                        <x-asset-manager-badge color="gray" size="sm" icon="heroicon-o-wrench-screwdriver">Manuell</x-asset-manager-badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-[color:var(--ui-secondary)] text-xs">
                                    {{ $row->serialNumber ?: '—' }}
                                </td>
                                <td class="px-5 py-3 text-gray-700 dark:text-gray-300">
                                    {{ $row->assignedTo ?: '—' }}
                                </td>
                                <td class="px-5 py-3">
                                    @if($row->statusLabel === '—')
                                        <span class="text-[color:var(--ui-muted)]">—</span>
                                    @else
                                        <x-asset-manager-badge :color="$row->statusColor" dot size="sm">{{ $row->statusLabel }}</x-asset-manager-badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if($row->monthlyCost > 0)
                                        <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row->monthlyCost, 2, ',', '.') }} €</span>
                                    @else
                                        <span class="text-[color:var(--ui-muted)]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if($items->hasPages())
                    <div class="px-5 py-3 border-t border-[color:var(--ui-muted)]">
                        {{ $items->links() }}
                    </div>
                @endif
            @endif
        </div>

        <p class="text-[11px] text-[color:var(--ui-secondary)] px-1">
            Monatskosten = AfA/Leasing je Objekt (rohe Gerätekosten) — nicht die kostenstellen-zugeteilte Summe aus der Kostenaufteilung.
        </p>
    </x-ui-page-container>
</x-ui-page>
