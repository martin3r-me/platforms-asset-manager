{{-- Lizenz-Abrechnung des Wiederverkäufers (ADR 0019) — Upload, Vorschau und Ergebnis. --}}

<x-asset-manager-panel title="Lizenz-Abrechnung importieren">
    <div class="space-y-5">
        <div>
            <p class="text-xs text-[var(--am-text-secondary)]">
                Export <strong>„Abrechnungsdetails"</strong> des Lizenz-Wiederverkäufers (.csv). Legt je
                Rechnungsposition eine <strong>Vertragszeile</strong> mit Monatspreis und Bindung an, ordnet
                sie einer Microsoft-365-Lizenz zu und verteilt die bezahlten Mengen auf die Lizenzträger.
            </p>
            <p class="text-[11px] text-[var(--am-text-muted)] mt-1">
                Der Monatspreis ist <strong>Listenpreis minus Rabatt</strong> — der Rabatt steckt im Preis
                und wird nicht getrennt ausgewiesen. Eine Lizenz kann mehrere Tarife haben (Jahresvertrag
                und Monatstarif nebeneinander); gemittelt wird nichts. Handgepflegte Stückpreise betroffener
                Lizenzen werden dabei abgelöst.
            </p>
        </div>

        <div>
            <label class="block text-xs text-[var(--am-text-secondary)] mb-1.5">Abrechnungsdetails (.csv)</label>
            <input type="file" wire:model="licenseFile" accept=".csv"
                   class="block w-full text-sm text-[var(--am-text-secondary)] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-[var(--am-primary)] file:text-[var(--am-on-primary)] hover:file:opacity-90 file:cursor-pointer cursor-pointer">
            @error('licenseFile')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror

            <div wire:loading wire:target="licenseFile" class="text-[11px] text-[var(--am-text-secondary)] mt-1">Lade hoch …</div>

            @if($licenseFile && !$errors->has('licenseFile'))
                <div class="text-[11px] text-emerald-700 mt-1">
                    @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ $licenseFile->getClientOriginalName() }} bereit
                </div>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <x-asset-manager-button variant="ghost" size="sm" wire:click="previewLicenseInvoice" wire:loading.attr="disabled" wire:target="previewLicenseInvoice,runLicenseInvoiceImport,licenseFile" :disabled="!$licenseFile">
                @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                Vorschau (Dry-Run)
            </x-asset-manager-button>
            <x-asset-manager-button variant="primary" size="md" wire:click="runLicenseInvoiceImport" wire:loading.attr="disabled" wire:target="previewLicenseInvoice,runLicenseInvoiceImport,licenseFile"
                    wire:confirm="Import wirklich schreiben? Handgepflegte Stückpreise der betroffenen Lizenzen werden dabei abgelöst."
                    :disabled="!$licenseFile">
                @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                Import starten
            </x-asset-manager-button>
            <span wire:loading wire:target="previewLicenseInvoice,runLicenseInvoiceImport" class="text-xs text-[var(--am-accent)] ml-1">
                @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
            </span>
        </div>
    </div>
</x-asset-manager-panel>

@if($licenseError)
    <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
        @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') {{ $licenseError }}
    </div>
@endif

