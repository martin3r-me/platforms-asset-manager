<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Import-Log', 'icon' => 'document-magnifying-glass'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            <div>
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)]">Import-Log</h2>
                <p class="text-xs text-[var(--ui-muted)] mt-1">
                    Zeigt zeilengenau, was aus der Excel <strong>wohin</strong> importiert wurde — je Kostenposition
                    die Herkunft (Sheet + Excel-Zeile) und das Ziel (Kostenstelle, Mitarbeiter bzw. Asset).
                    Nur Positionen aus dem Excel-Import ({{ $total }} gesamt).
                </p>
            </div>

            {{-- Filter --}}
            <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white border border-black/5 shadow-sm p-4">
                <div>
                    <label class="block text-[11px] text-[color:var(--ui-secondary)] mb-1">Sheet</label>
                    <select wire:model.live="filterSheet" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                        <option value="">Alle Sheets</option>
                        @foreach($sheets as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] text-[color:var(--ui-secondary)] mb-1">Import-Batch</label>
                    <select wire:model.live="filterBatch" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                        <option value="">Alle Batches</option>
                        @foreach($batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                    </select>
                </div>
                @if($filterSheet || $filterBatch)
                    <button wire:click="$set('filterSheet', ''); $set('filterBatch', '')" class="text-xs text-[color:var(--ui-primary)] hover:underline pb-2">Filter zurücksetzen</button>
                @endif
                <div class="ml-auto text-right">
                    <div class="text-[11px] text-[color:var(--ui-secondary)]">Summe (angezeigt)</div>
                    <div class="text-sm font-semibold tabular-nums text-gray-800">{{ number_format($shownSum, 2, ',', '.') }} €/Mt</div>
                </div>
            </div>

            {{-- Tabelle --}}
            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                @if($lines->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-document-magnifying-glass', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
                        <p class="text-sm text-[color:var(--ui-secondary)]">Keine importierten Positionen für diese Filter.</p>
                        <p class="text-xs text-[color:var(--ui-secondary)] mt-1">Nach einem Excel-Import erscheint hier jede Zeile mit ihrer Herkunft.</p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--ui-muted)] bg-[color:var(--ui-muted-10)] text-left">
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Herkunft</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Kostenart</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Bezeichnung</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">→ Kostenstelle</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">→ Zugeordnet</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)] text-right">€/Mt</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Importiert</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--ui-muted)]">
                            @foreach($lines as $line)
                                <tr class="hover:bg-[color:var(--ui-muted-10)]">
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        @php $sheet = $line->raw_data['sheet'] ?? null; $row = $line->raw_data['row'] ?? null; @endphp
                                        @if($sheet)
                                            <x-asset-manager-badge color="violet" size="xs">{{ $sheet }}</x-asset-manager-badge>
                                            @if($row)<span class="text-[11px] text-[color:var(--ui-secondary)] ml-1">Z.{{ $row }}</span>@endif
                                        @else
                                            <span class="text-[11px] text-[color:var(--ui-muted)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-600">{{ $line->costType?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-800">{{ $line->label }}</td>
                                    <td class="px-4 py-2.5">
                                        @if($line->costCenter)
                                            <span class="font-mono text-gray-700">{{ $line->costCenter->code }}</span>
                                            @if($line->costCenter->name)<span class="text-xs text-[color:var(--ui-secondary)]">· {{ $line->costCenter->name }}</span>@endif
                                        @else
                                            <span class="text-xs text-amber-600">Ohne Kostenstelle</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">
                                        @if($line->assignee)
                                            @svg('heroicon-o-user', 'w-3.5 h-3.5 inline -mt-0.5') {{ $line->assignee->name }}
                                        @elseif($line->assetItem)
                                            @svg('heroicon-o-cube', 'w-3.5 h-3.5 inline -mt-0.5') {{ $line->assetItem->name }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums font-medium text-gray-800">{{ number_format((float) $line->monthly_amount, 2, ',', '.') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)] whitespace-nowrap">{{ $line->created_at?->format('d.m.Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($lines->hasPages())
                        <div class="px-4 py-3 border-t border-[color:var(--ui-muted)]">{{ $lines->links() }}</div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-ui-page>
