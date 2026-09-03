{{--
    Vorschau der Abrechnungs-Ausnahme (Dry-Run des BillingExclusionService, ADR 0024).

    Der Kern dieses Zwischenschritts ist die Mengen-Kachel: die Aktion verändert, was einem Kunden
    in Rechnung gestellt wird. Sie zeigt deshalb nicht „12 Träger betroffen", sondern die Menge
    vorher und nachher samt Geldwirkung — und, wenn sich nichts ändert, auch das. Ein Ausschluss von
    Trägern, die die Zählregel ohnehin heraushält, sieht sonst nach einer Ersparnis aus, die nie
    eintritt.
--}}
<x-ui-modal model="showBulkBilling" size="lg">
    <x-slot name="header">
        {{ $bulkExclude ? 'Von der Weiterberechnung ausnehmen — Vorschau' : 'Ausnahme aufheben — Vorschau' }}
    </x-slot>

    @if($bulkBillingPreview)
        <div class="space-y-4">
            @if($bulkExclude)
                <p class="text-sm text-[var(--am-text-secondary)]">
                    Grund: <strong class="text-[var(--am-text)]">{{ $bulkReason }}</strong>
                </p>
            @else
                <p class="text-sm text-[var(--am-text-secondary)]">
                    Die Ausnahme wird entfernt — danach entscheidet allein die Zählregel des Profils,
                    ob diese Träger abgerechnet werden.
                </p>
            @endif

            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg border border-[color:var(--am-border)] p-3 text-center">
                    <div class="text-2xl font-semibold tabular-nums text-[var(--am-accent)]">{{ $bulkBillingPreview['summary']['updated'] }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mt-1">werden geändert</div>
                </div>
                <div class="rounded-lg border border-[color:var(--am-border)] p-3 text-center">
                    <div class="text-2xl font-semibold tabular-nums text-[var(--am-text-secondary)]">{{ $bulkBillingPreview['summary']['unchanged'] }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mt-1">
                        {{ $bulkExclude ? 'schon so ausgenommen' : 'haben keine Ausnahme' }}
                    </div>
                </div>
                <div class="rounded-lg border border-[color:var(--am-border)] p-3 text-center">
                    <div class="text-2xl font-semibold tabular-nums text-[var(--am-text-secondary)]">{{ $bulkBillingPreview['summary']['missing'] }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mt-1">nicht gefunden</div>
                </div>
            </div>

            {{-- Wirkung je Abrechnungsprofil. Mehrere Profile werden NICHT zu einer Zahl summiert:
                 sie gehören zu verschiedenen Kunden, und eine Summe darüber wäre nichts, was
                 jemandem in Rechnung gestellt wird. --}}
            @forelse($bulkBillingPreview['impact'] as $row)
                <div class="rounded-lg border border-[color:var(--am-border-strong)] bg-[var(--am-bg)] px-4 py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                            {{ $row['profile_name'] }}
                        </span>
                        @if($row['delta_quantity'] === 0)
                            <span class="text-xs text-[var(--am-text-secondary)]">Menge unverändert</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-baseline gap-3 mt-1.5">
                        <span class="text-sm text-[var(--am-text-secondary)]">Abgerechnete Menge</span>
                        <span class="text-lg font-semibold tabular-nums text-[var(--am-text)]">
                            {{ $row['quantity_before'] }} → {{ $row['quantity_after'] }}
                        </span>

                        @if($row['delta_net_cents'] !== null && $row['delta_quantity'] !== 0)
                            <span class="text-sm font-semibold tabular-nums {{ $row['delta_net_cents'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                {{ $row['delta_net_cents'] < 0 ? '−' : '+' }}{{ number_format(abs($row['delta_net_cents']) / 100, 2, ',', '.') }}
                                {{ $row['currency'] }} netto / Monat
                            </span>
                        @elseif($row['delta_net_cents'] === null)
                            <span class="text-xs text-amber-700">Stückpreis nicht ermittelbar — Geldwirkung unbekannt.</span>
                        @endif
                    </div>

                    @if($row['delta_quantity'] === 0 && $bulkBillingPreview['summary']['updated'] > 0)
                        <p class="text-xs text-[var(--am-text-secondary)] mt-2 mb-0">
                            Diese Träger hält die Zählregel ohnehin heraus (kein Personenkonto, keine
                            Lizenz, ausgeschlossene Domain …). Die Ausnahme ist damit eine Notiz, keine
                            Ersparnis.
                        </p>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-[color:var(--am-border)] bg-[var(--am-bg)] px-3 py-2.5 text-xs text-[var(--am-text-secondary)]">
                    Für diesen Tenant ist kein aktives Abrechnungsprofil hinterlegt — die Ausnahme wird
                    gespeichert, wirkt aber erst, wenn ein Profil abrechnet.
                </div>
            @endforelse

            @if($bulkBillingPreview['results'] !== [])
                <div class="rounded-lg border border-[color:var(--am-border)] max-h-64 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0">
                            <tr class="bg-[var(--am-bg)] border-b border-[color:var(--am-border)] text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">
                                <th class="text-left px-3 py-2">Träger</th>
                                <th class="text-left px-3 py-2">UPN</th>
                                <th class="text-left px-3 py-2">Bisher</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($bulkBillingPreview['results'] as $row)
                                <tr wire:key="bill-prev-{{ $row['id'] }}" class="{{ $row['status'] === 'unchanged' ? 'opacity-50' : '' }}">
                                    <td class="px-3 py-1.5 text-[var(--am-text)]">{{ $row['label'] }}</td>
                                    <td class="px-3 py-1.5 text-[var(--am-text-secondary)]">{{ $row['upn'] }}</td>
                                    <td class="px-3 py-1.5 text-[var(--am-text-secondary)]">
                                        @if($row['status'] === 'unchanged')
                                            unverändert
                                        @elseif(! empty($row['was_excluded']))
                                            ausgenommen{{ ! empty($row['previous_reason']) ? ' („' . $row['previous_reason'] . '“)' : '' }}
                                        @else
                                            wird abgerechnet
                                        @endif
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
        <x-asset-manager-button variant="secondary" size="sm" wire:click="$set('showBulkBilling', false)">
            Abbrechen
        </x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="bulkSetBillingExclusion" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            {{ $bulkExclude ? 'Jetzt ausnehmen' : 'Jetzt aufheben' }}
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
