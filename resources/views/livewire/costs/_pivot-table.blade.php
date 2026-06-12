{{-- Pivot Kostenstelle × Kostenart. Erwartet: $pivot (aus CostAggregationService::costCenterByType) --}}
@php $types = $pivot['types']; @endphp

<div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
    @if(empty($types) || empty($pivot['companies']))
        <div class="p-8 text-center text-sm text-gray-400">
            Noch keine Kostenpositionen erfasst. Importiere die Excel
            (<code class="text-xs">asset-manager:import-costs</code>) oder pflege Positionen manuell.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="text-xs border-collapse">
                <thead>
                    <tr class="border-b border-black/10 dark:border-white/10 bg-[var(--ui-muted-5)]">
                        <th class="sticky left-0 z-10 bg-[var(--ui-muted-5)] text-left px-3 py-2 font-semibold text-[var(--ui-secondary)] min-w-[180px]">Kostenstelle</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-2 font-medium text-gray-500 whitespace-nowrap min-w-[90px]">{{ $t['name'] }}</th>
                        @endforeach
                        <th class="text-right px-3 py-2 font-semibold text-[var(--ui-secondary)] whitespace-nowrap bg-violet-500/5">Summe</th>
                    </tr>
                    {{-- Metazeilen: Kreditor / System / Frequenz --}}
                    <tr class="text-[10px] text-gray-400 border-b border-black/5">
                        <th class="sticky left-0 z-10 bg-white text-left px-3 py-1 font-normal italic">Kreditor</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-1 font-normal whitespace-nowrap">{{ $pivot['meta'][$t['id']]['vendor'] ?? '—' }}</th>
                        @endforeach
                        <th class="bg-violet-500/5"></th>
                    </tr>
                    <tr class="text-[10px] text-gray-400 border-b border-black/10">
                        <th class="sticky left-0 z-10 bg-white text-left px-3 py-1 font-normal italic">System · Frequenz</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-1 font-normal whitespace-nowrap">{{ $pivot['meta'][$t['id']]['system'] ?? '—' }} · {{ $pivot['meta'][$t['id']]['frequency'] ?? '' }}</th>
                        @endforeach
                        <th class="bg-violet-500/5"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pivot['companies'] as $company)
                        <tr class="bg-[var(--ui-muted-10)]">
                            <td class="sticky left-0 z-10 bg-[var(--ui-muted-10)] px-3 py-1.5 font-semibold text-[var(--ui-secondary)]" colspan="{{ count($types) + 2 }}">
                                {{ $company['name'] }}
                                <span class="text-gray-400 font-normal ml-2 tabular-nums">{{ number_format($company['subtotal'], 2, ',', '.') }} €</span>
                            </td>
                        </tr>
                        @foreach($company['rows'] as $row)
                            <tr class="border-b border-black/[0.03] hover:bg-black/[0.02]">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5 font-medium text-gray-800 whitespace-nowrap">
                                    {{ $row['code'] }}@if($row['name'])<span class="text-gray-400 font-normal ml-1">{{ $row['name'] }}</span>@endif
                                </td>
                                @foreach($types as $t)
                                    @php $v = $row['cells'][$t['id']] ?? 0; @endphp
                                    <td class="text-right px-3 py-1.5 tabular-nums {{ $v == 0 ? 'text-gray-300' : ($v < 0 ? 'text-red-500' : 'text-gray-700') }}">
                                        {{ $v == 0 ? '–' : number_format($v, 2, ',', '.') }}
                                    </td>
                                @endforeach
                                <td class="text-right px-3 py-1.5 font-semibold tabular-nums text-violet-700 bg-violet-500/5">{{ number_format($row['rowTotal'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-black/10 bg-[var(--ui-muted-5)] font-semibold">
                        <td class="sticky left-0 z-10 bg-[var(--ui-muted-5)] px-3 py-2 text-[var(--ui-secondary)]">Summe</td>
                        @foreach($types as $t)
                            <td class="text-right px-3 py-2 tabular-nums text-[var(--ui-secondary)]">{{ number_format($pivot['colTotals'][$t['id']] ?? 0, 2, ',', '.') }}</td>
                        @endforeach
                        <td class="text-right px-3 py-2 tabular-nums text-violet-700 bg-violet-500/10">{{ number_format($pivot['grandTotal'], 2, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
