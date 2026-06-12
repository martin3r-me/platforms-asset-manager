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
                    <div class="px-3 py-2">
                        <label class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)]">
                            <input type="checkbox" wire:model.live="onlyActive" class="rounded border-[var(--ui-border)]/40" />
                            Nur aktive Mitarbeiter
                        </label>
                    </div>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-gray-400">Mitarbeiter gesamt</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['active'] }}</div>
                    <div class="text-xs text-gray-400">Aktiv</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-violet-600 dark:text-violet-400">{{ $stats['fromGraph'] }}</div>
                    <div class="text-xs text-gray-400">Aus Graph</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-500 dark:text-gray-400">{{ $stats['derived'] }}</div>
                    <div class="text-xs text-gray-400">Aus UPNs abgeleitet</div>
                </div>
            </div>

            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                @if($employees->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-users', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">Keine Mitarbeiter gefunden.</p>
                        <p class="text-xs text-gray-400 mt-1">Mitarbeiter werden automatisch beim Sync angelegt.</p>
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
