{{--
    EINE Gruppenzeile plus (wenn offen) der eingerückte Block ihrer Positionen.

    Erwartet: $g (dekorierte Gruppe aus Index::decorateGroups), $openGroup, $openLines,
              $openLinesTotal, $groupCols, $flaggedMap.
--}}
@php
    $isOpen   = $openGroup === $g['key'];
    $isCredit = $g['monthly'] < 0;
@endphp

<tr wire:key="grp-{{ $groupBy }}-{{ $g['key'] }}" wire:click="toggleGroup('{{ $g['key'] }}')"
    class="cursor-pointer hover:bg-[var(--am-bg)] {{ $isOpen ? 'bg-[var(--am-bg)]' : '' }}">

    <td class="w-8 pl-4 pr-1 py-3 text-[var(--am-text-muted)]">
        @svg($isOpen ? 'heroicon-o-chevron-down' : 'heroicon-o-chevron-right', 'w-4 h-4')
    </td>

    <td class="px-2 py-3">
        <div class="font-medium text-[var(--am-text)]">{{ $g['label'] }}</div>
        @if($g['sublabel'])
            <div class="text-xs text-[var(--am-text-muted)]">{{ $g['sublabel'] }}</div>
        @endif
    </td>

    <td class="px-4 py-3">
        <div class="flex flex-wrap items-center gap-1.5">
            @if($g['level'])
                <x-asset-manager-badge color="gray" size="xs" :pill="false"
                    title="Bezugsebene der Kostenart">
                    {{ $g['level'] === 'person' ? 'Person' : 'Kostenstelle' }}
                </x-asset-manager-badge>
            @endif

            @if($isCredit)
                <x-asset-manager-badge color="emerald" size="xs" :pill="false"
                    title="Negative Summe — diese Kostenart rechnet gegen">Gutschrift</x-asset-manager-badge>
            @endif

            {{-- Kostenart zieht ihren Wert aus einer anderen Quelle (Graph/Inventar): diese
                 Positionen fallen aus normalizedLines() und erscheinen in KEINER Auswertung. --}}
            @if($g['offPivot'])
                <x-asset-manager-badge color="amber" size="xs" :pill="false" icon="heroicon-o-exclamation-triangle"
                    title="Diese Kostenart wird aus einer anderen Quelle bewertet — die Beträge dieser Positionen erscheinen in keiner Auswertung.">
                    nicht im Pivot
                </x-asset-manager-badge>
            @endif

            @if($g['flagged'] > 0)
                <x-asset-manager-badge color="amber" size="xs" :pill="false" icon="heroicon-o-exclamation-triangle"
                    title="Positionen mit Plausibilitäts-Befund">{{ $g['flagged'] }} auffällig</x-asset-manager-badge>
            @endif

            @if($g['inactive'] > 0)
                <x-asset-manager-badge color="gray" size="xs" :pill="false">{{ $g['inactive'] }} inaktiv</x-asset-manager-badge>
            @endif

            @if($g['once'] != 0.0)
                <x-asset-manager-badge color="amber" size="xs" :pill="false"
                    title="Einmalbeträge — fließen nicht in die Monatssumme">
                    + {{ number_format($g['once'], 2, ',', '.') }} € einmalig
                </x-asset-manager-badge>
            @endif
        </div>
    </td>

    <td class="px-4 py-3 text-right text-xs text-[var(--am-text-secondary)] tabular-nums whitespace-nowrap">
        {{ $g['count'] }}
    </td>

    <td class="px-4 py-3 text-right font-semibold tabular-nums whitespace-nowrap {{ $isCredit ? 'text-red-700' : 'text-[var(--am-text)]' }}">
        {{ number_format($g['monthly'], 2, ',', '.') }} €
    </td>

    {{-- Balken: nur die Breite ist dynamisch (Inline-Style), alle Klassen literal — sonst findet
         der Tailwind-@source-Scan sie nicht. Gutschriften wachsen nach links. --}}
    <td class="px-4 py-3 w-40">
        <div class="w-full h-2 rounded-full bg-[var(--am-bg)] overflow-hidden flex {{ $isCredit ? 'justify-end' : '' }}">
            <div class="h-full {{ $isCredit ? 'bg-[var(--am-error)]' : 'bg-[var(--am-accent)]' }}"
                 style="width: {{ $g['sharePct'] }}%"></div>
        </div>
    </td>

    <td class="px-4 py-3 text-right text-xs text-[var(--am-text-muted)] tabular-nums whitespace-nowrap">
        {{ $isCredit ? '−' : '' }}{{ number_format($g['sharePct'], 1, ',', '.') }} %
    </td>

    <td class="pr-4 pl-2 py-3 text-right whitespace-nowrap">
        @can('asset-manager.manage')
            {{-- Ganze Gruppe auswaehlen: "alle 7 necta-Zeilen" ist ein Klick, nicht sieben. --}}
            <button type="button" wire:click.stop="selectGroup('{{ $g['key'] }}')"
                    class="mr-1 text-[var(--am-text-secondary)] hover:text-[var(--am-accent)]"
                    title="Alle {{ $g['count'] }} Positionen dieser Gruppe auswählen">
                @svg('heroicon-o-check-circle', 'w-4 h-4 inline')
            </button>
        @endcan
        <button type="button" wire:click.stop="drillDown('{{ $g['key'] }}')"
                class="text-[var(--am-text-secondary)] hover:text-[var(--am-accent)]"
                title="Gefiltert in der Liste öffnen">
            @svg('heroicon-o-arrow-top-right-on-square', 'w-4 h-4 inline')
        </button>
    </td>
</tr>

@if($isOpen)
    <tr wire:key="grp-open-{{ $groupBy }}-{{ $g['key'] }}">
        <td colspan="{{ $groupCols }}" class="p-0 bg-[var(--am-bg)] border-l-2 border-[color:var(--am-accent)]">
            @if($openLines->isEmpty())
                <div class="px-6 py-4 text-xs text-[var(--am-text-secondary)]">Keine Positionen in dieser Gruppe.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm bg-[var(--am-surface)]">
                        <thead>
                            <tr class="border-y border-[color:var(--am-border)] text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                @can('asset-manager.manage')<th class="w-10 pl-4 pr-1 py-2"></th>@endcan
                                <th class="text-left px-4 py-2">Bezeichnung</th>
                                <th class="text-left px-4 py-2">Kostenart</th>
                                <th class="text-left px-4 py-2">KSt</th>
                                <th class="text-left px-4 py-2">Träger</th>
                                <th class="text-left px-4 py-2">Kreditor</th>
                                <th class="text-right px-4 py-2">Betrag</th>
                                <th class="text-left px-4 py-2">Frequenz</th>
                                <th class="text-right px-4 py-2">€/Monat</th>
                                <th class="text-left px-4 py-2">Gültigkeit</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($openLines as $line)
                                @include('asset-manager::livewire.cost-lines.partials.row-line', ['line' => $line, 'dense' => true])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($openLinesTotal > $openLines->count())
                    <div class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)] border-t border-[color:var(--am-border)] bg-[var(--am-surface)]">
                        {{ $openLines->count() }} von {{ $openLinesTotal }} Positionen angezeigt ·
                        <button type="button" wire:click="drillDown('{{ $g['key'] }}')"
                                class="font-medium text-[var(--am-accent)] hover:underline">Alle anzeigen</button>
                    </div>
                @endif
            @endif
        </td>
    </tr>
@endif
