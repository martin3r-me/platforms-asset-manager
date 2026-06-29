{{-- Partial für Department/CostCenter-Tabellen. Erwartet $rows und $emptyHint. --}}
<div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
    @if($rows->isEmpty() || $rows->sum('total') == 0)
        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">{{ $emptyHint ?? 'Keine Daten.' }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[color:var(--am-border)]">
                        <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[var(--am-bg)] text-[var(--am-text-muted)]">Bereich</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[var(--am-bg)] text-[var(--am-text-muted)]">Mitarbeiter</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[var(--am-bg)] text-[var(--am-text-muted)]">Hardware</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[var(--am-bg)] text-[var(--am-text-muted)]">Lizenzen</th>
                        <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[var(--am-bg)] text-[var(--am-text-muted)]">Gesamt / Monat</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[var(--am-bg)] text-[var(--am-text-muted)] w-32">Verteilung</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[color:var(--am-border)]">
                    @php $maxTotal = $rows->max('total') ?: 1; @endphp
                    @foreach($rows as $row)
                        <tr class="hover:bg-[var(--am-bg)]">
                            <td class="px-5 py-3 font-medium text-[var(--am-text)]">{{ $row['label'] }}</td>
                            <td class="px-5 py-3 text-right text-sm tabular-nums text-[var(--am-text-secondary)]">{{ $row['count'] }}</td>
                            <td class="px-5 py-3 text-right text-sm tabular-nums text-sky-700">{{ number_format($row['hardware'], 2, ',', '.') }} €</td>
                            <td class="px-5 py-3 text-right text-sm tabular-nums text-emerald-700">{{ number_format($row['licenses'], 2, ',', '.') }} €</td>
                            <td class="px-5 py-3 text-right text-sm font-semibold tabular-nums text-[var(--am-accent)]">{{ number_format($row['total'], 2, ',', '.') }} €</td>
                            <td class="px-5 py-3">
                                <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                    <div class="h-full bg-[var(--am-accent)]" style="width: {{ round($row['total'] / $maxTotal * 100) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
