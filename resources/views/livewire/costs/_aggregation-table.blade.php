{{-- Partial für Department/CostCenter-Tabellen. Erwartet $rows und $emptyHint. --}}
<div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
    @if($rows->isEmpty() || $rows->sum('total') == 0)
        <div class="p-8 text-center text-sm text-[color:var(--ui-secondary)]">{{ $emptyHint ?? 'Keine Daten.' }}</div>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-[color:var(--ui-muted)]">
                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Bereich</th>
                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Mitarbeiter</th>
                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Hardware</th>
                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Lizenzen</th>
                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)]">Gesamt / Monat</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)] w-32">Verteilung</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[color:var(--ui-muted)]">
                @php $maxTotal = $rows->max('total') ?: 1; @endphp
                @foreach($rows as $row)
                    <tr class="hover:bg-[color:var(--ui-muted-10)]">
                        <td class="px-5 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $row['label'] }}</td>
                        <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $row['count'] }}</td>
                        <td class="px-5 py-3 text-right text-sm tabular-nums text-sky-700">{{ number_format($row['hardware'], 2, ',', '.') }} €</td>
                        <td class="px-5 py-3 text-right text-sm tabular-nums text-emerald-700">{{ number_format($row['licenses'], 2, ',', '.') }} €</td>
                        <td class="px-5 py-3 text-right text-sm font-semibold tabular-nums text-[color:var(--ui-primary)]">{{ number_format($row['total'], 2, ',', '.') }} €</td>
                        <td class="px-5 py-3">
                            <div class="w-full h-1.5 rounded-full bg-[var(--ui-muted-10)] overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500" style="width: {{ round($row['total'] / $maxTotal * 100) }}%"></div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
