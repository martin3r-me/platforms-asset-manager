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
                <h2 class="text-sm font-semibold text-[var(--am-text-secondary)]">Import-Log</h2>
                <p class="text-xs text-[var(--am-text-muted)] mt-1">
                    Zeigt zeilengenau, was aus der Excel <strong>wohin</strong> importiert wurde — je Kostenposition
                    die Herkunft (Sheet + Excel-Zeile) und das Ziel (Kostenstelle, Mitarbeiter bzw. Asset).
                    Nur Positionen aus dem Excel-Import ({{ $total }} gesamt).
                </p>
            </div>

            {{-- Filter --}}
            <div class="flex flex-wrap items-end gap-3 rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-4">
                <div>
                    <label class="block text-[11px] text-[var(--am-text-muted)] mb-1">Sheet</label>
                    <x-asset-manager-select size="sm" wire:model.live="filterSheet">
                        <option value="">Alle Sheets</option>
                        @foreach($sheets as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                    </x-asset-manager-select>
                </div>
                <div>
                    <label class="block text-[11px] text-[var(--am-text-muted)] mb-1">Import-Batch</label>
                    <x-asset-manager-select size="sm" wire:model.live="filterBatch">
                        <option value="">Alle Batches</option>
                        @foreach($batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                    </x-asset-manager-select>
                </div>
                @if($filterSheet || $filterBatch)
                    <x-asset-manager-button variant="ghost" size="sm" wire:click="$set('filterSheet', ''); $set('filterBatch', '')" class="pb-2">Filter zurücksetzen</x-asset-manager-button>
                @endif
                <div class="ml-auto text-right">
                    <div class="text-[11px] text-[var(--am-text-muted)]">Summe (angezeigt)</div>
                    <div class="text-sm font-semibold tabular-nums text-[var(--am-text)]">{{ number_format($shownSum, 2, ',', '.') }} €/Mt</div>
                </div>
            </div>

            {{-- Tabelle --}}
            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($lines->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-document-magnifying-glass', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                        <p class="text-sm text-[var(--am-text-secondary)]">Keine importierten Positionen für diese Filter.</p>
                        <p class="text-xs text-[var(--am-text-secondary)] mt-1">Nach einem Excel-Import erscheint hier jede Zeile mit ihrer Herkunft.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)] text-left">
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Herkunft</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Kostenart</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Bezeichnung</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">→ Kostenstelle</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">→ Zugeordnet</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] text-right">€/Mt</th>
                                <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Importiert</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($lines as $line)
                                <tr class="hover:bg-[var(--am-bg)]">
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        @php $sheet = $line->raw_data['sheet'] ?? null; $row = $line->raw_data['row'] ?? null; @endphp
                                        @if($sheet)
                                            <x-asset-manager-badge color="violet" size="xs">{{ $sheet }}</x-asset-manager-badge>
                                            @if($row)<span class="text-[11px] text-[var(--am-text-muted)] ml-1">Z.{{ $row }}</span>@endif
                                        @else
                                            <span class="text-[11px] text-[var(--am-text-disabled)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-[var(--am-text-secondary)]">{{ $line->costType?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-[var(--am-text)]">{{ $line->label }}</td>
                                    <td class="px-4 py-2.5">
                                        @if($line->costCenter)
                                            <span class="font-mono text-[var(--am-text-secondary)]">{{ $line->costCenter->code }}</span>
                                            @if($line->costCenter->name)<span class="text-xs text-[var(--am-text-muted)]">· {{ $line->costCenter->name }}</span>@endif
                                        @else
                                            <span class="text-xs text-amber-600">Ohne Kostenstelle</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">
                                        @if($line->assignee)
                                            @svg('heroicon-o-user', 'w-3.5 h-3.5 inline -mt-0.5') {{ $line->assignee->name }}
                                        @elseif($line->assetItem)
                                            @svg('heroicon-o-cube', 'w-3.5 h-3.5 inline -mt-0.5') {{ $line->assetItem->name }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums font-medium text-[var(--am-text)]">{{ number_format((float) $line->monthly_amount, 2, ',', '.') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)] whitespace-nowrap">{{ $line->created_at?->format('d.m.Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                    @if($lines->hasPages())
                        <div class="px-4 py-3 border-t border-[color:var(--am-border)]">{{ $lines->links() }}</div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-ui-page>
