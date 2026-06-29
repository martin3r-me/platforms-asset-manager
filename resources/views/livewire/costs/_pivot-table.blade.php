{{-- Pivot Kostenstelle × Kostenart. Erwartet: $pivot (aus CostAggregationService::costCenterByType) --}}
@php $types = $pivot['types']; @endphp

<div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
    @if(empty($types) || empty($pivot['companies']))
        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
            Noch keine Kostenpositionen erfasst. Importiere die Excel
            (<code class="text-xs">asset-manager:import-costs</code>) oder pflege Positionen manuell.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="text-xs border-collapse">
                <thead>
                    <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                        <th class="sticky left-0 z-10 bg-[var(--am-bg)] text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] min-w-[180px] border-r border-[color:var(--am-border)]">Kostenstelle</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] whitespace-nowrap min-w-[90px]">{{ $t['name'] }}</th>
                        @endforeach
                        <th class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text)] whitespace-nowrap bg-[var(--am-accent-surface)]">Summe</th>
                    </tr>
                    {{-- Metazeilen: Kreditor / System / Frequenz --}}
                    <tr class="text-[10px] text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                        <th class="sticky left-0 z-10 bg-[var(--am-surface)] text-left px-3 py-1 font-normal italic border-r border-[color:var(--am-border)]">Kreditor</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-1 font-normal whitespace-nowrap">{{ $pivot['meta'][$t['id']]['vendor'] ?? '—' }}</th>
                        @endforeach
                        <th class="bg-[var(--am-accent-surface)]"></th>
                    </tr>
                    <tr class="text-[10px] text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                        <th class="sticky left-0 z-10 bg-[var(--am-surface)] text-left px-3 py-1 font-normal italic border-r border-[color:var(--am-border)]">System · Frequenz</th>
                        @foreach($types as $t)
                            <th class="text-right px-3 py-1 font-normal whitespace-nowrap">{{ $pivot['meta'][$t['id']]['system'] ?? '—' }} · {{ $pivot['meta'][$t['id']]['frequency'] ?? '' }}</th>
                        @endforeach
                        <th class="bg-[var(--am-accent-surface)]"></th>
                    </tr>
                </thead>
                @foreach($pivot['companies'] as $company)
                <tbody class="border-t-2 border-[color:var(--am-border)]">
                        <tr class="bg-[var(--am-bg)]">
                            <td class="sticky left-0 z-10 bg-[var(--am-bg)] px-3 py-2 font-semibold text-[var(--am-text)]" colspan="{{ count($types) + 2 }}">
                                {{ $company['name'] }}
                                <span class="text-[var(--am-text-secondary)] font-normal ml-2 tabular-nums">{{ number_format($company['subtotal'], 2, ',', '.') }} €</span>
                            </td>
                        </tr>
                        @foreach($company['rows'] as $row)
                            <tr class="border-b border-[color:var(--am-border)] hover:bg-[var(--am-bg)]">
                                <td class="sticky left-0 z-10 bg-[var(--am-surface)] px-3 py-2 font-medium text-[var(--am-text)] whitespace-nowrap border-r border-[color:var(--am-border)]">
                                    {{ $row['code'] }}@if($row['name'])<span class="text-[var(--am-text-secondary)] font-normal ml-1">{{ $row['name'] }}</span>@endif
                                </td>
                                @foreach($types as $t)
                                    @php $v = $row['cells'][$t['id']] ?? 0; @endphp
                                    <td class="text-right px-3 py-2 tabular-nums {{ $v == 0 ? 'text-[var(--am-text-muted)]' : ($v < 0 ? 'text-red-700' : 'text-[var(--am-text)]') }}">
                                        {{ $v == 0 ? '–' : number_format($v, 2, ',', '.') }}
                                    </td>
                                @endforeach
                                <td class="text-right px-3 py-2 font-semibold tabular-nums text-[var(--am-accent)] bg-[var(--am-accent-surface)]">{{ number_format($row['rowTotal'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                </tbody>
                @endforeach
                <tfoot>
                    <tr class="border-t-2 border-[color:var(--am-border)] bg-[var(--am-bg)] font-semibold">
                        <td class="sticky left-0 z-10 bg-[var(--am-bg)] px-3 py-2 text-[var(--am-text)] border-r border-[color:var(--am-border)]">Summe</td>
                        @foreach($types as $t)
                            <td class="text-right px-3 py-2 tabular-nums text-[var(--am-text)]">{{ number_format($pivot['colTotals'][$t['id']] ?? 0, 2, ',', '.') }}</td>
                        @endforeach
                        <td class="text-right px-3 py-2 tabular-nums text-[var(--am-accent)] bg-[var(--am-accent-surface)]">{{ number_format($pivot['grandTotal'], 2, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
