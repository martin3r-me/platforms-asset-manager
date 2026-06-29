<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kostenaufteilung', 'icon' => 'table-cells'],
        ]">
            <x-slot name="actions">
                <div class="inline-flex rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-0.5">
                    <button wire:click="setPeriod('monthly')"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-all {{ $period === 'monthly' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm' : 'text-[var(--am-text-secondary)] hover:text-[var(--am-text)]' }}">
                        Monatlich
                    </button>
                    <button wire:click="setPeriod('quarterly')"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-all {{ $period === 'quarterly' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm' : 'text-[var(--am-text-secondary)] hover:text-[var(--am-text)]' }}">
                        Quartal
                    </button>
                </div>
                <x-asset-manager-button variant="secondary" size="sm" wire:click="exportCsv" class="ml-2">
                    @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                    CSV-Export
                </x-asset-manager-button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-8">

            <section class="space-y-3">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-[var(--am-text-secondary)]">
                        Kostenaufteilung — Kostenstelle × Kostenart ({{ $period === 'quarterly' ? 'Quartal' : 'Monatlich' }})
                    </h2>
                    <span class="text-sm font-semibold text-[var(--am-accent)] tabular-nums">
                        Gesamt: {{ number_format($pivot['grandTotal'], 2, ',', '.') }} €
                    </span>
                </div>
                <p class="text-[11px] text-[var(--am-text-muted)]">
                    MS-Lizenzen &amp; Hardware-AfA stammen aus dem Graph-Sync bzw. Inventar; alle übrigen Kostenarten aus den erfassten Kostenpositionen.
                </p>
                @include('asset-manager::livewire.costs._pivot-table', ['pivot' => $pivot])
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Nach Kostenart --}}
                <section class="space-y-3">
                    <h2 class="text-sm font-semibold text-[var(--am-text-secondary)]">Nach Kostenart</h2>
                    <x-asset-manager-panel body-class="p-0">
                        @if($byCostType->isEmpty())
                            <div class="p-6 text-center text-sm text-[var(--am-text-secondary)]">Keine Daten.</div>
                        @else
                            <div class="p-5 space-y-3">
                                @php $maxT = max($byCostType->max('monthly'), 0.01); @endphp
                                @foreach($byCostType as $row)
                                    <div>
                                        <div class="flex items-baseline justify-between text-xs mb-1">
                                            <span class="text-[var(--am-text-secondary)] font-medium">{{ $row['label'] }}</span>
                                            <span class="text-[var(--am-text-secondary)] tabular-nums font-semibold">{{ number_format($row['monthly'], 2, ',', '.') }} €</span>
                                        </div>
                                        <div class="w-full h-2 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                            <div class="h-full bg-[var(--am-accent)]" style="width: {{ round(max($row['monthly'],0) / $maxT * 100) }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-asset-manager-panel>
                </section>

                {{-- Nach Kreditor --}}
                <section class="space-y-3">
                    <h2 class="text-sm font-semibold text-[var(--am-text-secondary)]">Nach Kreditor</h2>
                    <x-asset-manager-panel body-class="p-0">
                        @if($byVendor->isEmpty())
                            <div class="p-6 text-center text-sm text-[var(--am-text-secondary)]">Keine Kostenpositionen mit Kreditor.</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-[color:var(--am-border)]">
                                        @foreach($byVendor as $row)
                                            <tr class="hover:bg-[var(--am-bg)]">
                                                <td class="px-5 py-2.5 font-medium text-[var(--am-text)]">{{ $row['label'] }}</td>
                                                <td class="px-5 py-2.5 text-right text-xs text-[var(--am-text-secondary)]">{{ $row['count'] }} Pos.</td>
                                                <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-[var(--am-accent)]">{{ number_format($row['monthly'], 2, ',', '.') }} €</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-asset-manager-panel>
                </section>
            </div>

        </div>
    </div>
</x-ui-page>
