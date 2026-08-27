{{--
    Nutzer-Vorschau einer Lizenz — der kurze Blick „wer hängt daran", ohne die Liste zu verlassen.

    Kehrt eine frühere Entscheidung (#762) um, die ein rechtes Vorschau-Panel entfernt hat, weil es die
    Detailseite verkürzt wiederholte. Der Unterschied zum damaligen Panel: dies ist keine zweite
    Dauersicht, sondern ein Nachschlagen auf Zuruf — und der Weg zur vollen Detailseite steht im Fuß.
--}}

@php
    $sku      = $usersModal['sku'];
    $rows     = $usersModal['rows'];
    $total    = $usersModal['total'];      // nach Suchfilter
    $totalAll = $usersModal['total_all'];  // ohne Suchfilter — Grundlage fuer das Suchfeld
    $filtered = trim($usersSearch) !== '';
@endphp

<x-ui-modal model="showUsersModal" size="lg">
    <x-slot name="header">
        {{ $sku ? ($sku->display_name ?: $sku->sku_part_number) : 'Nutzer' }}
        <span class="text-xs font-normal text-[var(--am-text-secondary)]">
            · {{ number_format($totalAll, 0, ',', '.') }} {{ $totalAll === 1 ? 'Zuweisung' : 'Zuweisungen' }}
            @if($filtered)
                · {{ number_format($total, 0, ',', '.') }} passend
            @endif
        </span>
    </x-slot>

    <div class="space-y-3">
        @if($totalAll > 10)
            <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="usersSearch"
                placeholder="Name oder Kennung suchen …" />
        @endif

        @if($rows->isEmpty())
            <p class="text-sm text-[var(--am-text-secondary)] m-0">
                {{ $filtered ? 'Keine Treffer für diese Suche.' : 'Diese Lizenz ist niemandem zugewiesen.' }}
            </p>
        @else
            <div class="overflow-x-auto -mx-1">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                            <th class="py-1.5 px-1">Träger</th>
                            <th class="py-1.5 px-1">Kostenstelle</th>
                            <th class="py-1.5 px-1 text-right">Preis / Monat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="border-b border-[color:var(--am-border-subtle)] last:border-0">
                                <td class="py-1.5 px-1">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        @if($row['holder'])
                                            <a href="{{ route('asset-manager.holders.show', $row['holder']) }}" wire:navigate
                                               class="text-[var(--am-text)] hover:text-[var(--am-accent)] truncate">
                                                {{ $row['name'] }}
                                            </a>
                                            @unless($row['holder']->holder_type === \Platform\AssetManager\Models\AssetHolder::TYPE_PERSON)
                                                <x-asset-manager-badge :color="$row['holder']->holderTypeBadgeColor()" size="xs">
                                                    {{ $row['holder']->holderTypeLabel() }}
                                                </x-asset-manager-badge>
                                            @endunless
                                        @else
                                            <span class="text-[var(--am-text)] truncate">{{ $row['name'] }}</span>
                                            {{-- Kein Asset-Träger zur Kennung: die Lizenz kostet trotzdem, ist
                                                 aber keiner Kostenstelle zurechenbar. --}}
                                            <x-asset-manager-badge color="amber" size="xs">nicht zugeordnet</x-asset-manager-badge>
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-[var(--am-text-muted)] font-mono truncate">{{ $row['upn'] }}</div>
                                </td>

                                <td class="py-1.5 px-1 text-xs text-[var(--am-text-secondary)]">
                                    @if($row['holder']?->costCenter)
                                        {{ $row['holder']->costCenter->code }} — {{ $row['holder']->costCenter->name }}
                                    @else
                                        <span class="text-[var(--am-text-muted)]">—</span>
                                    @endif
                                </td>

                                <td class="py-1.5 px-1 text-right whitespace-nowrap">
                                    @if($row['price'] !== null)
                                        <span class="tabular-nums">{{ number_format($row['price'], 2, ',', '.') }} €</span>
                                        @if($row['binding'])
                                            <span class="text-[10px] text-[var(--am-text-muted)]">
                                                {{ $row['binding'] === 'P1Y' ? 'Jahr' : 'Monat' }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-[var(--am-text-muted)]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($total > $rows->count())
                <p class="text-[11px] text-[var(--am-text-muted)] m-0">
                    {{ number_format($rows->count(), 0, ',', '.') }} von
                    {{ number_format($total, 0, ',', '.') }} gezeigt — die vollständige, blätterbare Liste
                    steht auf der Detailseite.
                </p>
            @endif
        @endif
    </div>

    <x-slot name="footer">
        @if($sku)
            <x-asset-manager-button variant="ghost" size="sm" :href="route('asset-manager.licenses.show', $sku)" wire:navigate>
                @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5')
                Detailseite
            </x-asset-manager-button>
        @endif
        <x-asset-manager-button variant="primary" size="sm" wire:click="closeUsers">Schließen</x-asset-manager-button>
    </x-slot>
</x-ui-modal>
