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
                {{-- Anlegen nur Owner/Admin (Backend: createItem → Gate create). Nur manuelle Assets (E7). --}}
                @can('asset-manager.manage')
                    <x-ui-button variant="primary" size="sm" rounded="lg" wire:click="openCreate">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Asset anlegen
                    </x-ui-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter (Token-Sektionen wie assets/index — nicht die fetten x-ui-Tische). --}}
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
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Zuordnung</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterAssignment" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            <option value="assigned">Zugeordnet</option>
                            <option value="unassigned">Nicht zugeordnet</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Status / Lebenszyklus</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterStatus" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            <optgroup label="Manuelle Assets">
                                <option value="in_stock">Lager</option>
                                <option value="assigned">Zugewiesen</option>
                            </optgroup>
                            <optgroup label="Geräte (Lebenszyklus)">
                                <option value="in_use">In Betrieb</option>
                                <option value="spare">Reserve / Lager</option>
                                <option value="repair">In Reparatur</option>
                                <option value="defect">Defekt / Kaputt</option>
                            </optgroup>
                            <optgroup label="Ausgemustert / Verloren">
                                <option value="retired">Ausgemustert</option>
                                <option value="lost">Verloren</option>
                            </optgroup>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kategorie <span class="normal-case text-[var(--ui-muted)]/70">· nur manuelle</span></h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterCategory" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kostenstelle <span class="normal-case text-[var(--ui-muted)]/70">· nur Geräte</span></h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterCostCenter" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            @foreach($costCenters as $cc)
                                <option value="{{ $cc->id }}">{{ trim(($cc->code ? $cc->code . ' · ' : '') . $cc->name) }}</option>
                            @endforeach
                        </select>
                    </div>
                </section>

                @if($this->hasFilters)
                    <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" class="w-full" wire:click="resetFilters">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-ui-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Kennzahlen + Schnellaktionen --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-chart-bar" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                {{-- Quick-Stats --}}
                <section class="space-y-2">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1">Kennzahlen</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $counts['total'] }}</div>
                            <div class="text-[10px] text-[color:var(--ui-secondary)]">Hardware gesamt</div>
                        </div>
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                            <div class="text-xl font-semibold text-emerald-700 dark:text-emerald-400">{{ $counts['assigned'] }}</div>
                            <div class="text-[10px] text-[color:var(--ui-secondary)]">Zugewiesen</div>
                        </div>
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                            <div class="text-xl font-semibold text-gray-600 dark:text-gray-300">{{ $counts['manual'] }}</div>
                            <div class="text-[10px] text-[color:var(--ui-secondary)]">Manuelle Assets</div>
                        </div>
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                            <div class="text-xl font-semibold text-[color:var(--ui-primary)]">{{ $counts['intune'] }}</div>
                            <div class="text-[10px] text-[color:var(--ui-secondary)]">Intune-Geräte</div>
                        </div>
                    </div>
                </section>

                {{-- Schnellaktionen --}}
                <section class="space-y-2">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1">Schnellaktionen</h3>
                    @can('asset-manager.manage')
                        <x-ui-button variant="primary" size="md" rounded="lg" class="w-full" wire:click="openCreate">
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            Asset anlegen
                        </x-ui-button>
                    @endcan
                    <x-ui-button variant="secondary-ghost" size="md" rounded="lg" class="w-full"
                                 href="{{ route('asset-manager.employees.index') }}" wire:navigate>
                        @svg('heroicon-o-users', 'w-4 h-4')
                        Zu den Mitarbeitern
                    </x-ui-button>
                    <x-ui-button variant="secondary-ghost" size="md" rounded="lg" class="w-full"
                                 href="{{ route('asset-manager.devices.index') }}" wire:navigate>
                        @svg('heroicon-o-computer-desktop', 'w-4 h-4')
                        Nur Intune-Geräte
                    </x-ui-button>
                    <x-ui-button variant="secondary-ghost" size="md" rounded="lg" class="w-full"
                                 href="{{ route('asset-manager.assets.index') }}" wire:navigate>
                        @svg('heroicon-o-cube-transparent', 'w-4 h-4')
                        Nur manuelle Assets
                    </x-ui-button>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

        @if($flash)
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                <p class="text-sm text-emerald-700 dark:text-emerald-400">{{ $flash }}</p>
                <button wire:click="$set('flash', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
            </div>
        @endif

        {{-- Schlanke Kopfzeile: Treffer + Seitengröße (Filter sind in der linken Sidebar). --}}
        <div class="flex items-center justify-between gap-2 px-1">
            <div class="text-xs text-[color:var(--ui-secondary)]">{{ $totalFiltered }} Einträge</div>
            <div class="flex items-center gap-1.5 text-xs text-[color:var(--ui-secondary)]">
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
                        @if($this->hasFilters)
                            Keine Hardware für diese Filter.
                        @else
                            Noch keine Hardware erfasst — weder manuelle Assets noch Intune-Geräte.
                        @endif
                    </p>
                </div>
            @else
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
        </div>
    </div>

    {{-- Modals innerhalb <x-ui-page> (Referenz-Doku: „Modals immer innerhalb von x-ui-page"). --}}
    @can('asset-manager.manage')
        @include('asset-manager::livewire.inventory.partials.modal-create')
    @endcan
</x-ui-page>
