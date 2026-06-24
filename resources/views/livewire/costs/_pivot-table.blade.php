{{-- Pivot Kostenstelle × Kostenart. Erwartet: $pivot (aus CostAggregationService::costCenterByType) --}}
@php $types = $pivot['types']; @endphp

<div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
    @if(empty($types) || empty($pivot['companies']))
        <div class="p-8 text-center text-sm text-[color:var(--ui-secondary)]">
            Noch keine Kostenpositionen erfasst. Importiere die Excel
            (<code class="text-xs">asset-manager:import-costs</code>) oder pflege Positionen manuell.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="text-xs border-collapse">
                <thead>
                    <tr class="border-b border-[color:var(--ui-muted)] bg-[color:var(--ui-muted-10)]">
                        <th class="sticky left-0 z-10 bg-[color:var(--ui-muted-10)] text-left px-3 py-2 font-semibold text-[color:var(--ui-body-color)] min-w-[180px] border-r border-[color:var(--ui-muted)]">Kostenstelle</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-2 font-semibold text-[color:var(--ui-secondary)] whitespace-nowrap min-w-[90px]">{{ $t['name'] }}</th>
                        @endforeach
                        <th class="text-right px-3 py-2 font-semibold text-[color:var(--ui-body-color)] whitespace-nowrap bg-[color:var(--ui-primary-5)]">Summe</th>
                    </tr>
                    {{-- Metazeilen: Kreditor / System / Frequenz --}}
                    <tr class="text-[10px] text-[color:var(--ui-secondary)] border-b border-[color:var(--ui-muted)]">
                        <th class="sticky left-0 z-10 bg-white text-left px-3 py-1 font-normal italic border-r border-[color:var(--ui-muted)]">Kreditor</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-1 font-normal whitespace-nowrap">{{ $pivot['meta'][$t['id']]['vendor'] ?? '—' }}</th>
                        @endforeach
                        <th class="bg-[color:var(--ui-primary-5)]"></th>
                    </tr>
                    <tr class="text-[10px] text-[color:var(--ui-secondary)] border-b border-[color:var(--ui-muted)]">
                        <th class="sticky left-0 z-10 bg-white text-left px-3 py-1 font-normal italic border-r border-[color:var(--ui-muted)]">System · Frequenz</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-1 font-normal whitespace-nowrap">{{ $pivot['meta'][$t['id']]['system'] ?? '—' }} · {{ $pivot['meta'][$t['id']]['frequency'] ?? '' }}</th>
                        @endforeach
                        <th class="bg-[color:var(--ui-primary-5)]"></th>
                    </tr>
                </thead>
                @foreach($pivot['companies'] as $company)
                <tbody class="border-t-2 border-[color:var(--ui-muted)]">
                        <tr class="bg-[color:var(--ui-muted-20)]">
                            <td class="sticky left-0 z-10 bg-[color:var(--ui-muted-20)] px-3 py-2 font-semibold text-[color:var(--ui-body-color)]" colspan="{{ count($types) + 2 }}">
                                {{ $company['name'] }}
                                <span class="text-[color:var(--ui-secondary)] font-normal ml-2 tabular-nums">{{ number_format($company['subtotal'], 2, ',', '.') }} €</span>
                            </td>
                        </tr>
                        @foreach($company['rows'] as $row)
                            <tr class="border-b border-[color:var(--ui-border)] even:bg-[color:var(--ui-muted-5)] hover:bg-[color:var(--ui-muted-10)]">
                                <td class="sticky left-0 z-10 bg-white px-3 py-2 font-medium text-[color:var(--ui-body-color)] whitespace-nowrap border-r border-[color:var(--ui-muted)]">
                                    {{ $row['code'] }}@if($row['name'])<span class="text-[color:var(--ui-secondary)] font-normal ml-1">{{ $row['name'] }}</span>@endif
                                </td>
                                @foreach($types as $t)
                                    @php $v = $row['cells'][$t['id']] ?? 0; @endphp
                                    <td class="text-right px-3 py-2 tabular-nums {{ $v == 0 ? 'text-[color:var(--ui-muted)]' : ($v < 0 ? 'text-red-700' : 'text-[color:var(--ui-body-color)]') }}">
                                        {{ $v == 0 ? '–' : number_format($v, 2, ',', '.') }}
                                    </td>
                                @endforeach
                                <td class="text-right px-3 py-2 font-semibold tabular-nums text-[color:var(--ui-primary)] bg-[color:var(--ui-primary-5)]">{{ number_format($row['rowTotal'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                </tbody>
                @endforeach
                <tfoot>
                    <tr class="border-t-2 border-[color:var(--ui-muted)] bg-[color:var(--ui-muted-10)] font-semibold">
                        <td class="sticky left-0 z-10 bg-[color:var(--ui-muted-10)] px-3 py-2 text-[color:var(--ui-body-color)] border-r border-[color:var(--ui-muted)]">Summe</td>
                        @foreach($types as $t)
                            <td class="text-right px-3 py-2 tabular-nums text-[color:var(--ui-body-color)]">{{ number_format($pivot['colTotals'][$t['id']] ?? 0, 2, ',', '.') }}</td>
                        @endforeach
                        <td class="text-right px-3 py-2 tabular-nums text-[color:var(--ui-primary)] bg-[color:var(--ui-primary-10)]">{{ number_format($pivot['grandTotal'], 2, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
