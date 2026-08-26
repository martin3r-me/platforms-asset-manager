{{--
    Bestätigung der Bulk-Umbuchung mit Dry-Run-Vorschau.

    Die Zahlen kommen aus CostLineReassignService::reassignCostCenter(dryRun: true) — derselben
    Logik, die anschließend wirklich schreibt. Ein wire:confirm könnte nicht sagen, wie viele
    Positionen schon am Ziel hängen oder gar nicht mehr existieren.
--}}
<x-ui-modal model="showBulkReassign" size="lg">
    <x-slot name="header">Kostenstelle umbuchen</x-slot>

    @if($bulkPreview)
        <div class="space-y-4">
            <p class="text-sm text-[var(--am-text-secondary)] m-0">
                Ziel: <strong class="text-[var(--am-text)]">{{ $bulkPreview['target'] }}</strong>
            </p>

            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-3">
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Werden umgebucht</div>
                    <div class="text-xl font-semibold text-[var(--am-text)] tabular-nums">{{ $bulkPreview['summary']['updated'] }}</div>
                </div>
                <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-3">
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Hängen schon dort</div>
                    <div class="text-xl font-semibold text-[var(--am-text-secondary)] tabular-nums">{{ $bulkPreview['summary']['unchanged'] }}</div>
                </div>
                <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-3">
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Nicht gefunden</div>
                    <div class="text-xl font-semibold {{ $bulkPreview['summary']['missing'] > 0 ? 'text-amber-600' : 'text-[var(--am-text-secondary)]' }} tabular-nums">
                        {{ $bulkPreview['summary']['missing'] }}
                    </div>
                </div>
            </div>

            @if($bulkPreview['summary']['missing'] > 0)
                <p class="text-xs text-amber-700 m-0">
                    Nicht gefunden heißt: die Position wurde zwischenzeitlich gelöscht oder gehört zu einem
                    anderen Team. Sie wird übersprungen.
                </p>
            @endif

            @if(count($bulkPreview['results']) > 0)
                <div class="max-h-64 overflow-y-auto rounded-lg border border-[color:var(--am-border)]">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-[var(--am-bg)] text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <th class="text-left px-3 py-2">Position</th>
                                <th class="text-left px-3 py-2">Von</th>
                                <th class="text-left px-3 py-2">Nach</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($bulkPreview['results'] as $row)
                                <tr class="{{ $row['status'] === 'unchanged' ? 'opacity-50' : '' }}">
                                    <td class="px-3 py-1.5 text-[var(--am-text)]">{{ $row['label'] }}</td>
                                    <td class="px-3 py-1.5 text-[var(--am-text-secondary)]">{{ $row['from'] ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-[var(--am-text-secondary)]">
                                        {{ $row['status'] === 'unchanged' ? 'unverändert' : $row['to'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <x-slot name="footer">
        <x-asset-manager-button variant="secondary" size="sm" wire:click="$set('showBulkReassign', false)">
            Abbrechen
        </x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="bulkReassignCenter" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            Umbuchen
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
