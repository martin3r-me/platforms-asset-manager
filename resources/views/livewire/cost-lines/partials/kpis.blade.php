{{--
    Kennzahlen der gefilterten Menge.

    Vor dem Redesign stand hier eine Prosa-Zeile "Summe (gefiltert): 16.080,45 € / Monat". Das ist
    ein NETTO — die Gutschrift-Kostenart ("VF Lizenz RC Rabatt", −1.196,48 €/Mt.) war darin
    unsichtbar verrechnet. Aufwand und Gutschriften stehen deshalb jetzt getrennt daneben.
--}}
@php
    $flaggedCount = count($flaggedMap);
@endphp

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <x-asset-manager-stat-card
        label="Positionen"
        :value="number_format($totalFiltered, 0, ',', '.')"
        :sub="$kpi['once'] != 0.0 ? 'zzgl. ' . number_format($kpi['once'], 2, ',', '.') . ' € einmalig' : null"
        icon="heroicon-o-list-bullet"
        accent="navy" />

    <x-asset-manager-stat-card
        label="Aufwand / Monat"
        :value="number_format($kpi['gross'], 2, ',', '.') . ' €'"
        :sub="$kpi['credits'] != 0.0 ? 'Gutschriften ' . number_format($kpi['credits'], 2, ',', '.') . ' €' : 'keine Gutschriften'"
        icon="heroicon-o-arrow-trending-up" />

    <x-asset-manager-stat-card
        label="Netto / Monat"
        :value="number_format($kpi['net'], 2, ',', '.') . ' €'"
        :sub="$isReconciled ? 'Basis wie Kostenaufteilung' : 'Basis: alle Zeilen, auch inaktiv/abgelaufen'"
        icon="heroicon-o-banknotes"
        accent="violet"
        value-class="text-violet-600" />

    {{-- Bestandskennzahl (nicht gefiltert): die Prüfregeln sind kreuz-zeilig und lassen sich nicht
         auf die aktuelle Filterung eingrenzen. Erklärtexte bleiben auf /costs, hier nur Absprung. --}}
    <x-asset-manager-stat-card
        label="Auffällig"
        :value="number_format($flaggedCount, 0, ',', '.')"
        :sub="$flaggedCount > 0 ? number_format($flaggedMonthly, 2, ',', '.') . ' €/Mt. im Bestand' : 'keine Befunde'"
        icon="heroicon-o-exclamation-triangle"
        :accent="$flaggedCount > 0 ? 'amber' : null"
        :value-class="$flaggedCount > 0 ? 'text-amber-600' : ''"
        :href="route('asset-manager.costs') . '#section-plausibility'" />
</div>
