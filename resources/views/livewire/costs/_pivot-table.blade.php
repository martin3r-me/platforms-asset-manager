{{-- Pivot Kostenstellen-Baum × Kostenart. Erwartet: $pivot (aus CostAggregationService::costCenterByType) --}}
@php
    $types = $pivot['types'];
    // Zeilen stehen in Baum-Reihenfolge; Knoten MIT Kindern zeigen die Rollup-Summe (eigene +
    // Nachfahren), Blätter ihre eigenen Beträge. Die Fußzeile summiert nur die eigenen Beträge —
    // sonst zählte jeder Betrag so oft, wie sein Knoten Vorfahren hat.
    $extraRows = array_values(array_filter([$pivot['unassigned'] ?? null, $pivot['orphan'] ?? null]));
    $allRows   = array_merge($pivot['rows'], $extraRows);
    $maxDepth  = \Platform\AssetManager\Models\AssetCostCenter::MAX_DEPTH;
@endphp

<div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
    @if(empty($types) || empty($allRows))
        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
            Noch keine Kostenpositionen erfasst. Importiere die Excel
            (<code class="text-xs">asset-manager:import-costs</code>) oder pflege Positionen manuell.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="text-xs border-collapse">
                <thead>
                    <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                        <th class="sticky left-0 z-10 bg-[var(--am-bg)] text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] min-w-[220px] border-r border-[color:var(--am-border)]">Kostenstelle</th>
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
                <tbody>
                    @foreach($allRows as $row)
                        @php
                            $depth   = min((int) ($row['depth'] ?? 0), $maxDepth);
                            $isGroup = ! empty($row['has_children']);
                            // Gruppen-Knoten zeigen den Rollup, Blätter ihre eigenen Zahlen.
                            $cells   = $isGroup ? ($row['rollupCells'] ?? $row['cells']) : $row['cells'];
                            $total   = $isGroup ? ($row['rollupTotal'] ?? $row['rowTotal']) : $row['rowTotal'];
                        @endphp
                        <tr class="border-b border-[color:var(--am-border)] hover:bg-[var(--am-bg)] {{ $isGroup ? 'bg-[var(--am-bg)] font-semibold' : '' }}">
                            <td class="sticky left-0 z-10 {{ $isGroup ? 'bg-[var(--am-bg)]' : 'bg-[var(--am-surface)]' }} px-3 py-2 whitespace-nowrap border-r border-[color:var(--am-border)] {{ $isGroup ? 'text-[var(--am-text)]' : 'font-medium text-[var(--am-text)]' }}">
                                {{-- Einrückung als Inline-Style: die Tiefe ist unbegrenzt, dynamische
                                     Tailwind-Klassen (pl-{{ n }}) entstehen im Build nicht. --}}
                                <span class="inline-flex items-center" style="padding-left: {{ $depth * 16 }}px">
                                    @if($depth > 0)<span class="text-[var(--am-text-muted)] mr-1.5 select-none">└</span>@endif
                                    {{ $row['code'] }}@if($row['name'])<span class="text-[var(--am-text-secondary)] font-normal ml-1">{{ $row['name'] }}</span>@endif
                                    @if($isGroup)<span class="ml-2 text-[9px] uppercase tracking-wider text-[var(--am-text-muted)] font-normal">inkl. untergeordnete</span>@endif
                                </span>
                            </td>
                            @foreach($types as $t)
                                @php $v = $cells[$t['id']] ?? 0; @endphp
                                <td class="text-right px-3 py-2 tabular-nums {{ $v == 0 ? 'text-[var(--am-text-muted)]' : ($v < 0 ? 'text-red-700' : 'text-[var(--am-text)]') }}">
                                    {{ $v == 0 ? '–' : number_format($v, 2, ',', '.') }}
                                </td>
                            @endforeach
                            <td class="text-right px-3 py-2 font-semibold tabular-nums text-[var(--am-accent)] bg-[var(--am-accent-surface)]">{{ number_format($total, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
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
