{{--
    EINE Kostenpositions-Zeile. Einzige Quelle für das Zeilen-Markup — wird von der flachen
    Tabelle und (mit $dense=true) vom aufgeklappten Gruppenblock benutzt.

    Erwartet: $line. Optional: $dense (kompaktere Polsterung im Gruppenblock).
--}}
@php
    $dense   = $dense ?? false;
    $cellPad = $dense ? 'px-4 py-2' : 'px-4 py-2.5';

    $freqLabels = ['monthly' => 'mtl.', 'quarterly' => 'qrtl.', 'yearly' => 'jähr.', 'once' => 'einm.'];

    // Herkunft als dezentes Icon, nicht als Badge: bei 100 % Import-Bestand wären 575 farbige
    // Badges reines Rauschen — es geht darum, die EINE manuelle Zeile zu erkennen.
    $srcMeta = [
        'manual'       => ['heroicon-o-pencil-square', 'manuell erfasst'],
        'excel_import' => ['heroicon-o-table-cells',   'aus Excel-Import'],
        'graph'        => ['heroicon-o-cloud',          'aus Graph-Sync'],
    ][$line->source] ?? null;

    // Gültigkeit: NULL heisst unbegrenzt (AssetCostLine::validOn). Abgelaufene und zukünftige
    // Positionen fallen aus der Kostenaufteilung heraus, waren in der Liste aber unsichtbar.
    $today   = now()->startOfDay();
    $from    = $line->valid_from;
    $to      = $line->valid_to;
    $expired = $to !== null && $to->lt($today);
    $future  = $from !== null && $from->gt($today);

    // Plausibilitäts-Befunde dieser Zeile (CostPlausibilityService liefert die vollständigen
    // line_ids, die Map wird einmal pro Render gebaut - siehe Index::plausibility()).
    $findings = $flaggedMap[$line->id] ?? [];

    $validityText = match (true) {
        $from !== null && $to !== null => $from->format('d.m.y') . '–' . $to->format('d.m.y'),
        $from !== null                 => 'ab ' . $from->format('d.m.y'),
        $to !== null                   => 'bis ' . $to->format('d.m.y'),
        default                        => '—',
    };
@endphp

<tr wire:key="cl-{{ $line->id }}" wire:click="edit({{ $line->id }})"
    class="cursor-pointer hover:bg-[var(--am-bg)] {{ $line->active ? '' : 'opacity-50' }}">

    <td class="{{ $cellPad }} font-medium text-[var(--am-text)]">
        <span class="inline-flex items-center gap-1.5">
            @if($srcMeta)
                <span title="{{ $srcMeta[1] }}" class="text-[var(--am-text-disabled)] flex-shrink-0">
                    @svg($srcMeta[0], 'w-3.5 h-3.5')
                </span>
            @endif
            {{ $line->label }}
            @if($findings)
                <span title="{{ implode(' · ', $findings) }}" class="text-amber-600 flex-shrink-0">
                    @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5')
                </span>
            @endif
        </span>
    </td>

    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">{{ $line->costType?->name }}</td>
    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">{{ $line->costCenter?->code ?? '—' }}</td>

    {{-- Asset-Träger: wurde schon immer eager-geladen, aber nie angezeigt — obwohl die
         Bezugsebene (person|cost_center) das zentrale fachliche Merkmal der Kostenart ist. --}}
    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">
        @if($line->assignee)
            <span class="inline-flex items-center gap-1.5">
                {{ $line->assignee->name }}
                @unless($line->assignee->is_active)
                    <x-asset-manager-badge color="gray" size="xs" :pill="false"
                        title="Asset-Träger ist inaktiv">inaktiv</x-asset-manager-badge>
                @endunless
            </span>
        @else
            —
        @endif
    </td>

    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)]">{{ $line->vendor?->name ?? '—' }}</td>

    <td class="{{ $cellPad }} text-right tabular-nums text-[var(--am-text)] whitespace-nowrap">
        {{ number_format((float) $line->amount, 2, ',', '.') }}
        {{ $line->currency === 'EUR' ? '€' : $line->currency }}
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

    <td class="{{ $cellPad }} text-xs text-[var(--am-text-secondary)] whitespace-nowrap">
        @if($expired)
            <x-asset-manager-badge color="red" size="xs" :pill="false"
                title="Zeitraum abgelaufen — zählt nicht in der Kostenaufteilung">{{ $validityText }}</x-asset-manager-badge>
        @elseif($future)
            <x-asset-manager-badge color="sky" size="xs" :pill="false"
                title="Beginnt erst in der Zukunft — zählt noch nicht in der Kostenaufteilung">{{ $validityText }}</x-asset-manager-badge>
        @else
            {{ $validityText }}
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
