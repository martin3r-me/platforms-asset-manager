<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kostenaufteilung', 'icon' => 'table-cells'],
        ]">
            <x-slot name="actions">
                <div class="inline-flex rounded-lg bg-black/[0.04] dark:bg-white/[0.06] p-0.5">
                    <button wire:click="setPeriod('monthly')"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-all {{ $period === 'monthly' ? 'bg-white shadow text-violet-600' : 'text-gray-500 hover:text-gray-700' }}">
                        Monatlich
                    </button>
                    <button wire:click="setPeriod('quarterly')"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-all {{ $period === 'quarterly' ? 'bg-white shadow text-violet-600' : 'text-gray-500 hover:text-gray-700' }}">
                        Quartal
                    </button>
                </div>
                <button wire:click="exportCsv"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all ml-2">
                    @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                    CSV-Export
                </button>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-8">

            <section class="space-y-3">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-[var(--ui-secondary)]">
                        Kostenaufteilung — Kostenstelle × Kostenart ({{ $period === 'quarterly' ? 'Quartal' : 'Monatlich' }})
                    </h2>
                    <span class="text-sm font-semibold text-violet-700 tabular-nums">
                        Gesamt: {{ number_format($pivot['grandTotal'], 2, ',', '.') }} €
                    </span>
                </div>
                <p class="text-[11px] text-[var(--ui-muted)]">
                    MS-Lizenzen &amp; Hardware-AfA stammen aus dem Graph-Sync bzw. Inventar; alle übrigen Kostenarten aus den erfassten Kostenpositionen.
                </p>
                @include('asset-manager::livewire.costs._pivot-table', ['pivot' => $pivot])
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Nach Kostenart --}}
                <section class="space-y-3">
                    <h2 class="text-sm font-semibold text-[var(--ui-secondary)]">Nach Kostenart</h2>
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                        @if($byCostType->isEmpty())
                            <div class="p-6 text-center text-sm text-gray-400">Keine Daten.</div>
                        @else
                            <div class="p-5 space-y-3">
                                @php $maxT = max($byCostType->max('monthly'), 0.01); @endphp
                                @foreach($byCostType as $row)
                                    <div>
                                        <div class="flex items-baseline justify-between text-xs mb-1">
                                            <span class="text-[var(--ui-secondary)] font-medium">{{ $row['label'] }}</span>
                                            <span class="text-[var(--ui-secondary)] tabular-nums font-semibold">{{ number_format($row['monthly'], 2, ',', '.') }} €</span>
                                        </div>
                                        <div class="w-full h-2 rounded-full bg-[var(--ui-muted-10)] overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500" style="width: {{ round(max($row['monthly'],0) / $maxT * 100) }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Nach Kreditor --}}
                <section class="space-y-3">
                    <h2 class="text-sm font-semibold text-[var(--ui-secondary)]">Nach Kreditor</h2>
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                        @if($byVendor->isEmpty())
                            <div class="p-6 text-center text-sm text-gray-400">Keine Kostenpositionen mit Kreditor.</div>
                        @else
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-black/[0.03]">
                                    @foreach($byVendor as $row)
                                        <tr class="hover:bg-black/[0.02]">
                                            <td class="px-5 py-2.5 font-medium text-gray-800">{{ $row['label'] }}</td>
                                            <td class="px-5 py-2.5 text-right text-xs text-gray-400">{{ $row['count'] }} Pos.</td>
                                            <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-violet-700">{{ number_format($row['monthly'], 2, ',', '.') }} €</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </section>
            </div>

        </div>
    </div>
</x-ui-page>
