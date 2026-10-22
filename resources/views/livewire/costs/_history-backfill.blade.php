{{-- Preis-Historie nachtragen (ADR 0029): ältere Rechnungen nur in den Verlauf, ohne Kosten/DATEV zu ändern. --}}
@if($canManage)
    <x-asset-manager-panel title="Preis-Historie nachtragen">
        <div class="space-y-5">
            <div>
                <p class="text-xs text-[var(--am-text-secondary)]">
                    Ältere Vodafone-Rechnungen nur in den <strong>Preisverlauf</strong> übernehmen — Mobilfunk
                    („Rechnungsdaten") und Festnetz („Rechnung CSV") gemischt, der Typ wird je Datei erkannt.
                </p>
                <p class="text-[11px] text-[var(--am-text-muted)] mt-1">
                    Ändert <strong>keine</strong> aktuellen Kosten, keine Monatsstände und keine DATEV-Daten. Dieselbe
                    Rechnung erneut hochladen überschreibt nur ihren Verlaufseintrag. Festnetz: die Plätze kommen aus der
                    heutigen RingCentral-Liste — Beträge stimmen, die Zuordnung zur Person ist für alte Monate eine Näherung.
                </p>
            </div>

            <div>
                <label class="block text-xs text-[var(--am-text-secondary)] mb-1.5">Rechnungsdateien (.csv / .xlsx, höchstens 20 je Durchgang)</label>
                {{-- PHP nimmt je Upload höchstens 20 Dateien an (max_file_uploads) und verwirft den Rest still —
                     deshalb hier vorher abfangen. .capture läuft vor dem Upload-Listener von wire:model. --}}
                <input type="file" wire:model="historyFiles" multiple accept=".xlsx,.csv"
                       x-on:change.capture="if ($event.target.files.length > 20) { $event.stopImmediatePropagation(); alert('Höchstens 20 Dateien je Durchgang — bitte in mehreren Runden hochladen.'); $event.target.value = ''; }"
                       class="block w-full text-sm text-[var(--am-text-secondary)] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-[var(--am-primary)] file:text-[var(--am-on-primary)] hover:file:opacity-90 file:cursor-pointer cursor-pointer">
                @error('historyFiles')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror
                <div wire:loading wire:target="historyFiles" class="text-[11px] text-[var(--am-text-secondary)] mt-1">Lade hoch …</div>
                @if(count((array) $historyFiles) > 0)
                    <div class="text-[11px] text-emerald-700 mt-1">
                        @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ count((array) $historyFiles) }} Datei(en) bereit
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <x-asset-manager-button variant="primary" size="md" wire:click="runHistoryBackfill" wire:loading.attr="disabled" wire:target="runHistoryBackfill"
                        :disabled="count((array) $historyFiles) === 0">
                    @svg('heroicon-o-clock', 'w-3.5 h-3.5')
                    In die Historie übernehmen
                </x-asset-manager-button>
                <span wire:loading wire:target="runHistoryBackfill" class="text-xs text-[var(--am-accent)] ml-1">
                    @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
                </span>
            </div>

            @if($historyResults !== null)
                <div class="overflow-x-auto rounded-lg border border-[color:var(--am-border)]">
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--am-bg)] text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">
                            <tr>
                                <th class="px-3 py-2 text-left">Datei</th>
                                <th class="px-3 py-2 text-left">Typ</th>
                                <th class="px-3 py-2 text-left">Rechnung</th>
                                <th class="px-3 py-2 text-left">Zeitraum</th>
                                <th class="px-3 py-2 text-right">Einträge</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($historyResults as $hr)
                                <tr>
                                    <td class="px-3 py-2 text-xs text-[var(--am-text-secondary)]">{{ $hr['file'] }}</td>
                                    <td class="px-3 py-2 text-xs">{{ $hr['type'] }}</td>
                                    <td class="px-3 py-2 text-xs tabular-nums">{{ $hr['invoice'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs tabular-nums">{{ $hr['period'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-right tabular-nums">
                                        @if($hr['error'])
                                            <span class="text-red-700">{{ $hr['error'] }}</span>
                                        @else
                                            {{ $hr['rows'] }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-asset-manager-panel>
@endif
