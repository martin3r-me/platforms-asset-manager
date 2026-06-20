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
                <button wire:click="$toggle('showBulkCreate')"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                    @svg('heroicon-o-square-3-stack-3d', 'w-3.5 h-3.5')
                    Mehrere anlegen
                </button>
                <a href="{{ route('asset-manager.assets.create') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                    @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                    Asset anlegen
                </a>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Name, Hersteller, Seriennr..."
                            class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kategorie</h3>
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
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Status</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterStatus" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            <option value="in_stock">Lager</option>
                            <option value="assigned">Zugewiesen</option>
                            <option value="retired">Ausgemustert</option>
                            <option value="lost">Verloren</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Herkunft</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterSource" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            <option value="manual">Manuell</option>
                            <option value="intune">Intune (synced)</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Zugewiesen an</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterAssignee" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)]">
                            <option value="">Alle</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </section>

                @if($search || $filterCategory || $filterStatus || $filterSource || $filterAssignee)
                    <button wire:click="$set('search', ''); $set('filterCategory', ''); $set('filterStatus', ''); $set('filterSource', ''); $set('filterAssignee', null)"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10 transition-colors">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Filter zurücksetzen
                    </button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Schnellaktionen --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktionen" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                <a href="{{ route('asset-manager.assets.create') }}" wire:navigate
                   class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    Neues Asset anlegen
                </a>
                <button wire:click="$toggle('showBulkCreate')"
                   class="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 bg-white border border-[var(--ui-border)]/40 rounded-lg hover:bg-[var(--ui-muted-5)] transition-all">
                    @svg('heroicon-o-square-3-stack-3d', 'w-4 h-4')
                    Mehrere identische anlegen
                </button>
                <a href="{{ route('asset-manager.employees.index') }}" wire:navigate
                   class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 bg-white border border-[var(--ui-border)]/40 rounded-lg hover:bg-[var(--ui-muted-5)] transition-all">
                    @svg('heroicon-o-users', 'w-4 h-4')
                    Zu den Mitarbeitern
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

            {{-- Bulk-Result-Feedback --}}
            @if($bulkResult)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                    <p class="text-sm text-emerald-700 dark:text-emerald-400">{{ $bulkResult }}</p>
                    <button wire:click="$set('bulkResult', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
                </div>
            @endif

            {{-- Bulk-Anlage-Formular --}}
            @if($showBulkCreate)
                <div class="rounded-xl bg-white/80 dark:bg-white/5 backdrop-blur-xl border border-violet-500/30 shadow-sm p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                            @svg('heroicon-o-square-3-stack-3d', 'w-4 h-4 text-violet-500')
                            Mehrere identische Assets anlegen
                        </h2>
                        <button wire:click="$set('showBulkCreate', false)" class="text-gray-400 hover:text-gray-600">@svg('heroicon-o-x-mark', 'w-4 h-4')</button>
                    </div>
                    <form wire:submit="createBulk" class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                            <div class="sm:col-span-1">
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Kategorie *</label>
                                <select wire:model.live="bcCategoryId" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                                    <option value="">–</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('bcCategoryId') <p class="text-[10px] text-red-500 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Name *</label>
                                <input type="text" wire:model="bcName" placeholder="z.B. Logitech MX Keys"
                                    class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                                @error('bcName') <p class="text-[10px] text-red-500 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Anzahl *</label>
                                <input type="number" min="1" max="500" wire:model="bcQuantity"
                                    class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                                @error('bcQuantity') <p class="text-[10px] text-red-500 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Hersteller</label>
                                <input type="text" wire:model="bcManufacturer" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Modell</label>
                                <input type="text" wire:model="bcModel" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Kaufpreis (€/Stück)</label>
                                <input type="number" step="0.01" min="0" wire:model="bcPurchasePrice" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">AfA (Monate)</label>
                                <input type="number" min="1" max="240" wire:model="bcDepreciationMonths" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                            </div>
                        </div>
                        <div class="flex items-end gap-3">
                            <div class="flex-1 max-w-xs">
                                <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Direkt zuweisen an</label>
                                <select wire:model="bcAssigneeId" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                                    <option value="">– Niemand (Lager) –</option>
                                    @foreach($employees as $emp)
                                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                <span>{{ $bcQuantity > 1 ? $bcQuantity . '× anlegen' : 'Anlegen' }}</span>
                            </button>
                        </div>
                        <p class="text-[10px] text-gray-400">Seriennummern bleiben leer und können später pro Gerät ergänzt werden.</p>
                    </form>
                </div>
            @endif

            {{-- Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-gray-400">Assets gesamt</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['assigned'] }}</div>
                    <div class="text-xs text-gray-400">Zugewiesen</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-sky-600 dark:text-sky-400">{{ $stats['in_stock'] }}</div>
                    <div class="text-xs text-gray-400">Auf Lager</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-500 dark:text-gray-400">{{ $stats['retired'] }}</div>
                    <div class="text-xs text-gray-400">Ausgemustert</div>
                </div>
            </div>

            {{-- Bulk-Aktionsleiste --}}
            @if(count($selected) > 0)
                <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-violet-600 text-white shadow-lg">
                    <span class="text-sm font-medium">{{ count($selected) }} ausgewählt</span>

                    <div class="h-4 w-px bg-white/30"></div>

                    {{-- Zuweisen --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkAssignee" class="px-2 py-1 text-xs rounded-md bg-white/15 border border-white/20 text-white [&>option]:text-gray-900">
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
                        <select wire:model="bulkStatus" class="px-2 py-1 text-xs rounded-md bg-white/15 border border-white/20 text-white [&>option]:text-gray-900">
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

            {{-- Tabelle --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-cube-transparent', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400 mb-3">
                            @if($search || $filterCategory || $filterStatus || $filterSource || $filterAssignee)
                                Keine Assets für diese Filter.
                            @else
                                Noch keine Assets angelegt.
                            @endif
                        </p>
                        @if(!$search && !$filterCategory && !$filterStatus && !$filterSource && !$filterAssignee)
                            <a href="{{ route('asset-manager.assets.create') }}" wire:navigate
                               class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                                @svg('heroicon-o-plus', 'w-4 h-4')
                                Erstes Asset anlegen
                            </a>
                        @endif
                    </div>
                @else
                    {{-- "Alle X auswählen"-Banner --}}
                    @if($selectPage && count($selected) < $totalFiltered)
                        <div class="px-5 py-2 bg-violet-500/10 border-b border-violet-500/20 text-center text-xs text-violet-700 dark:text-violet-400">
                            Alle {{ count($selected) }} auf dieser Seite ausgewählt.
                            <button wire:click="selectAllFiltered" class="font-medium underline hover:text-violet-900">Alle {{ $totalFiltered }} gefilterten auswählen</button>
                        </div>
                    @endif

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 dark:border-white/5">
                                <th class="w-10 px-5 py-3">
                                    <input type="checkbox" wire:model.live="selectPage" class="rounded border-black/20 dark:border-white/20 text-violet-600 focus:ring-violet-500/30" />
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-gray-600">
                                        Name
                                        @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Kategorie</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Zugewiesen an</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Status</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Herkunft</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($items as $item)
                                @php $isChecked = in_array((string) $item->id, $selected, true); @endphp
                                <tr wire:key="item-{{ $item->id }}" class="transition-colors {{ $isChecked ? 'bg-violet-500/5' : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]' }}">
                                    <td class="px-5 py-3">
                                        <input type="checkbox" value="{{ $item->id }}" wire:model.live="selected" class="rounded border-black/20 dark:border-white/20 text-violet-600 focus:ring-violet-500/30" />
                                    </td>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('asset-manager.assets.show', $item) }}" wire:navigate
                                           class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400">
                                            {{ $item->name }}
                                        </a>
                                        @if($item->manufacturer || $item->model)
                                            <div class="text-xs text-gray-400">{{ trim($item->manufacturer . ' ' . $item->model) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-700 dark:text-gray-300">
                                        <span class="inline-flex items-center gap-1.5">
                                            @if($item->category?->icon) @svg($item->category->icon, 'w-3.5 h-3.5 text-gray-400') @endif
                                            {{ $item->category?->name ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700 dark:text-gray-300">
                                        {{ $item->assignee?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3">
                                        @php $c = $item->statusBadgeColor() @endphp
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-{{ $c }}-500/10 text-{{ $c }}-600 dark:text-{{ $c }}-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $c }}-500"></span>
                                            {{ $item->statusLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-500">
                                        @if($item->source === 'intune')
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-400">
                                                @svg('heroicon-o-cloud', 'w-3 h-3')
                                                Intune
                                            </span>
                                        @else
                                            <span class="text-gray-400">Manuell</span>
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
        </div>
    </div>
</x-ui-page>
