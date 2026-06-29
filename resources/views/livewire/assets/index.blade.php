<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Assets', 'icon' => 'cube-transparent'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
                {{-- Schreib-Controls nur Owner/Admin (E1/ADR 0004) — Backend: AssetItemPolicy::create. --}}
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="ghost" size="sm" wire:click="$toggle('showBulkCreate')">
                        @svg('heroicon-o-square-3-stack-3d', 'w-3.5 h-3.5')
                        Mehrere anlegen
                    </x-asset-manager-button>
                    <x-asset-manager-button variant="primary" size="sm"
                                 href="{{ route('asset-manager.assets.create') }}" wire:navigate>
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Asset anlegen
                    </x-asset-manager-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">

                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search"
                        placeholder="Name, Hersteller, Seriennr..." />
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Kategorie">
                    <x-asset-manager-select size="sm" wire:model.live="filterCategory">
                        <option value="">Alle</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Status">
                    <x-asset-manager-select size="sm" wire:model.live="filterStatus">
                        <option value="">Alle</option>
                        <option value="in_stock">Lager</option>
                        <option value="assigned">Zugewiesen</option>
                        <option value="retired">Ausgemustert</option>
                        <option value="lost">Verloren</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Herkunft">
                    <x-asset-manager-select size="sm" wire:model.live="filterSource">
                        <option value="">Alle</option>
                        <option value="manual">Manuell</option>
                        <option value="intune">Intune (synced)</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Zugewiesen an">
                    <x-asset-manager-select size="sm" wire:model.live="filterAssignee">
                        <option value="">Alle</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                        @endforeach
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                @if($search || $filterCategory || $filterStatus || $filterSource || $filterAssignee)
                    <x-asset-manager-button variant="ghost" size="sm" class="w-full"
                                 wire:click="$set('search', ''); $set('filterCategory', ''); $set('filterStatus', ''); $set('filterSource', ''); $set('filterAssignee', null)">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-asset-manager-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Schnellaktionen --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktionen" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="primary" size="md" class="w-full"
                                 href="{{ route('asset-manager.assets.create') }}" wire:navigate>
                        @svg('heroicon-o-plus', 'w-4 h-4')
                        Neues Asset anlegen
                    </x-asset-manager-button>
                    <x-asset-manager-button variant="secondary" size="md" class="w-full" wire:click="$toggle('showBulkCreate')">
                        @svg('heroicon-o-square-3-stack-3d', 'w-4 h-4')
                        Mehrere identische anlegen
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
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Bulk-Result-Feedback --}}
            @if($bulkResult)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-600 flex-shrink-0')
                    <p class="text-sm text-emerald-700">{{ $bulkResult }}</p>
                    <button wire:click="$set('bulkResult', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
                </div>
            @endif

            {{-- Bulk-Anlage-Formular — nur Owner/Admin (Backend: createBulk → Gate create). --}}
            @can('asset-manager.manage')
            @if($showBulkCreate)
                <x-asset-manager-panel title="Mehrere identische Assets anlegen">
                    <x-slot name="actions">
                        <button wire:click="$set('showBulkCreate', false)" class="text-[var(--am-text-muted)] hover:text-[var(--am-text-secondary)]">@svg('heroicon-o-x-mark', 'w-4 h-4')</button>
                    </x-slot>
                    <form wire:submit="createBulk" class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                            <div class="sm:col-span-1">
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kategorie *</label>
                                <x-asset-manager-select size="sm" wire:model.live="bcCategoryId">
                                    <option value="">–</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </x-asset-manager-select>
                                @error('bcCategoryId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Name *</label>
                                <x-asset-manager-input size="sm" type="text" wire:model="bcName" placeholder="z.B. Logitech MX Keys" />
                                @error('bcName') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Anzahl *</label>
                                <x-asset-manager-input size="sm" type="number" min="1" max="500" wire:model="bcQuantity" />
                                @error('bcQuantity') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Hersteller</label>
                                <x-asset-manager-input size="sm" type="text" wire:model="bcManufacturer" />
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Modell</label>
                                <x-asset-manager-input size="sm" type="text" wire:model="bcModel" />
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufpreis (€/Stück)</label>
                                <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="bcPurchasePrice" />
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">AfA (Monate)</label>
                                <x-asset-manager-input size="sm" type="number" min="1" max="240" wire:model="bcDepreciationMonths" />
                            </div>
                        </div>
                        <div class="flex items-end gap-3">
                            <div class="flex-1 max-w-xs">
                                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Direkt zuweisen an</label>
                                <x-asset-manager-select size="sm" wire:model="bcAssigneeId">
                                    <option value="">– Niemand (Lager) –</option>
                                    @foreach($employees as $emp)
                                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                    @endforeach
                                </x-asset-manager-select>
                            </div>
                            <x-asset-manager-button type="submit" variant="primary" size="md">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                <span>{{ $bcQuantity > 1 ? $bcQuantity . '× anlegen' : 'Anlegen' }}</span>
                            </x-asset-manager-button>
                        </div>
                        <p class="text-[10px] text-[var(--am-text-muted)]">Seriennummern bleiben leer und können später pro Gerät ergänzt werden.</p>
                    </form>
                </x-asset-manager-panel>
            @endif
            @endcan

            {{-- Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <x-asset-manager-stat-card label="Assets gesamt" :value="$stats['total']" accent="navy" />
                <x-asset-manager-stat-card label="Zugewiesen" :value="$stats['assigned']" accent="emerald" value-class="text-emerald-600" />
                <x-asset-manager-stat-card label="Auf Lager" :value="$stats['in_stock']" accent="sky" value-class="text-sky-600" />
                <x-asset-manager-stat-card label="Ausgemustert" :value="$stats['retired']" accent="navy" value-class="text-[var(--am-text-muted)]" />
            </div>

            {{-- Bulk-Aktionsleiste — nur Owner/Admin (Backend: bulk* → Gate create/delete). --}}
            @can('asset-manager.manage')
            @if(count($selected) > 0)
                <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm">
                    <span class="text-sm font-medium">{{ count($selected) }} ausgewählt</span>

                    <div class="h-4 w-px bg-white/30"></div>

                    {{-- Zuweisen --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkAssignee" class="px-2 py-1 text-xs rounded-md bg-white/15 border border-white/20 text-[var(--am-on-primary)] [&>option]:text-gray-900">
                            <option value="">An wen zuweisen…</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                            @endforeach
                        </select>
                        <button wire:click="bulkAssign" class="px-2.5 py-1 text-xs font-medium rounded-md bg-white/20 hover:bg-white/30 transition-colors">Zuweisen</button>
                    </div>

                    <button wire:click="bulkUnassign" class="px-2.5 py-1 text-xs font-medium rounded-md bg-white/20 hover:bg-white/30 transition-colors">Ins Lager</button>

                    {{-- Status --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkStatus" class="px-2 py-1 text-xs rounded-md bg-white/15 border border-white/20 text-[var(--am-on-primary)] [&>option]:text-gray-900">
                            <option value="">Status setzen…</option>
                            <option value="in_stock">Lager</option>
                            <option value="retired">Ausgemustert</option>
                            <option value="lost">Verloren</option>
                        </select>
                        <button wire:click="bulkSetStatus" class="px-2.5 py-1 text-xs font-medium rounded-md bg-white/20 hover:bg-white/30 transition-colors">Setzen</button>
                    </div>

                    <button wire:click="bulkDelete" wire:confirm="Ausgewählte manuelle Assets wirklich löschen?" class="px-2.5 py-1 text-xs font-medium rounded-md bg-red-500/80 hover:bg-red-500 transition-colors">
                        @svg('heroicon-o-trash', 'w-3.5 h-3.5 inline -mt-0.5') Löschen
                    </button>

                    <button wire:click="clearSelection" class="ml-auto text-xs text-white/80 hover:text-white">Auswahl aufheben</button>
                </div>
            @endif
            @endcan

            {{-- Tabelle --}}
            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-cube-transparent', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                        <p class="text-sm text-[var(--am-text-secondary)] mb-3">
                            @if($search || $filterCategory || $filterStatus || $filterSource || $filterAssignee)
                                Keine Assets für diese Filter.
                            @else
                                Noch keine Assets angelegt.
                            @endif
                        </p>
                        @if(!$search && !$filterCategory && !$filterStatus && !$filterSource && !$filterAssignee)
                            @can('asset-manager.manage')
                                <x-asset-manager-button variant="primary" size="md"
                                             href="{{ route('asset-manager.assets.create') }}" wire:navigate>
                                    @svg('heroicon-o-plus', 'w-4 h-4')
                                    Erstes Asset anlegen
                                </x-asset-manager-button>
                            @endcan
                        @endif
                    </div>
                @else
                    {{-- "Alle X auswählen"-Banner — nur Owner/Admin (Bulk ist owner/admin-only). --}}
                    @can('asset-manager.manage')
                    @if($selectPage && count($selected) < $totalFiltered)
                        <div class="px-5 py-2 bg-[var(--am-accent-surface)] border-b border-[color:var(--am-border)] text-center text-xs text-[var(--am-accent)]">
                            Alle {{ count($selected) }} auf dieser Seite ausgewählt.
                            <button wire:click="selectAllFiltered" class="font-medium underline hover:opacity-80">Alle {{ $totalFiltered }} gefilterten auswählen</button>
                        </div>
                    @endif
                    @endcan

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                                @can('asset-manager.manage')
                                <th class="w-10 px-5 py-3">
                                    <input type="checkbox" wire:model.live="selectPage" class="rounded border-[color:var(--am-border)] text-[var(--am-accent)] focus:ring-[var(--am-accent)]/30" />
                                </th>
                                @endcan
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                    <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-[var(--am-accent)]">
                                        Name
                                        @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Kategorie</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Zugewiesen an</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Status</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Herkunft</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($items as $item)
                                @php $isChecked = in_array((string) $item->id, $selected, true); @endphp
                                <tr wire:key="item-{{ $item->id }}" class="transition-colors {{ $isChecked ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : 'hover:bg-[var(--am-bg)]' }}">
                                    @can('asset-manager.manage')
                                    <td class="px-5 py-3">
                                        <input type="checkbox" value="{{ $item->id }}" wire:model.live="selected" class="rounded border-[color:var(--am-border)] text-[var(--am-accent)] focus:ring-[var(--am-accent)]/30" />
                                    </td>
                                    @endcan
                                    <td class="px-5 py-3">
                                        <a href="{{ route('asset-manager.assets.show', $item) }}" wire:navigate
                                           class="font-medium text-[var(--am-text)] hover:text-[var(--am-accent)]">
                                            {{ $item->name }}
                                        </a>
                                        @if($item->manufacturer || $item->model)
                                            <div class="text-xs text-[var(--am-text-secondary)]">{{ trim($item->manufacturer . ' ' . $item->model) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-[var(--am-text-secondary)]">
                                        <span class="inline-flex items-center gap-1.5">
                                            @if($item->category?->icon) @svg($item->category->icon, 'w-3.5 h-3.5 text-[var(--am-text-muted)]') @endif
                                            {{ $item->category?->name ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-[var(--am-text-secondary)]">
                                        {{ $item->assignee?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-asset-manager-badge :color="$item->statusBadgeColor()" dot size="sm">{{ $item->statusLabel() }}</x-asset-manager-badge>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-[var(--am-text-secondary)]">
                                        @if($item->source === 'intune')
                                            <x-asset-manager-badge color="violet" size="xs" icon="heroicon-o-cloud">Intune</x-asset-manager-badge>
                                        @else
                                            <span class="text-[var(--am-text-secondary)]">Manuell</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($items->hasPages())
                        <div class="px-5 py-3 border-t border-[color:var(--am-border)]">
                            {{ $items->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