@if($licenseResult)
    @php
        $lic    = $licenseResult;
        $checks = $lic['checks'];
        $alloc  = $lic['allocation']['totals'];
        $money  = fn ($value) => number_format((float) $value, 2, ',', '.') . ' €';
        $seats  = fn ($value) => number_format((float) $value, 0, ',', '.');
    @endphp

    <div class="rounded-xl bg-[var(--am-surface)] border {{ $licenseWasDryRun ? 'border-amber-200' : 'border-emerald-200' }} shadow-sm overflow-hidden">
        <div class="px-4 py-2.5 text-xs font-semibold {{ $licenseWasDryRun ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
            Lizenz-Abrechnung {{ $lic['period']['label'] }} —
            {{ $licenseWasDryRun ? 'Vorschau (Dry-Run — nichts geschrieben)' : 'Import abgeschlossen' }}
        </div>

        <div class="p-4 space-y-4">
            {{-- Prüfungen zuerst: ohne sie ist jede Zahl darunter wertlos. --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-lg border {{ $checks['reconciles'] ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' }} px-3 py-2">
                    <div class="text-[11px] {{ $checks['reconciles'] ? 'text-emerald-700' : 'text-red-700' }} font-medium">
                        {{ $checks['reconciles'] ? 'Summe stimmt' : 'Summe weicht ab' }}
                    </div>
                    <div class="text-xs text-[var(--am-text-secondary)] tabular-nums mt-0.5">
                        {{ $money($checks['positions_sum_net']) }}
                        @if($checks['invoice_total_net'] !== null)
                            von {{ $money($checks['invoice_total_net']) }}
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border {{ $checks['prices_match'] ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }} px-3 py-2">
                    <div class="text-[11px] {{ $checks['prices_match'] ? 'text-emerald-700' : 'text-amber-700' }} font-medium">
                        {{ $checks['prices_match'] ? 'Preise belegt' : 'Preise prüfen' }}
                    </div>
                    <div class="text-xs text-[var(--am-text-secondary)] mt-0.5">
                        Listenpreis minus Rabatt
                    </div>
                </div>

                <div class="rounded-lg border {{ $checks['quantities_balanced'] ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' }} px-3 py-2">
                    <div class="text-[11px] {{ $checks['quantities_balanced'] ? 'text-emerald-700' : 'text-red-700' }} font-medium">
                        {{ $checks['quantities_balanced'] ? 'Mengen vollständig verteilt' : 'Mengenbilanz stimmt nicht' }}
                    </div>
                    <div class="text-xs text-[var(--am-text-secondary)] tabular-nums mt-0.5">
                        {{ $seats($alloc['seats']) }} Seats · {{ $seats($alloc['overflow_seats']) }} Überhang · {{ $seats($alloc['pool_seats']) }} Pool
                    </div>
                </div>
            </div>

            @unless($checks['prices_match'])
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                    Bei diesen Positionen passt der Monatspreis nicht zum abgerechneten Betrag — Listenpreis
                    oder Rabattsatz der Datei sind unbrauchbar:
                    <ul class="list-disc list-inside mt-1">
                        @foreach($checks['price_mismatches'] as $mismatch)
                            <li>{{ $mismatch['edition'] }} (Abweichung {{ $money($mismatch['difference'] ?? 0) }})</li>
                        @endforeach
                    </ul>
                </div>
            @endunless

            {{-- Wohin das Geld geht --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach([
                    ['Rechnung (Monat)', $alloc['invoice_amount'], 'Monatsvolle Mengen × Preis'],
                    ['Auf Trägern',      $alloc['seat_amount'],    $seats($alloc['seats']) . ' Seats mit Beleg'],
                    ['IT-Gemeinkosten',  $alloc['overflow_amount'], $seats($alloc['overflow_seats']) . ' Monats-Seats über Bedarf'],
                    ['Lizenzpool IT',    $alloc['pool_amount'],    $seats($alloc['pool_seats']) . ' bezahlt, unbesetzt'],
                ] as [$label, $value, $hint])
                    <div class="rounded-lg border border-[var(--am-border)] px-3 py-2">
                        <div class="text-[11px] text-[var(--am-text-muted)]">{{ $label }}</div>
                        <div class="text-sm font-semibold tabular-nums">{{ $money($value) }}</div>
                        <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5">{{ $hint }}</div>
                    </div>
                @endforeach
            </div>

            @if(abs((float) $checks['snapshot_difference']) >= 0.01)
                <p class="text-[11px] text-[var(--am-text-muted)]">
                    Differenz zur Datei: <span class="tabular-nums">{{ $money($checks['snapshot_difference']) }}</span> —
                    erwartet. Ein Seat kostet den vollen Monatspreis, auch wenn die Rechnung ihn nur für
                    einen Teil des Monats berechnet hat (Stand zum Monatsersten, keine Seat-Tage).
                </p>
            @endif

            {{-- Vertragszeilen --}}
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="text-left text-[var(--am-text-muted)] border-b border-[var(--am-border)]">
                            <th class="py-1.5 pr-3">Position</th>
                            <th class="py-1.5 pr-3">Lizenz</th>
                            <th class="py-1.5 pr-3">Bindung</th>
                            <th class="py-1.5 pr-3 text-right">Menge</th>
                            <th class="py-1.5 pr-3 text-right">Liste</th>
                            <th class="py-1.5 pr-3 text-right">Rabatt</th>
                            <th class="py-1.5 pr-3 text-right">Monatspreis</th>
                            <th class="py-1.5 text-right">Summe</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lic['lines'] as $line)
                            <tr class="border-b border-[var(--am-border-subtle)] last:border-0">
                                <td class="py-1.5 pr-3">{{ $line['edition'] }}</td>
                                <td class="py-1.5 pr-3">
                                    @if($line['matched'])
                                        <span class="font-mono text-[10px]">{{ $line['sku'] }}</span>
                                    @else
                                        <x-asset-manager-badge color="amber">nicht zugeordnet</x-asset-manager-badge>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-3">
                                    <x-asset-manager-badge :color="$line['binding'] === 'P1Y' ? 'sky' : 'slate'">
                                        {{ $line['binding'] === 'P1Y' ? 'Jahr' : 'Monat' }}
                                    </x-asset-manager-badge>
                                </td>
                                <td class="py-1.5 pr-3 text-right tabular-nums">{{ $seats($line['quantity']) }}</td>
                                <td class="py-1.5 pr-3 text-right tabular-nums text-[var(--am-text-muted)]">
                                    {{ $line['list_price'] !== null ? $money($line['list_price']) : '—' }}
                                </td>
                                <td class="py-1.5 pr-3 text-right tabular-nums">
                                    {{ $line['discount'] > 0 ? '−' . number_format($line['discount'], 1, ',', '.') . ' %' : '—' }}
                                </td>
                                <td class="py-1.5 pr-3 text-right tabular-nums font-medium">{{ $money($line['unit_price']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $money($line['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($lic['unmatched'] !== [])
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                    <strong>{{ count($lic['unmatched']) }} Position(en) ohne Lizenz</strong> — diese Beträge
                    fließen noch in keine Kostenstelle. Zuordnen unter „Lizenzen → Vertragszeilen":
                    <ul class="list-disc list-inside mt-1">
                        @foreach($lic['unmatched'] as $open)
                            <li>{{ $open['edition'] }} — {{ $money($open['amount']) }} ({{ $seats($open['quantity']) }} Stück)</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($lic['price_moves'] !== [])
                <div class="rounded-lg bg-sky-50 border border-sky-200 px-3 py-2 text-xs text-sky-900">
                    Handgepflegte Stückpreise abgelöst — ab jetzt gilt der Rechnungspreis:
                    <ul class="list-disc list-inside mt-1">
                        @foreach($lic['price_moves'] as $move)
                            <li>{{ $move['display_name'] ?? $move['sku'] }}: war {{ $money($move['previous_price']) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Befunde je Lizenz: bezahlt ohne Nutzung, genutzt ohne Bezahlung, ungepflegte Konten --}}
            @php
                $findings = collect($lic['allocation']['skus'])
                    ->filter(fn ($s) => $s['unpaid_seats'] > 0 || $s['pool_seats'] > 0
                        || $s['overflow_seats'] > 0 || $s['incomplete_function_accounts'] > 0);
            @endphp

            @if($findings->isNotEmpty())
                <div class="rounded-lg border border-[var(--am-border)] px-3 py-2">
                    <div class="text-[11px] font-medium text-[var(--am-text-secondary)] mb-1.5">Befunde</div>
                    <ul class="space-y-1 text-xs text-[var(--am-text-secondary)]">
                        @foreach($findings as $finding)
                            <li>
                                <span class="font-mono text-[10px]">{{ $finding['sku_part_number'] ?? $finding['sku_id'] }}</span>
                                @if($finding['overflow_seats'] > 0)
                                    · {{ $seats($finding['overflow_seats']) }} Monats-Seats
                                    ({{ $money($finding['overflow_amount']) }}) auf Jahresbindung umstellbar
                                @endif
                                @if($finding['pool_seats'] > 0)
                                    · {{ $seats($finding['pool_seats']) }} bezahlt und unbesetzt
                                    ({{ $money($finding['pool_amount']) }})
                                @endif
                                @if($finding['unpaid_seats'] > 0)
                                    · {{ $seats($finding['unpaid_seats']) }} zugewiesen ohne Bezahlung
                                @endif
                                @if($finding['incomplete_function_accounts'] > 0)
                                    · {{ $seats($finding['incomplete_function_accounts']) }} Funktionskonto/-konten
                                    ohne System oder Kostenstelle
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif
