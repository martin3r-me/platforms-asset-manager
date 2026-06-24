<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Mitarbeiter', 'icon' => 'users'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Name, E-Mail, UPN..."
                            class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Lizenz</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <select wire:model.live="filterSku" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle SKUs</option>
                            @foreach($skus as $sku)
                                <option value="{{ $sku->sku_id }}">{{ $sku->display_name ?? $sku->sku_part_number }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)]">
                            <input type="checkbox" wire:model.live="filterHasLicense" class="rounded border-[var(--ui-border)]/40" />
                            Hat mindestens eine Lizenz
                        </label>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Zuweisungen</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <label class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)]">
                            <input type="checkbox" wire:model.live="filterHasDevice" class="rounded border-[var(--ui-border)]/40" />
                            Hat Intune-Gerät
                        </label>
                        <label class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)]">
                            <input type="checkbox" wire:model.live="filterHasAsset" class="rounded border-[var(--ui-border)]/40" />
                            Hat manuelles Asset
                        </label>
                    </div>
                </section>

                @if($departments->count() > 0)
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Abteilung</h3>
                        <div class="px-3 pb-3">
                            <select wire:model.live="filterDept" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="">Alle</option>
                                @foreach($departments as $d)
                                    <option value="{{ $d }}">{{ $d }}</option>
                                @endforeach
                            </select>
                        </div>
                    </section>
                @endif

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Quelle</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterSource" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="graph">Aus Graph</option>
                            <option value="derived">Aus UPN abgeleitet</option>
                            <option value="manual">Manuell</option>
                        </select>
                    </div>
                </section>

                @if($costCenters->count() > 0)
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kostenstelle</h3>
                        <div class="px-3 pb-3">
                            <select wire:model.live="filterCostCenter" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="">Alle</option>
                                @foreach($costCenters as $cc)
                                    <option value="{{ $cc->id }}">{{ $cc->name ? $cc->code . ' — ' . $cc->name : $cc->code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </section>
                @endif

                @if($preset !== 'active' || $search || $filterDept || $filterSku || $filterSource || $filterCostCenter || $filterHasLicense || $filterHasDevice || $filterHasAsset)
                    <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" class="w-full" wire:click="resetFilters">
                        @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 mr-1')
                        Alle Filter zurücksetzen
                    </x-ui-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Zusammenfassungs-Panel zum gewählten Mitarbeiter --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Mitarbeiter" icon="heroicon-o-user" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                @if($selectedEmployee)
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Mitarbeiter</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    {{-- Identität --}}
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-[color:var(--ui-primary-10)] text-[color:var(--ui-primary)] flex items-center justify-center text-xs font-semibold flex-shrink-0">
                                {{ $selectedEmployee->initials() }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-[var(--ui-secondary)] truncate">{{ $selectedEmployee->name }}</div>
                                <div class="text-[10px] text-[var(--ui-muted)] truncate">{{ $selectedEmployee->user_principal_name ?: '—' }}</div>
                            </div>
                        </div>
                        @if($selectedEmployee->department || $selectedEmployee->job_title)
                            <div class="mt-2 text-[10px] text-[var(--ui-muted)]">
                                {{ $selectedEmployee->job_title }}@if($selectedEmployee->department && $selectedEmployee->job_title) · @endif{{ $selectedEmployee->department }}
                            </div>
                        @endif
                    </section>

                    {{-- Meta --}}
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                            <div class="flex items-baseline justify-between gap-2 py-1.5 px-3">
                                <dt class="text-[var(--ui-muted)] flex-shrink-0">Abteilung</dt>
                                <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[60%] text-right">{{ $selectedEmployee->department ?: '—' }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-2 py-1.5 px-3">
                                <dt class="text-[var(--ui-muted)] flex-shrink-0">Kostenstelle</dt>
                                <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[60%] text-right">{{ $selectedEmployee->cost_center ?: '—' }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-2 py-1.5 px-3">
                                <dt class="text-[var(--ui-muted)] flex-shrink-0">Quelle</dt>
                                <dd class="m-0 text-right">
                                    @if($selectedEmployee->source === 'graph')
                                        <x-asset-manager-badge color="violet" size="xs">Graph</x-asset-manager-badge>
                                    @elseif($selectedEmployee->source === 'manual')
                                        <x-asset-manager-badge color="amber" size="xs">Manuell</x-asset-manager-badge>
                                    @else
                                        <x-asset-manager-badge color="gray" size="xs">Abgeleitet</x-asset-manager-badge>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-2 py-1.5 px-3">
                                <dt class="text-[var(--ui-muted)] flex-shrink-0">Status</dt>
                                <dd class="m-0 text-right">
                                    @if($selectedEmployee->is_active)
                                        <x-asset-manager-badge color="emerald" size="xs">Aktiv</x-asset-manager-badge>
                                    @else
                                        <x-asset-manager-badge color="red" size="xs">Inaktiv</x-asset-manager-badge>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {{-- Zähler + Monatskosten --}}
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-2.5 text-center">
                            <div class="text-lg font-semibold tabular-nums text-[var(--ui-secondary)]">{{ $selDeviceCount }}</div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Geräte</div>
                        </div>
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-2.5 text-center">
                            <div class="text-lg font-semibold tabular-nums text-[var(--ui-secondary)]">{{ $selAssetCount }}</div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Assets</div>
                        </div>
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-2.5 text-center">
                            <div class="text-lg font-semibold tabular-nums text-[var(--ui-secondary)]">{{ $selLicenseCount }}</div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Lizenzen</div>
                        </div>
                        <div class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-2.5 text-center">
                            <div class="text-lg font-semibold tabular-nums text-[color:var(--ui-primary)]">{{ number_format($selectedCost['total'], 2, ',', '.') }} €</div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">pro Monat</div>
                        </div>
                    </div>

                    <x-ui-button variant="primary" size="md" rounded="lg" href="{{ route('asset-manager.employees.show', $selectedEmployee) }}" wire:navigate class="w-full">
                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5')
                        Vollständiges Profil
                    </x-ui-button>
                @else
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[color:var(--ui-muted)] mb-3')
                        <p class="text-[11px] text-[var(--ui-muted)] mb-1">Wähle einen Mitarbeiter aus der Tabelle.</p>
                        <p class="text-[10px] text-[color:var(--ui-secondary)]">Zeigt Zusammenfassung & Monatskosten.</p>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Öffnet die kollabierte rechte Sidebar, wenn selectEmployee() 'open-activity' dispatcht --}}
    <div x-data x-on:open-activity.window="$store.ui && $store.ui.mSet('activity', 'open', true)"></div>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- QUICK-FILTER CHIPS --}}
            @php
                $chips = [
                    ['key' => 'active',       'label' => 'Aktive Nutzer',   'count' => $counts['active'],       'color' => 'emerald', 'icon' => 'heroicon-o-check-circle',     'hint' => 'mit Lizenz, Gerät oder Asset'],
                    ['key' => 'with_license', 'label' => 'Mit Lizenz',      'count' => $counts['with_license'], 'color' => 'violet',  'icon' => 'heroicon-o-key',              'hint' => 'mindestens eine M365-Lizenz'],
                    ['key' => 'with_device',  'label' => 'Mit Gerät',       'count' => $counts['with_device'],  'color' => 'sky',     'icon' => 'heroicon-o-computer-desktop', 'hint' => 'mindestens ein Intune-Gerät'],
                    ['key' => 'with_asset',   'label' => 'Mit Asset',       'count' => $counts['with_asset'],   'color' => 'indigo',  'icon' => 'heroicon-o-cube-transparent', 'hint' => 'mindestens ein manuelles Asset'],
                    ['key' => 'unassigned',   'label' => 'Ohne alles',      'count' => $counts['unassigned'],   'color' => 'amber',   'icon' => 'heroicon-o-exclamation-triangle', 'hint' => 'Karteileichen — keine Zuweisung'],
                    ['key' => 'inactive',     'label' => 'Inaktiv',         'count' => $counts['inactive'],     'color' => 'red',     'icon' => 'heroicon-o-user-minus',       'hint' => 'is_active = false'],
                    ['key' => 'all',          'label' => 'Alle',            'count' => $counts['all'],          'color' => 'gray',    'icon' => 'heroicon-o-list-bullet',      'hint' => 'jeder Eintrag in der Tabelle'],
                ];
            @endphp

            <div class="flex flex-wrap gap-2">
                @foreach($chips as $chip)
                    @php $active = $preset === $chip['key']; @endphp
                    <button wire:click="setPreset('{{ $chip['key'] }}')"
                            title="{{ $chip['hint'] }}"
                            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium border transition-all
                                {{ $active
                                    ? 'bg-' . $chip['color'] . '-500 text-white border-' . $chip['color'] . '-500 shadow-sm'
                                    : 'bg-white dark:bg-white/5 text-gray-600 dark:text-gray-400 border-black/10 dark:border-white/10 hover:border-' . $chip['color'] . '-500/50 hover:text-' . $chip['color'] . '-600' }}">
                        @svg($chip['icon'], 'w-3.5 h-3.5')
                        {{ $chip['label'] }}
                        <span class="tabular-nums {{ $active ? 'text-white/80' : 'text-[color:var(--ui-secondary)]' }}">{{ $chip['count'] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Aktive Sidebar-Filter als Badges --}}
            @if($search || $filterDept || $filterSku || $filterSource || $filterCostCenter || $filterHasLicense || $filterHasDevice || $filterHasAsset)
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-[color:var(--ui-secondary)]">Zusatzfilter:</span>
                    @if($search) <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">"{{ Str::limit($search, 30) }}" <button wire:click="$set('search', '')">×</button></span> @endif
                    @if($filterSku)
                        @php $skuLabel = optional($skus->firstWhere('sku_id', $filterSku))->display_name ?? $filterSku; @endphp
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">SKU: {{ Str::limit($skuLabel, 25) }} <button wire:click="$set('filterSku', '')">×</button></span>
                    @endif
                    @if($filterHasLicense) <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">Hat Lizenz <button wire:click="$set('filterHasLicense', false)">×</button></span> @endif
                    @if($filterHasDevice)  <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">Hat Gerät <button wire:click="$set('filterHasDevice', false)">×</button></span> @endif
                    @if($filterHasAsset)   <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">Hat Asset <button wire:click="$set('filterHasAsset', false)">×</button></span> @endif
                    @if($filterDept)       <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">{{ $filterDept }} <button wire:click="$set('filterDept', '')">×</button></span> @endif
                    @if($filterSource)     <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">{{ $filterSource }} <button wire:click="$set('filterSource', '')">×</button></span> @endif
                    @if($filterCostCenter)
                        @php $ccLabel = optional($costCenters->firstWhere('id', (int) $filterCostCenter))->code ?? $filterCostCenter; @endphp
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">KSt: {{ $ccLabel }} <button wire:click="$set('filterCostCenter', '')">×</button></span>
                    @endif
                </div>
            @endif

            {{-- Tabelle --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                @if($employees->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-users', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
                        <p class="text-sm text-[color:var(--ui-secondary)]">Keine Mitarbeiter für diese Filter.</p>
                        <button wire:click="resetFilters" class="mt-2 text-xs text-violet-500 hover:underline">Filter zurücksetzen</button>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--ui-muted)]">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">
                                    <button wire:click="sortBy('display_name')" class="flex items-center gap-1 hover:text-gray-600">
                                        Name
                                        @if($sortField === 'display_name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Abteilung</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Kostenstelle</th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Geräte</th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Assets</th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Lizenzen</th>
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Quelle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--ui-muted)]">
                            @foreach($employees as $emp)
                                {{-- Klick auf die Zeile öffnet das Panel; Klicks aus dem Namens-Link (wire:navigate)
                                     werden ignoriert, damit der Name weiterhin direkt zum Profil navigiert. Kein
                                     stopPropagation am Link — das würde wire:navigate (document-delegiert) abwürgen. --}}
                                <tr wire:key="emp-{{ $emp->id }}"
                                    x-data
                                    @click="if (! $event.target.closest('a')) $wire.selectEmployee({{ $emp->id }})"
                                    class="cursor-pointer transition-colors {{ $selectedId === $emp->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : 'hover:bg-[color:var(--ui-muted-10)]' }}">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('asset-manager.employees.show', $emp) }}" wire:navigate class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-full bg-[color:var(--ui-primary-10)] text-[color:var(--ui-primary)] flex items-center justify-center text-[10px] font-semibold flex-shrink-0">
                                                {{ $emp->initials() }}
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600">{{ $emp->name }}</div>
                                                <div class="text-xs text-[color:var(--ui-secondary)] truncate max-w-[240px]">{{ $emp->user_principal_name }}</div>
                                            </div>
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-[color:var(--ui-secondary)]">{{ $emp->department ?? '—' }}</td>
                                    <td class="px-5 py-3 text-xs text-[color:var(--ui-secondary)]">{{ $emp->cost_center ?: '—' }}</td>
                                    <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $deviceCounts[$emp->user_principal_name] ?? 0 }}</td>
                                    <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $itemCounts[$emp->id] ?? 0 }}</td>
                                    <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $licenseCounts[$emp->user_principal_name] ?? 0 }}</td>
                                    <td class="px-5 py-3">
                                        @if($emp->source === 'graph')
                                            <x-asset-manager-badge color="violet" size="xs">Graph</x-asset-manager-badge>
                                        @elseif($emp->source === 'manual')
                                            <x-asset-manager-badge color="amber" size="xs">Manuell</x-asset-manager-badge>
                                        @else
                                            <x-asset-manager-badge color="gray" size="xs">Abgeleitet</x-asset-manager-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($employees->hasPages())
                        <div class="px-5 py-3 border-t border-[color:var(--ui-muted)]">
                            {{ $employees->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
