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
                <x-asset-manager-button variant="ghost" size="sm"
                             href="{{ route('asset-manager.assets.index') }}" wire:navigate>
                    @svg('heroicon-o-cube-transparent', 'w-3.5 h-3.5')
                    Nur manuelle Assets
                </x-asset-manager-button>
                <x-asset-manager-button variant="ghost" size="sm"
                             href="{{ route('asset-manager.devices.index') }}" wire:navigate>
                    @svg('heroicon-o-computer-desktop', 'w-3.5 h-3.5')
                    Nur Intune-Geräte
                </x-asset-manager-button>
                {{-- Anlegen nur Owner/Admin (Backend: createItem → Gate create). Nur manuelle Assets (E7). --}}
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="primary" size="sm" wire:click="openCreate">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Asset anlegen
                    </x-asset-manager-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter (Token-Sektionen wie assets/index — nicht die fetten x-ui-Tische). --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">

                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search"
                        placeholder="Name, Hersteller, Seriennr., Nutzer..." />
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Typ">
                    <x-asset-manager-select size="sm" wire:model.live="filterType">
                        <option value="">Alle</option>
                        <option value="manual">Manuelle Assets</option>
                        <option value="intune">Intune-Geräte</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Zuordnung">
                    <x-asset-manager-select size="sm" wire:model.live="filterAssignment">
                        <option value="">Alle</option>
                        <option value="assigned">Zugeordnet</option>
                        <option value="unassigned">Nicht zugeordnet</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Status / Lebenszyklus">
                    <x-asset-manager-select size="sm" wire:model.live="filterStatus">
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
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Kategorie" note="· nur manuelle">
                    <x-asset-manager-select size="sm" wire:model.live="filterCategory">
                        <option value="">Alle</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Kostenstelle" note="· nur Geräte">
                    <x-asset-manager-select size="sm" wire:model.live="filterCostCenter">
                        <option value="">Alle</option>
                        @foreach($costCenters as $cc)
                            <option value="{{ $cc->id }}">{{ trim(($cc->code ? $cc->code . ' · ' : '') . $cc->name) }}</option>
                        @endforeach
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                @if($this->hasFilters)
                    <x-asset-manager-button variant="ghost" size="sm" class="w-full" wire:click="resetFilters">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-asset-manager-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Kennzahlen + Schnellaktionen --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-chart-bar" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">

                {{-- Quick-Stats (kompakt, flach) --}}
                <section class="space-y-2">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-1">Kennzahlen</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                            <div class="text-xl font-semibold text-[var(--am-text)]">{{ $counts['total'] }}</div>
                            <div class="text-[10px] text-[var(--am-text-muted)]">Hardware gesamt</div>
                        </div>
                        <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                            <div class="text-xl font-semibold text-emerald-600">{{ $counts['assigned'] }}</div>
                            <div class="text-[10px] text-[var(--am-text-muted)]">Zugewiesen</div>
                        </div>
                        <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                            <div class="text-xl font-semibold text-[var(--am-text-secondary)]">{{ $counts['manual'] }}</div>
                            <div class="text-[10px] text-[var(--am-text-muted)]">Manuelle Assets</div>
                        </div>
                        <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                            <div class="text-xl font-semibold text-violet-600">{{ $counts['intune'] }}</div>
                            <div class="text-[10px] text-[var(--am-text-muted)]">Intune-Geräte</div>
                        </div>
                    </div>
                </section>

                {{-- Schnellaktionen --}}
                <section class="space-y-2">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-1">Schnellaktionen</h3>
                    @can('asset-manager.manage')
                        <x-asset-manager-button variant="primary" size="md" class="w-full" wire:click="openCreate">
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            Asset anlegen
                        </x-asset-manager-button>
                    @endcan
                    <x-asset-manager-button variant="secondary" size="md" class="w-full"
                                 href="{{ route('asset-manager.employees.index') }}" wire:navigate>
                        @svg('heroicon-o-users', 'w-4 h-4')
                        Zu den Mitarbeitern
                    </x-asset-manager-button>
                    <x-asset-manager-button variant="secondary" size="md" class="w-full"
                                 href="{{ route('asset-manager.devices.index') }}" wire:navigate>
                        @svg('heroicon-o-computer-desktop', 'w-4 h-4')
                        Nur Intune-Geräte
                    </x-asset-manager-button>
                    <x-asset-manager-button variant="secondary" size="md" class="w-full"
                                 href="{{ route('asset-manager.assets.index') }}" wire:navigate>
                        @svg('heroicon-o-cube-transparent', 'w-4 h-4')
                        Nur manuelle Assets
                    </x-asset-manager-button>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

        @if($flash)
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                <p class="text-sm text-emerald-700">{{ $flash }}</p>
                <button wire:click="$set('flash', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
            </div>
        @endif

        {{-- Schlanke Kopfzeile: Treffer + Seitengröße (Filter sind in der linken Sidebar). --}}
        <div class="flex items-center justify-between gap-2 px-1">
            <div class="text-xs text-[var(--am-text-secondary)]">{{ $totalFiltered }} Einträge</div>
            <div class="flex items-center gap-1.5 text-xs text-[var(--am-text-secondary)]">
                <span>Pro Seite</span>
                <select wire:model.live="perPage" class="px-2 py-1 text-xs rounded-md bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] text-[var(--am-text)] cursor-pointer focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)]">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        {{-- Tabelle --}}
        <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
            @if($items->isEmpty())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    @svg('heroicon-o-rectangle-group', 'w-10 h-10 text-[var(--am-text-disabled)] mb-3')
                    <p class="text-sm text-[var(--am-text-secondary)]">
                        @if($this->hasFilters)
                            Keine Hardware für diese Filter.
                        @else
                            Noch keine Hardware erfasst — weder manuelle Assets noch Intune-Geräte.
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                            <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-[var(--am-text)]">
                                    Name
                                    @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button wire:click="sortBy('type')" class="flex items-center gap-1 hover:text-[var(--am-text)]">
                                    Typ
                                    @if($sortField === 'type') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Seriennr.</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button wire:click="sortBy('assignedTo')" class="flex items-center gap-1 hover:text-[var(--am-text)]">
                                    Zugewiesen an
                                    @if($sortField === 'assignedTo') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button wire:click="sortBy('status')" class="flex items-center gap-1 hover:text-[var(--am-text)]">
                                    Status
                                    @if($sortField === 'status') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button wire:click="sortBy('monthlyCost')" class="flex items-center gap-1 hover:text-[var(--am-text)] ml-auto"
                                        title="AfA/Leasing je Objekt — keine Kostenstellen-Zuteilung">
                                    Monatskosten
                                    @if($sortField === 'monthlyCost') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[color:var(--am-border)]">
                        @foreach($items as $row)
                            <tr wire:key="inv-{{ $row->type }}-{{ $row->id }}" class="transition-colors hover:bg-[var(--am-bg)]">
                                <td class="px-4 py-3">
                                    <a href="{{ $row->detailRoute }}" wire:navigate
                                       class="font-medium text-[var(--am-text)] hover:text-[var(--am-accent)]">
                                        {{ $row->name }}
                                    </a>
                                    @if($row->manufacturer || $row->model)
                                        <div class="text-xs text-[var(--am-text-muted)]">{{ trim($row->manufacturer . ' ' . $row->model) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($row->type === 'intune')
                                        <x-asset-manager-badge color="violet" size="sm" icon="heroicon-o-cloud">Intune</x-asset-manager-badge>
                                    @else
                                        <x-asset-manager-badge color="gray" size="sm" icon="heroicon-o-wrench-screwdriver">Manuell</x-asset-manager-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-[var(--am-text-muted)] text-xs">
                                    {{ $row->serialNumber ?: '—' }}
                                </td>
                                <td class="px-4 py-3 text-[var(--am-text-secondary)]">
                                    {{ $row->assignedTo ?: '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    @if($row->statusLabel === '—')
                                        <span class="text-[var(--am-text-disabled)]">—</span>
                                    @else
                                        <x-asset-manager-badge :color="$row->statusColor" dot size="sm">{{ $row->statusLabel }}</x-asset-manager-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($row->monthlyCost > 0)
                                        <span class="tabular-nums text-[var(--am-text)]">{{ number_format($row->monthlyCost, 2, ',', '.') }} €</span>
                                    @else
                                        <span class="text-[var(--am-text-disabled)]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>

                @if($items->hasPages())
                    <div class="px-4 py-3 border-t border-[color:var(--am-border)]">
                        {{ $items->links() }}
                    </div>
                @endif
            @endif
        </div>

        <p class="text-[11px] text-[var(--am-text-muted)] px-1">
            Monatskosten = AfA/Leasing je Objekt (rohe Gerätekosten) — nicht die kostenstellen-zugeteilte Summe aus der Kostenaufteilung.
        </p>
        </div>
    </div>

    {{-- Modals innerhalb <x-ui-page> (Referenz-Doku: „Modals immer innerhalb von x-ui-page"). --}}
    @can('asset-manager.manage')
        @include('asset-manager::livewire.inventory.partials.modal-create')
    @endcan
</x-ui-page>
