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
                <x-asset-manager-button variant="ghost" size="sm" href="{{ route('asset-manager.device-models.index') }}" wire:navigate>
                    @svg('heroicon-o-cpu-chip', 'w-3.5 h-3.5')
                    Modell-Preise pflegen
                </x-asset-manager-button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Summary --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <x-asset-manager-stat-card
                    label="Geräte gesamt"
                    :value="$summary['devices']"
                    icon="heroicon-o-cpu-chip"
                    accent="navy" />
                <x-asset-manager-stat-card
                    label="Verschiedene Modelle"
                    :value="$summary['models']"
                    icon="heroicon-o-squares-2x2"
                    accent="violet" />
                <x-asset-manager-stat-card
                    label="Modelle ohne Kostenbasis"
                    :value="$summary['withoutCost']"
                    icon="heroicon-o-exclamation-triangle"
                    :accent="$summary['withoutCost'] > 0 ? 'amber' : 'navy'"
                    :value-class="$summary['withoutCost'] > 0 ? 'text-amber-700' : 'text-[var(--am-text-muted)]'" />
            </div>

            {{-- Callout: Modelle ohne Preis fallen aus der Kostenrechnung --}}
            @if($summary['withoutCost'] > 0)
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5')
                    <div class="text-sm text-amber-700">
                        {{ $summary['withoutCost'] }} Modell(e) haben keine hinterlegten Kosten — deren Geräte fallen still aus der Kostenrechnung.
                        <a href="{{ route('asset-manager.device-models.index') }}" wire:navigate class="font-medium underline hover:text-amber-900">Modell-Preise pflegen</a>.
                    </div>
                </div>
            @endif

            {{-- Tabelle --}}
            <x-asset-manager-panel title="Geräte nach Modell" body-class="p-0">
                @if($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-cpu-chip', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                        <p class="text-sm text-[var(--am-text-secondary)]">Noch keine Intune-Geräte synchronisiert.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-[var(--am-bg)]">
                                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                        <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)]">
                                            Hersteller / Modell
                                            @if($sortField === 'name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                        </button>
                                    </th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                        <button wire:click="sortBy('count')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)] ml-auto">
                                            Anzahl
                                            @if($sortField === 'count') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                        </button>
                                    </th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Zugewiesen</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                        <button wire:click="sortBy('monthly')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)] ml-auto"
                                                title="AfA/Leasing je Objekt summiert — keine Kostenstellen-Zuteilung">
                                            Σ Monatskosten
                                            @if($sortField === 'monthly') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($rows as $g)
                                    <tr wire:key="m-{{ $loop->index }}" class="hover:bg-[var(--am-bg)] transition-colors">
                                        <td class="px-5 py-3">
                                            <div class="font-medium text-[var(--am-text)]">{{ $g['model'] }}</div>
                                            <div class="text-xs text-[var(--am-text-secondary)]">{{ $g['manufacturer'] }}</div>
                                        </td>
                                        <td class="px-5 py-3 text-right tabular-nums text-[var(--am-text-secondary)]">{{ $g['count'] }}</td>
                                        <td class="px-5 py-3 text-right tabular-nums text-[var(--am-text-muted)]">{{ $g['assigned'] }} / {{ $g['count'] }}</td>
                                        <td class="px-5 py-3 text-right">
                                            @if($g['monthly'] > 0)
                                                <span class="tabular-nums text-[var(--am-text-secondary)]">{{ number_format($g['monthly'], 2, ',', '.') }} €</span>
                                            @else
                                                <x-asset-manager-badge color="amber" size="sm" icon="heroicon-o-exclamation-triangle">kein Preis</x-asset-manager-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-asset-manager-panel>

            <p class="text-[11px] text-[var(--am-text-muted)] px-1">
                Σ Monatskosten = AfA/Leasing je Gerät summiert (Override bzw. Modell-Default) — nicht die kostenstellen-zugeteilte Summe der Kostenaufteilung.
            </p>
        </div>
    </div>
</x-ui-page>
