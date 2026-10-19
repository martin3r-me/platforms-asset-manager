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
                {{-- Die Legacy-Liste /assets ist abgelöst (#974): „nur manuelle" ist jetzt der Typ-Filter hier. --}}
                <x-asset-manager-button variant="ghost" size="sm" wire:click="$set('filterType', 'manual')">
                    @svg('heroicon-o-cube-transparent', 'w-3.5 h-3.5')
                    Nur manuelle Assets
                </x-asset-manager-button>
                <x-asset-manager-button variant="ghost" size="sm"
                             href="{{ route('asset-manager.devices.index') }}" wire:navigate>
                    @svg('heroicon-o-computer-desktop', 'w-3.5 h-3.5')
                    Nur Intune-Geräte
                </x-asset-manager-button>
                {{-- Lesepfad: Export auch für Member (wie Geräte- und Trägerliste). Folgt Filtern + Sortierung. --}}
                <x-asset-manager-button variant="ghost" size="sm" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                    @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                    Export CSV
                </x-asset-manager-button>
                {{-- Anlegen nur Owner/Admin (Backend: createItem → Gate create). Nur manuelle Assets (E7). --}}
                @if($canManage)
                    <x-asset-manager-button variant="primary" size="sm" wire:click="openCreate">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Asset anlegen
                    </x-asset-manager-button>
                @endif
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter (Token-Sektionen — nicht die fetten x-ui-Tische). --}}
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

                {{-- Kostenstelle ist Controlling-Stammdatum (#874, ADR 0008). --}}
                @if($controllingEnabled)
                    <x-asset-manager-filter-section title="Kostenstelle" note="· nur Geräte">
                        <x-asset-manager-select size="sm" wire:model.live="filterCostCenter">
                            <option value="">Alle</option>
                            @foreach($costCenters as $cc)
                                <option value="{{ $cc->id }}">{{ trim(($cc->code ? $cc->code . ' · ' : '') . $cc->name) }}</option>
                            @endforeach
                        </x-asset-manager-select>
                    </x-asset-manager-filter-section>
                @endif

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
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

        {{-- Kennzahlen. Standen bis S8 in einer rechten Sidebar, die per Default zugeklappt war —
             also ausgerechnet die Zahlen, die den Einstieg geben sollen, sah man erst nach einem
             Klick. Die Schnellaktionen von dort duplizierten die Actionbar und sind ersatzlos weg. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-asset-manager-stat-card label="Hardware gesamt" :value="$counts['total']" icon="heroicon-o-rectangle-group" accent="navy" />
            <x-asset-manager-stat-card label="Zugeordnet" :value="$counts['assigned']" icon="heroicon-o-user" accent="emerald" value-class="text-emerald-600" />
            <x-asset-manager-stat-card label="Manuelle Assets" :value="$counts['manual']" icon="heroicon-o-cube-transparent" :href="route('asset-manager.inventory.index', ['filterType' => 'manual'])" />
            <x-asset-manager-stat-card label="Intune-Geräte" :value="$counts['intune']" icon="heroicon-o-computer-desktop" accent="violet" value-class="text-violet-600" :href="route('asset-manager.devices.index')" />
        </div>

        @if($flash)
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                <p class="text-sm text-emerald-700">{{ $flash }}</p>
                <button wire:click="$set('flash', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
            </div>
        @endif

        {{-- Sammelaktionen — nur Owner/Admin, nur manuelle Assets (#974). Alles läuft über den
             AssetWriteService: Status-Regel wie auf der Detailseite (#941). --}}
        @if($canManage && count($selected) > 0)
            <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm">
                <span class="text-sm font-medium">{{ count($selected) }} ausgewählt</span>
                <div class="h-4 w-px bg-[var(--am-on-primary)]/30"></div>

                <div class="flex items-center gap-1.5">
                    <select wire:model="bulkAssignee" class="px-2 py-1 text-xs rounded-md bg-[var(--am-on-primary)]/15 border border-[var(--am-on-primary)]/20 text-[var(--am-on-primary)] [&>option]:text-[var(--am-text)]">
                        <option value="">Zuordnen an…</option>
                        @foreach($holders as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                        @endforeach
                    </select>
                    <button wire:click="bulkAssign" class="px-2.5 py-1 text-xs font-medium rounded-md bg-[var(--am-on-primary)]/20 hover:bg-[var(--am-on-primary)]/30 transition-colors">Zuordnen</button>
                </div>

                <button wire:click="bulkUnassign" class="px-2.5 py-1 text-xs font-medium rounded-md bg-[var(--am-on-primary)]/20 hover:bg-[var(--am-on-primary)]/30 transition-colors">Ins Lager</button>

                <div class="flex items-center gap-1.5">
                    <select wire:model="bulkStatus" class="px-2 py-1 text-xs rounded-md bg-[var(--am-on-primary)]/15 border border-[var(--am-on-primary)]/20 text-[var(--am-on-primary)] [&>option]:text-[var(--am-text)]">
                        <option value="">Status setzen…</option>
                        <option value="in_stock">Lager</option>
                        <option value="retired">Ausgemustert</option>
                        <option value="lost">Verloren</option>
                    </select>
                    <button wire:click="bulkSetStatus" class="px-2.5 py-1 text-xs font-medium rounded-md bg-[var(--am-on-primary)]/20 hover:bg-[var(--am-on-primary)]/30 transition-colors">Setzen</button>
                </div>

                <button wire:click="bulkDelete" wire:confirm="Ausgewählte manuelle Assets wirklich löschen?" class="px-2.5 py-1 text-xs font-medium rounded-md bg-red-500/80 hover:bg-red-500 transition-colors">
                    @svg('heroicon-o-trash', 'w-3.5 h-3.5 inline -mt-0.5') Löschen
                </button>

                <button wire:click="clearSelection" class="ml-auto text-xs text-[var(--am-on-primary)]/80 hover:text-[var(--am-on-primary)]">Auswahl aufheben</button>
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
                @if($canManage && $selectPage && count($selected) < $manualFiltered)
                    <div class="px-5 py-2 bg-[var(--am-accent-surface)] border-b border-[color:var(--am-border)] text-center text-xs text-[var(--am-accent)]">
                        Alle {{ count($selected) }} manuellen Assets auf dieser Seite ausgewählt.
                        <button wire:click="selectAllFiltered" class="font-medium underline hover:opacity-80">Alle {{ $manualFiltered }} gefilterten manuellen Assets auswählen</button>
                    </div>
                @endif
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                            @if($canManage)
                                <th class="w-10 px-4 py-3" title="Auswahl gilt nur für manuelle Assets — Geräte haben ihre Sammelaktionen auf der Geräteliste">
                                    <input type="checkbox" wire:model.live="selectPage" class="rounded border-[color:var(--am-border)] text-[var(--am-accent)] focus:ring-[var(--am-accent)]/30" />
                                </th>
                            @endif
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
                                    Zugeordnet an
                                    @if($sortField === 'assignedTo') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <button wire:click="sortBy('status')" class="flex items-center gap-1 hover:text-[var(--am-text)]">
                                    Status
                                    @if($sortField === 'status') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                </button>
                            </th>
                            @if($controllingEnabled)
                                <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                    <button wire:click="sortBy('monthlyCost')" class="flex items-center gap-1 hover:text-[var(--am-text)] ml-auto"
                                            title="AfA/Leasing je Objekt — keine Kostenstellen-Zuteilung">
                                        Monatskosten
                                        @if($sortField === 'monthlyCost') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[color:var(--am-border)]">
                        @foreach($items as $row)
                            @php $isChecked = $canManage && $row->type === 'manual' && in_array((string) $row->id, $selected, true); @endphp
                            <tr wire:key="inv-{{ $row->type }}-{{ $row->id }}" class="transition-colors {{ $isChecked ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : 'hover:bg-[var(--am-bg)]' }}">
                                @if($canManage)
                                    <td class="px-4 py-3">
                                        @if($row->type === 'manual')
                                            <input type="checkbox" value="{{ $row->id }}" wire:model.live="selected" class="rounded border-[color:var(--am-border)] text-[var(--am-accent)] focus:ring-[var(--am-accent)]/30" />
                                        @endif
                                    </td>
                                @endif
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
                                @if($controllingEnabled)
                                    <td class="px-4 py-3 text-right">
                                        @if($row->monthlyCost > 0)
                                            <span class="tabular-nums text-[var(--am-text)]">{{ number_format($row->monthlyCost, 2, ',', '.') }} €</span>
                                        @else
                                            <span class="text-[var(--am-text-disabled)]">—</span>
                                        @endif
                                    </td>
                                @endif
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

        @if($controllingEnabled)
            <p class="text-[11px] text-[var(--am-text-muted)] px-1">
                Monatskosten = AfA/Leasing je Objekt (rohe Gerätekosten) — nicht die kostenstellen-zugeteilte Summe aus der Kostenaufteilung.
            </p>
        @endif
        </div>
    </div>

    {{-- Modals innerhalb <x-ui-page> (Referenz-Doku: „Modals immer innerhalb von x-ui-page"). --}}
    @if($canManage)
        @include('asset-manager::livewire.inventory.partials.modal-create')
    @endif
</x-ui-page>
