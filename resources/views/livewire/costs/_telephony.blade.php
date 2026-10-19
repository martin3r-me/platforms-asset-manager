{{-- Telefonie & Festnetz (#931): RingCentral-Benutzerliste + Vodafone-Festnetzrechnung. --}}
@php
    $money = fn ($value) => number_format((float) $value, 2, ',', '.') . ' €';
    $fileInput = 'block w-full text-sm text-[var(--am-text-secondary)] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-[var(--am-primary)] file:text-[var(--am-on-primary)] hover:file:opacity-90 file:cursor-pointer cursor-pointer';
@endphp

{{-- ── 1. RingCentral-Benutzerliste ─────────────────────────────────────────────────── --}}
<x-asset-manager-panel title="RingCentral-Benutzerliste importieren">
    <div class="space-y-5">
        <div>
            <p class="text-xs text-[var(--am-text-secondary)]">
                Export <strong>„Download User List"</strong> aus dem RingCentral-Admin-Portal (.xlsx). Hält fest,
                <strong>wer</strong> einen Telefonie-Platz belegt — die Vodafone-Rechnung nennt nur die Menge.
                Jeder Upload ersetzt den bisherigen Stand.
            </p>
            <p class="text-[11px] text-[var(--am-text-muted)] mt-1">
                Zuordnung über die E-Mail-Adresse, sonst über einen eindeutigen Namen. Plätze ohne Person
                (Konferenztelefon, Lager) gehen beim Festnetz-Import auf die Telefonie-Sammelstelle.
            </p>
        </div>

        <div>
            <label class="block text-xs text-[var(--am-text-secondary)] mb-1.5">Benutzerliste (.xlsx)</label>
            <input type="file" wire:model="seatFile" accept=".xlsx" class="{{ $fileInput }}">
            @error('seatFile')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror
            <div wire:loading wire:target="seatFile" class="text-[11px] text-[var(--am-text-secondary)] mt-1">Lade hoch …</div>
            @if($seatFile && !$errors->has('seatFile'))
                <div class="text-[11px] text-emerald-700 mt-1">
                    @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ $seatFile->getClientOriginalName() }} bereit
                </div>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <x-asset-manager-button variant="ghost" size="sm" wire:click="previewSeats" wire:loading.attr="disabled" wire:target="previewSeats,runSeatImport" :disabled="!$seatFile">
                @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                Vorschau (Dry-Run)
            </x-asset-manager-button>
            <x-asset-manager-button variant="primary" size="md" wire:click="runSeatImport" wire:loading.attr="disabled" wire:target="previewSeats,runSeatImport"
                    wire:confirm="Benutzerliste übernehmen? Der bisherige Stand der Telefonie-Plätze wird ersetzt."
                    :disabled="!$seatFile">
                @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                Übernehmen
            </x-asset-manager-button>
            <span wire:loading wire:target="previewSeats,runSeatImport" class="text-xs text-[var(--am-accent)] ml-1">
                @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
            </span>
        </div>
    </div>
</x-asset-manager-panel>

@if($seatError)
    <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
        @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') {{ $seatError }}
    </div>
@endif

@if($seatResult)
    <div class="rounded-xl bg-[var(--am-surface)] border {{ $seatWasDryRun ? 'border-amber-200' : 'border-emerald-200' }} shadow-sm overflow-hidden">
        <div class="px-4 py-2.5 text-xs font-semibold {{ $seatWasDryRun ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
            RingCentral-Benutzerliste — {{ $seatWasDryRun ? 'Vorschau (Dry-Run — nichts geschrieben)' : 'übernommen' }}
        </div>
        <div class="p-4 space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach([
                    ['Plätze', $seatResult['seats'], 'eine Lizenz je Platz'],
                    ['Mit Träger', $seatResult['matched'], $seatResult['matched_name'] > 0 ? $seatResult['matched_name'] . ' über den Namen' : 'über die E-Mail'],
                    ['Ohne Träger', count($seatResult['unmatched']), '→ Sammelstelle'],
                    ['Nicht aktiv', count($seatResult['not_active']), 'bezahlt, ungenutzt'],
                ] as [$label, $value, $hint])
                    <div class="rounded-lg border border-[var(--am-border)] px-3 py-2">
                        <div class="text-[11px] text-[var(--am-text-muted)]">{{ $label }}</div>
                        <div class="text-sm font-semibold tabular-nums">{{ $value }}</div>
                        <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5">{{ $hint }}</div>
                    </div>
                @endforeach
            </div>

            @unless($seatResult['first_upload'])
                <p class="text-xs text-[var(--am-text-secondary)] m-0">
                    Gegenüber dem letzten Stand: <strong>{{ count($seatResult['added']) }}</strong> neu{{ $seatResult['added'] !== [] ? ' (' . implode(', ', array_slice($seatResult['added'], 0, 8)) . (count($seatResult['added']) > 8 ? ' …' : '') . ')' : '' }},
                    <strong>{{ $seatResult['removed'] }}</strong> entfallen.
                </p>
            @endunless

            @if($seatResult['unmatched'] !== [])
                <div class="rounded-lg border border-[var(--am-border)] px-3 py-2">
                    <div class="text-[11px] font-medium text-[var(--am-text-secondary)] mb-1.5">Plätze ohne Träger</div>
                    <ul class="space-y-0.5 text-xs text-[var(--am-text-secondary)]">
                        @foreach($seatResult['unmatched'] as $open)
                            <li>{{ $open['name'] }} <span class="text-[var(--am-text-muted)]">· {{ $open['email'] }} · {{ $open['status'] }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($seatResult['not_active'] !== [])
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                    <strong>{{ count($seatResult['not_active']) }} Plätze nicht aktiviert oder deaktiviert</strong> — sie werden
                    bezahlt. Prüfen, ob sie beim Anbieter gelöscht werden können:
                    {{ implode(', ', array_map(fn ($s) => $s['name'] . ' (' . $s['status'] . ')', $seatResult['not_active'])) }}
                </div>
            @endif
        </div>
    </div>
@endif

{{-- ── 2. Vodafone-Festnetzrechnung ─────────────────────────────────────────────────── --}}
<x-asset-manager-panel title="Vodafone-Festnetz importieren (Internet & RingCentral)">
    <div class="space-y-5">
        <div>
            <p class="text-xs text-[var(--am-text-secondary)]">
                <strong>„Rechnung CSV"</strong> eines Festnetzkontos aus der Vodafone-Rechnungs-Analyse (Detailseite der
                Rechnung). Eine Datei je Konto. Bucht die Internetleitung auf ihre Kostenstelle, die RingCentral-Lizenzen
                je belegtem Platz auf die Kostenstelle der Person und Anlagenanschluss, Verbindungen und unbesetzte Plätze
                auf die <strong>Telefonie-Sammelstelle</strong> (Moduleinstellungen).
            </p>
            <p class="text-[11px] text-[var(--am-text-muted)] mt-1">
                Vorher die aktuelle RingCentral-Benutzerliste übernehmen. Die Lizenzrechnung (Microsoft 365 über Vodafone)
                gehört nicht hierher — sie kommt über „Lizenz-Abrechnung" und wird abgewiesen.
            </p>
        </div>

        <div>
            <label class="block text-xs text-[var(--am-text-secondary)] mb-1.5">Rechnung CSV (.csv)</label>
            <input type="file" wire:model="fixednetFile" accept=".csv" class="{{ $fileInput }}">
            @error('fixednetFile')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror
            <div wire:loading wire:target="fixednetFile" class="text-[11px] text-[var(--am-text-secondary)] mt-1">Lade hoch …</div>
            @if($fixednetFile && !$errors->has('fixednetFile'))
                <div class="text-[11px] text-emerald-700 mt-1">
                    @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ $fixednetFile->getClientOriginalName() }} bereit
                </div>
            @endif
        </div>

        @if($fixednetResult && $fixednetResult['unknown_lines'] !== [])
            <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-3 space-y-2">
                <div class="text-xs text-amber-800">
                    <strong>Leitung zuordnen:</strong> Diese Anschlusskennung kennt das Inventar noch nicht. Ist es eine
                    bestehende Leitung (z. B. aus der Excel), hier auswählen — sonst wird eine neue angelegt und die alte
                    Zeile zählt weiter mit. Danach Vorschau erneut starten.
                </div>
                @foreach($fixednetResult['unknown_lines'] as $kennung)
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs w-32 shrink-0">{{ $kennung }}</span>
                        <x-asset-manager-select wire:model="lineMapping.{{ $kennung }}">
                            <option value="">— neue Leitung anlegen —</option>
                            @foreach($internetLines as $line)
                                <option value="{{ $line->id }}">{{ $line->name }}</option>
                            @endforeach
                        </x-asset-manager-select>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-2">
            <x-asset-manager-button variant="ghost" size="sm" wire:click="previewFixedNet" wire:loading.attr="disabled" wire:target="previewFixedNet,runFixedNetImport" :disabled="!$fixednetFile">
                @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                Vorschau (Dry-Run)
            </x-asset-manager-button>
            <x-asset-manager-button variant="primary" size="md" wire:click="runFixedNetImport" wire:loading.attr="disabled" wire:target="previewFixedNet,runFixedNetImport"
                    wire:confirm="Import wirklich schreiben? Abgelöste Excel-Zeilen (Internet dieses Kontos, RingCentral) werden dabei entfernt."
                    :disabled="!$fixednetFile">
                @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                Import starten
            </x-asset-manager-button>
            <span wire:loading wire:target="previewFixedNet,runFixedNetImport" class="text-xs text-[var(--am-accent)] ml-1">
                @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
            </span>
        </div>
    </div>
