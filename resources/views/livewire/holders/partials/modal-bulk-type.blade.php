{{--
    Vorschau der Typ-Sammelaktion (Dry-Run des HolderTypeService).

    Die drei rechten Kacheln sind der Grund, warum es diesen Zwischenschritt überhaupt gibt: sie
    benennen Nebenwirkungen, die man dem Vorgang sonst nicht ansieht — ein gelöschtes Funktions-
    System, ein Funktionskonto ohne System (nach ADR 0019 nicht bewirtschaftbar) und Träger, die der
    nächste Sync wieder zurückstellt. Der letzte Fall ist der heimtückischste: ohne diesen Hinweis
    sieht man Erfolg und findet den Bestand am nächsten Morgen unverändert vor.
--}}
<x-ui-modal model="showBulkType" size="lg">
    <x-slot name="header">Träger-Typ setzen — Vorschau</x-slot>

    @if($bulkPreview)
        <div class="space-y-4">
            <p class="text-sm text-[var(--am-text-secondary)]">
                Ziel: <strong class="text-[var(--am-text)]">{{ $bulkPreview['target'] }}</strong>
            </p>

            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg border border-[color:var(--am-border)] p-3 text-center">
                    <div class="text-2xl font-semibold tabular-nums text-[var(--am-accent)]">{{ $bulkPreview['summary']['updated'] }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mt-1">werden geändert</div>
                </div>
                <div class="rounded-lg border border-[color:var(--am-border)] p-3 text-center">
                    <div class="text-2xl font-semibold tabular-nums text-[var(--am-text-secondary)]">{{ $bulkPreview['summary']['unchanged'] }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mt-1">haben den Typ schon</div>
                </div>
                <div class="rounded-lg border border-[color:var(--am-border)] p-3 text-center">
                    <div class="text-2xl font-semibold tabular-nums text-[var(--am-text-secondary)]">{{ $bulkPreview['summary']['missing'] }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mt-1">nicht gefunden</div>
                </div>
            </div>

            @if($bulkPreview['summary']['clears_system'] > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
                    <strong>{{ $bulkPreview['summary']['clears_system'] }} × zugehöriges System wird gelöscht.</strong>
                    Das Feld gehört nur an ein Funktionskonto. Der Wert ist danach weg und lässt sich nicht zurückholen.
                </div>
            @endif

            @if($bulkPreview['summary']['needs_system'] > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
                    <strong>{{ $bulkPreview['summary']['needs_system'] }} Funktionskonten ohne System.</strong>
                    Die Sammelaktion trägt keins ein — dasselbe System für alle wäre fast immer falsch.
                    Ohne Angabe behandelt die Lizenz-Verteilung sie nicht bevorzugt; bitte einzeln nachpflegen.
                </div>
            @endif

            @if($bulkPreview['summary']['reverts_on_sync'] > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
                    <strong>{{ $bulkPreview['summary']['reverts_on_sync'] }} werden beim nächsten Sync zurückgestellt.</strong>
                    Bei ihnen greift eine Erkennungsregel (oder eine synthetische Funktions-UPN), die stärker ist
                    als die Einzelkorrektur. Dauerhaft ändern lässt sich das nur über die Regeln in den
                    Moduleinstellungen.
                </div>
            @endif

            @if($bulkPreview['results'] !== [])
                <div class="rounded-lg border border-[color:var(--am-border)] max-h-64 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0">
                            <tr class="bg-[var(--am-bg)] border-b border-[color:var(--am-border)] text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">
                                <th class="text-left px-3 py-2">Träger</th>
                                <th class="text-left px-3 py-2">Von</th>
                                <th class="text-left px-3 py-2">Nach</th>
                                <th class="text-left px-3 py-2">Hinweis</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($bulkPreview['results'] as $row)
                                <tr wire:key="prev-{{ $row['id'] }}" class="{{ $row['status'] === 'unchanged' ? 'opacity-50' : '' }}">
                                    <td class="px-3 py-1.5 text-[var(--am-text)]">{{ $row['label'] }}</td>
                                    <td class="px-3 py-1.5 text-[var(--am-text-secondary)]">{{ $row['from'] ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-[var(--am-text-secondary)]">{{ $row['to'] }}</td>
                                    <td class="px-3 py-1.5 text-amber-700">
                                        @if(! empty($row['clears_system'])) System wird gelöscht @endif
                                        @if(! empty($row['needs_system'])) kein System hinterlegt @endif
                                        @if(! empty($row['reverts_on_sync'])) Sync stellt zurück @endif
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
        <x-asset-manager-button variant="secondary" size="sm" wire:click="$set('showBulkType', false)">
            Abbrechen
        </x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="bulkSetType" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            Jetzt setzen
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
