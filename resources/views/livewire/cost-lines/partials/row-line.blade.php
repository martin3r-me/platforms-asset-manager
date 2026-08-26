{{--
    EINE Kostenpositions-Zeile. Einzige Quelle für das Zeilen-Markup — wird von der flachen
    Tabelle und (mit $dense=true) vom aufgeklappten Gruppenblock benutzt.

    Erwartet: $line, optional $dense (kompaktere Polsterung im Gruppenblock).
--}}
@php
    $dense    = $dense ?? false;
    $cellPad  = $dense ? 'px-4 py-2' : 'px-4 py-2.5';
    $freqLabels = ['monthly' => 'mtl.', 'quarterly' => 'qrtl.', 'yearly' => 'jähr.', 'once' => 'einm.'];
@endphp

<tr wire:key="cl-{{ $line->id }}" wire:click="edit({{ $line->id }})"
    class="cursor-pointer hover:bg-[var(--am-bg)] {{ $line->active ? '' : 'opacity-50' }}">

    <td class="{{ $cellPad }} font-medium text-[var(--am-text)]">
        {{ $line->label }}
    </td>

    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">{{ $line->costType?->name }}</td>
    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">{{ $line->costCenter?->code ?? '—' }}</td>
    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">{{ $line->vendor?->name ?? '—' }}</td>

    <td class="{{ $cellPad }} text-right tabular-nums text-[var(--am-text)]">
        {{ number_format((float) $line->amount, 2, ',', '.') }} €
    </td>

    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">
        {{ $freqLabels[$line->frequency] ?? $line->frequency }}
    </td>

    <td class="{{ $cellPad }} text-right font-semibold tabular-nums text-[var(--am-accent)]">
        @if($line->frequency === 'once')
            <x-asset-manager-badge color="amber" size="xs" :pill="false"
                title="Einmalbetrag — fließt nicht in die monatliche Aufteilung">einmalig</x-asset-manager-badge>
        @else
            {{ number_format((float) $line->monthly_amount, 2, ',', '.') }} €
        @endif
    </td>

    <td class="{{ $cellPad }} text-right whitespace-nowrap">
        @can('asset-manager.manage')
            <button type="button" wire:click.stop="toggleActive({{ $line->id }})"
                    class="text-xs text-[var(--am-text-secondary)] hover:text-amber-600"
                    title="{{ $line->active ? 'Deaktivieren' : 'Aktivieren' }}">
                @svg('heroicon-o-power', 'w-4 h-4 inline')
            </button>
        @endcan
    </td>
</tr>