</x-asset-manager-panel>

@if($fixednetError)
    <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
        @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') {{ $fixednetError }}
    </div>
@endif

@if($fixednetResult)
    @php
        $fn  = $fixednetResult;
        $tel = $fn['telephony'];
    @endphp
    <div class="rounded-xl bg-[var(--am-surface)] border {{ $fixednetWasDryRun ? 'border-amber-200' : 'border-emerald-200' }} shadow-sm overflow-hidden">
        <div class="px-4 py-2.5 text-xs font-semibold {{ $fixednetWasDryRun ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
            Festnetz {{ $fn['account'] }} · {{ $fn['period'] }} —
            {{ $fixednetWasDryRun ? 'Vorschau (Dry-Run — nichts geschrieben)' : 'Import abgeschlossen' }}
        </div>
        <div class="p-4 space-y-4">
            {{-- Prüfsumme zuerst: ohne sie ist jede Zahl darunter wertlos. --}}
            <div class="rounded-lg border {{ $fn['reconciles'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700' }} px-3 py-2 text-xs">
                @if($fn['reconciles'])
                    Positionen ergeben das Rechnungsnetto: <strong class="tabular-nums">{{ $money($fn['total_net']) }}</strong>
                @else
                    Positionen weichen um <strong class="tabular-nums">{{ $money($fn['difference']) }}</strong> vom Rechnungsnetto ({{ $money($fn['total_net']) }}) ab — Datei unvollständig?
                @endif
            </div>

            <table class="min-w-full text-xs">
                <thead>
                    <tr class="text-left text-[var(--am-text-muted)] border-b border-[var(--am-border)]">
                        <th class="py-1.5 pr-3">Block</th>
                        <th class="py-1.5 pr-3">Ziel</th>
                        <th class="py-1.5 text-right">Betrag/Monat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($fn['internet'] as $line)
                        <tr class="border-b border-[var(--am-border)]">
                            <td class="py-1.5 pr-3">Internet {{ $line['item'] }} <span class="font-mono text-[10px] text-[var(--am-text-muted)]">{{ $line['kennung'] }}</span></td>
                            <td class="py-1.5 pr-3">{{ $line['created'] ? 'neue Leitung' : 'Kostenstelle der Leitung' }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $money($line['amount']) }}</td>
                        </tr>
                    @endforeach
                    @if($tel)
                        <tr class="border-b border-[var(--am-border)]">
                            <td class="py-1.5 pr-3">RingCentral-Lizenzen auf Personen</td>
                            <td class="py-1.5 pr-3">{{ $tel['person_seats'] }} × {{ $money($tel['unit_net']) }} · Kostenstelle der Person</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $money($tel['persons_amount']) }}</td>
                        </tr>
                        <tr class="border-b border-[var(--am-border)]">
                            <td class="py-1.5 pr-3">RingCentral-Lizenzen ohne Person</td>
                            <td class="py-1.5 pr-3">{{ $tel['pool_seats'] }} Plätze · Sammelstelle</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $money($tel['pool_amount']) }}</td>
                        </tr>
                        <tr class="border-b border-[var(--am-border)]">
                            <td class="py-1.5 pr-3">Anlagenanschluss ({{ $tel['channels'] }} Kanäle)</td>
                            <td class="py-1.5 pr-3">Sammelstelle</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $money($tel['fixed_net']) }}</td>
                        </tr>
                        <tr class="border-b border-[var(--am-border)]">
                            <td class="py-1.5 pr-3">Verbindungen</td>
                            <td class="py-1.5 pr-3">Sammelstelle</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $money($tel['usage_net']) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>

            @if($tel)
                <p class="text-[11px] text-[var(--am-text-muted)] m-0">
                    Rechnung: {{ $tel['invoice_seats'] }} Lizenzen · Benutzerliste: {{ $tel['list_seats'] }} Plätze, davon
                    {{ $tel['person_seats'] }} mit Person.
                    @if($fn['removed_excel'] > 0)
                        {{ $fn['removed_excel'] }} Excel-Zeile(n) {{ $fixednetWasDryRun ? 'würden' : 'wurden' }} abgelöst.
                    @endif
                </p>
            @endif

            @if($fn['findings'] !== [])
                <div class="rounded-lg border border-[var(--am-border)] px-3 py-2">
                    <div class="text-[11px] font-medium text-[var(--am-text-secondary)] mb-1.5">Befunde</div>
                    <ul class="list-disc list-inside space-y-1 text-xs text-[var(--am-text-secondary)]">
                        @foreach($fn['findings'] as $finding)
                            <li>{{ $finding }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif
