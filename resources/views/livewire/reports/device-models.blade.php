<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Auswertungen', 'icon' => 'chart-bar'],
            ['label' => 'Geräte nach Modell', 'icon' => 'cpu-chip'],
        ]">
            <x-slot name="actions">
                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" href="{{ route('asset-manager.device-models.index') }}" wire:navigate>
                    @svg('heroicon-o-cpu-chip', 'w-3.5 h-3.5')
                    Modell-Preise pflegen
                </x-ui-button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Summary --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $summary['devices'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">Geräte gesamt</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-violet-600 dark:text-violet-400">{{ $summary['models'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">Verschiedene Modelle</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold {{ $summary['withoutCost'] > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-gray-500 dark:text-[color:var(--ui-secondary)]' }}">{{ $summary['withoutCost'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">Modelle ohne Kostenbasis</div>
                </div>
            </div>

            {{-- Callout: Modelle ohne Preis fallen aus der Kostenrechnung --}}
            @if($summary['withoutCost'] > 0)
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/20">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5')
                    <div class="text-sm text-amber-700 dark:text-amber-400">
                        {{ $summary['withoutCost'] }} Modell(e) haben keine hinterlegten Kosten — deren Geräte fallen still aus der Kostenrechnung.
                        <a href="{{ route('asset-manager.device-models.index') }}" wire:navigate class="font-medium underline hover:text-amber-900">Modell-Preise pflegen</a>.
                    </div>
                </div>
            @endif

            {{-- Tabelle --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                @if($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-cpu-chip', 'w-10 h-10 text-[color:var(--ui-muted)] dark:text-gray-600 mb-3')
                        <p class="text-sm text-[color:var(--ui-secondary)]">Noch keine Intune-Geräte synchronisiert.</p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--ui-muted)]">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">
                                    <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-gray-600">
                                        Hersteller / Modell
                                        @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">
                                    <button wire:click="sortBy('count')" class="flex items-center gap-1 hover:text-gray-600 ml-auto">
                                        Anzahl
                                        @if($sortField === 'count') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">Zugewiesen</th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] bg-[color:var(--ui-muted-10)]">
                                    <button wire:click="sortBy('monthly')" class="flex items-center gap-1 hover:text-gray-600 ml-auto"
                                            title="AfA/Leasing je Objekt summiert — keine Kostenstellen-Zuteilung">
                                        Σ Monatskosten
                                        @if($sortField === 'monthly') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--ui-muted)]">
                            @foreach($rows as $g)
                                <tr wire:key="m-{{ $loop->index }}" class="hover:bg-[color:var(--ui-muted-10)] transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $g['model'] }}</div>
                                        <div class="text-xs text-[color:var(--ui-secondary)]">{{ $g['manufacturer'] }}</div>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-700 dark:text-[color:var(--ui-muted)]">{{ $g['count'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-500 dark:text-[color:var(--ui-secondary)]">{{ $g['assigned'] }} / {{ $g['count'] }}</td>
                                    <td class="px-5 py-3 text-right">
                                        @if($g['monthly'] > 0)
                                            <span class="tabular-nums text-gray-700 dark:text-[color:var(--ui-muted)]">{{ number_format($g['monthly'], 2, ',', '.') }} €</span>
                                        @else
                                            <x-asset-manager-badge color="amber" size="sm" icon="heroicon-o-exclamation-triangle">kein Preis</x-asset-manager-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <p class="text-[11px] text-[color:var(--ui-secondary)] px-1">
                Σ Monatskosten = AfA/Leasing je Gerät summiert (Override bzw. Modell-Default) — nicht die kostenstellen-zugeteilte Summe der Kostenaufteilung.
            </p>
        </div>
    </div>
</x-ui-page>
