<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Mitarbeiter', 'icon' => 'users'],
        ]" />
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
                    <button wire:click="resetFilters"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10">
                        @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Alle Filter zurücksetzen
                    </button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

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
                        <span class="tabular-nums {{ $active ? 'text-white/80' : 'text-gray-400' }}">{{ $chip['count'] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Aktive Sidebar-Filter als Badges --}}
            @if($search || $filterDept || $filterSku || $filterSource || $filterCostCenter || $filterHasLicense || $filterHasDevice || $filterHasAsset)
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-400">Zusatzfilter:</span>
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
                        @svg('heroicon-o-users', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">Keine Mitarbeiter für diese Filter.</p>
                        <button wire:click="resetFilters" class="mt-2 text-xs text-violet-500 hover:underline">Filter zurücksetzen</button>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 dark:border-white/5">
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('display_name')" class="flex items-center gap-1 hover:text-gray-600">
                                        Name
                                        @if($sortField === 'display_name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Abteilung</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Kostenstelle</th>
                                <th class="text-right px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Geräte</th>
                                <th class="text-right px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Assets</th>
                                <th class="text-right px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Lizenzen</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Quelle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($employees as $emp)
                                <tr wire:key="emp-{{ $emp->id }}" class="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('asset-manager.employees.show', $emp) }}" wire:navigate class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-full bg-violet-500/10 text-violet-600 flex items-center justify-center text-[10px] font-semibold flex-shrink-0">
                                                {{ $emp->initials() }}
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600">{{ $emp->name }}</div>
                                                <div class="text-xs text-gray-400 truncate max-w-[240px]">{{ $emp->user_principal_name }}</div>
                                            </div>
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-500">{{ $emp->department ?? '—' }}</td>
                                    <td class="px-5 py-3">
                                        @if($canManage)
                                            <select wire:change="assignCostCenter({{ $emp->id }}, $event.target.value)"
                                                    class="text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 px-1.5 py-1 max-w-[170px] focus:outline-none focus:ring-2 focus:ring-violet-500/30">
                                                <option value="">—</option>
                                                @foreach($costCenters as $cc)
                                                    <option value="{{ $cc->id }}" @selected($emp->cost_center_id == $cc->id)>{{ $cc->name ? $cc->code . ' — ' . $cc->name : $cc->code }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="text-xs text-gray-500">{{ $emp->cost_center ?: '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $deviceCounts[$emp->user_principal_name] ?? 0 }}</td>
                                    <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $itemCounts[$emp->id] ?? 0 }}</td>
                                    <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $licenseCounts[$emp->user_principal_name] ?? 0 }}</td>
                                    <td class="px-5 py-3">
                                        @if($emp->source === 'graph')
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] rounded-full bg-violet-500/10 text-violet-600">Graph</span>
                                        @elseif($emp->source === 'manual')
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] rounded-full bg-amber-500/10 text-amber-600">Manuell</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] rounded-full bg-gray-500/10 text-gray-500">Abgeleitet</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($employees->hasPages())
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                            {{ $employees->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
