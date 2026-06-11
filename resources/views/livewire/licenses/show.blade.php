<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lizenzen', 'href' => route('asset-manager.licenses.index'), 'icon' => 'key'],
            ['label' => $sku->display_name ?? $sku->sku_part_number],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-5">

            {{-- Header-Karte --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent"></div>
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 flex items-center justify-center">
                        @svg('heroicon-o-key', 'w-6 h-6 text-violet-500')
                    </div>
                    <div class="flex-1 min-w-0">
                        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ $sku->display_name ?? $sku->sku_part_number }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sku->sku_part_number }}</p>
                    </div>
                    <div class="flex-shrink-0 text-right">
                        @php $pct = $sku->utilizationPercent(); $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                        <div class="text-2xl font-semibold text-{{ $color }}-600 dark:text-{{ $color }}-400">{{ $pct }}%</div>
                        <div class="text-xs text-gray-400">{{ $sku->consumed_units }}/{{ $sku->purchased_units }} genutzt</div>
                    </div>
                </div>
            </div>

            {{-- Suche --}}
            <div class="relative max-w-sm">
                <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                    @svg('heroicon-o-magnifying-glass', 'w-4 h-4 text-gray-400')
                </div>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Name oder E-Mail suchen..."
                    class="w-full pl-9 pr-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all"
                />
            </div>

            {{-- Tabelle --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>

                @if($assignments->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-users', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">
                            {{ $search ? 'Keine Nutzer gefunden.' : 'Keine Lizenzzuweisungen gefunden.' }}
                        </p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 dark:border-white/5">
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Name</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">E-Mail</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Zugewiesen seit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($assignments as $assignment)
                                <tr class="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ $assignment->display_name ?? '—' }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-gray-500 dark:text-gray-400">
                                        {{ $assignment->user_principal_name }}
                                    </td>
                                    <td class="px-5 py-3 text-gray-500 dark:text-gray-400">
                                        {{ $assignment->assigned_at ? $assignment->assigned_at->format('d.m.Y') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($assignments->hasPages())
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                            {{ $assignments->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
