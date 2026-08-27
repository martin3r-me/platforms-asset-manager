<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kosten-Import', 'icon' => 'arrow-up-tray'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5 max-w-3xl">

            <x-asset-manager-panel title="Kostenaufteilung importieren">
                <div class="space-y-5">
                    <div>
                        <p class="text-xs text-[var(--am-text-secondary)]">
                            Lade <strong>Kostenaufteilung_IT.xlsx</strong> hoch. Erst <em>Vorschau (Dry-Run)</em> — schreibt nichts —
                            und bei stimmigen Zahlen <em>Import starten</em>. Wiederholter Import überschreibt statt zu duplizieren.
                        </p>
                        <p class="text-[11px] text-[var(--am-text-muted)] mt-1">
                            Importiert werden Opex-Kostenarten (Mobilfunk, Leasing, Internet, Drucker, Abos, BPEvent, HGK, necta).
                            MS-Lizenzen &amp; gekaufte Hardware kommen aus dem Graph-Sync bzw. Inventar.
                        </p>
                    </div>

                    {{-- Upload --}}
                    <div>
                        <label class="block text-xs text-[var(--am-text-secondary)] mb-1.5">Excel-Datei (.xlsx)</label>
                        <input type="file" wire:model="file" accept=".xlsx,.xls"
                               class="block w-full text-sm text-[var(--am-text-secondary)] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-[var(--am-primary)] file:text-[var(--am-on-primary)] hover:file:opacity-90 file:cursor-pointer cursor-pointer">
                        @error('file')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror

                        <div wire:loading wire:target="file" class="text-[11px] text-[var(--am-text-secondary)] mt-1">Lade hoch …</div>

                        @if($file && !$errors->has('file'))
                            <div class="text-[11px] text-emerald-700 mt-1">
                                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ $file->getClientOriginalName() }} bereit
                            </div>
                        @endif
                    </div>

                    {{-- Aktionen --}}
                    <div class="flex items-center gap-2">
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="preview" wire:loading.attr="disabled" wire:target="preview,runImport,file" :disabled="!$file">
                            @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                            Vorschau (Dry-Run)
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="primary" size="md" wire:click="runImport" wire:loading.attr="disabled" wire:target="preview,runImport,file"
                                wire:confirm="Import wirklich schreiben? (idempotent — überschreibt vorherigen Upload-Batch)"
                                :disabled="!$file">
                            @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                            Import starten
                        </x-asset-manager-button>
                        <span wire:loading wire:target="preview,runImport" class="text-xs text-[var(--am-accent)] ml-1">
                            @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
                        </span>
                    </div>
                </div>
            </x-asset-manager-panel>

            {{-- Fehler --}}
            @if($error)
                <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') {{ $error }}
                </div>
            @endif

            {{-- Ergebnis --}}
            @if($result)
                <div class="rounded-xl bg-[var(--am-surface)] border {{ $wasDryRun ? 'border-amber-200' : 'border-emerald-200' }} shadow-sm overflow-hidden">
                    <div class="px-4 py-2.5 text-xs font-semibold {{ $wasDryRun ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
                        {{ $wasDryRun ? 'Vorschau (Dry-Run — nichts geschrieben)' : 'Import abgeschlossen' }}
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($result as $sheet => $value)
                                    @if($sheet === '_mode') @continue @endif
                                    <tr class="hover:bg-[var(--am-bg)]">
                                        <td class="px-4 py-2 text-[var(--am-text-secondary)] capitalize">{{ $sheet }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">
                                            {{ is_int($value) ? $value . ' Positionen' : $value }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($wasDryRun)
                        <div class="px-4 py-2.5 text-[11px] text-[var(--am-text-secondary)] border-t border-[color:var(--am-border)]">
                            Zahlen ok? Dann auf <strong>Import starten</strong> klicken. Danach unter
                            <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="text-[var(--am-accent)] hover:underline">Kostenaufteilung</a> prüfen.
                        </div>
                    @else
                        <div class="px-4 py-2.5 text-[11px] text-[var(--am-text-secondary)] border-t border-[color:var(--am-border)]">
                            → <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="text-[var(--am-accent)] hover:underline">Kostenaufteilung ansehen</a>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ── Vodafone-Rechnungsanalyse (ADR 0018) ───────────────────────────────────── --}}
            <x-asset-manager-panel title="Vodafone-Rechnungsanalyse importieren">
                <div class="space-y-5">
                    <div>
                        <p class="text-xs text-[var(--am-text-secondary)]">
                            Export <strong>„Rechnungsdaten"</strong> aus dem Vodafone-Firmenkundenportal
                            (Rechnungs-Analyse). Legt je Rufnummer eine Kostenposition der Kostenart
                            <strong>Mobilfunk</strong> an und ordnet sie über die Rufnummer dem Asset-Träger zu.
                        </p>
                        <p class="text-[11px] text-[var(--am-text-muted)] mt-1">
                            Seit diesem Import ist die Rechnung die <strong>einzige</strong> Quelle für Mobilfunk —
                            die entsprechende Spalte der Kostenaufteilungs-Excel wird nicht mehr gelesen, und
                            deren alte Mobilfunk-Zeilen werden beim ersten Lauf entfernt.
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs text-[var(--am-text-secondary)] mb-1.5">Rechnungsdaten (.xlsx)</label>
                        <input type="file" wire:model="vodafoneFile" accept=".xlsx"
                               class="block w-full text-sm text-[var(--am-text-secondary)] file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-[var(--am-primary)] file:text-[var(--am-on-primary)] hover:file:opacity-90 file:cursor-pointer cursor-pointer">
                        @error('vodafoneFile')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror

                        <div wire:loading wire:target="vodafoneFile" class="text-[11px] text-[var(--am-text-secondary)] mt-1">Lade hoch …</div>

                        @if($vodafoneFile && !$errors->has('vodafoneFile'))
                            <div class="text-[11px] text-emerald-700 mt-1">
                                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ $vodafoneFile->getClientOriginalName() }} bereit
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="previewVodafone" wire:loading.attr="disabled" wire:target="previewVodafone,runVodafoneImport,vodafoneFile" :disabled="!$vodafoneFile">
                            @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                            Vorschau (Dry-Run)
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="primary" size="md" wire:click="runVodafoneImport" wire:loading.attr="disabled" wire:target="previewVodafone,runVodafoneImport,vodafoneFile"
                                wire:confirm="Import wirklich schreiben? Die Mobilfunk-Zeilen aus dem Excel-Import werden dabei entfernt."
                                :disabled="!$vodafoneFile">
                            @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                            Import starten
                        </x-asset-manager-button>
                        <span wire:loading wire:target="previewVodafone,runVodafoneImport" class="text-xs text-[var(--am-accent)] ml-1">
                            @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
                        </span>
                    </div>
                </div>
            </x-asset-manager-panel>

            @if($vodafoneError)
                <div class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') {{ $vodafoneError }}
                </div>
            @endif

            @if($vodafoneResult)
                @php $v = $vodafoneResult; $s = $v['stats']; @endphp
                <div class="rounded-xl bg-[var(--am-surface)] border {{ $vodafoneWasDryRun ? 'border-amber-200' : 'border-emerald-200' }} shadow-sm overflow-hidden">
                    <div class="px-4 py-2.5 text-xs font-semibold {{ $vodafoneWasDryRun ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
                        Vodafone — {{ $vodafoneWasDryRun ? 'Vorschau (Dry-Run — nichts geschrieben)' : 'Import abgeschlossen' }}
                        @if($v['period']['from'] && $v['period']['to'])
                            · Zeitraum {{ $v['period']['from']->format('d.m.Y') }}–{{ $v['period']['to']->format('d.m.Y') }}
                        @endif
                    </div>

                    {{-- Prüfsumme zuerst: stimmt sie nicht, sind alle Zahlen darunter unvollständig. --}}
                    <div class="px-4 py-2.5 text-xs border-b border-[color:var(--am-border)] {{ $v['reconciles'] ? 'text-emerald-700' : 'text-red-700 bg-red-50' }}">
                        @if($v['reconciles'])
                            @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline')
                            Summe der Zeilen entspricht dem Rechnungsbetrag ({{ number_format($v['sum_net'], 2, ',', '.') }} € netto).
                        @else
                            @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline')
                            <strong>Summe weicht ab:</strong> Zeilen {{ number_format($v['sum_net'], 2, ',', '.') }} € gegen
                            Rechnungsbetrag {{ number_format($v['invoice_total_net'], 2, ',', '.') }} € — die Datei ist vermutlich gefiltert.
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Rufnummern</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $s['numbers'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">davon einem Asset-Träger zugeordnet</td><td class="px-4 py-2 text-right tabular-nums font-medium text-emerald-700">{{ $s['matched'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">ohne Treffer (Position wird trotzdem angelegt)</td><td class="px-4 py-2 text-right tabular-nums font-medium {{ $s['unmatched'] > 0 ? 'text-amber-700' : 'text-[var(--am-text)]' }}">{{ $s['unmatched'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Kontobezogene Posten (ohne Rufnummer)</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $s['account_items'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Mobilfunk-Zeilen aus dem Excel-Import entfernt</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $v['removed_excel_rows'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Zeilen früherer Läufe entfernt (gekündigte Nummern)</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $v['pruned_rows'] }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    @if(!empty($s['unknown_centers']))
                        <div class="px-4 py-2.5 text-[11px] text-amber-700 bg-amber-50 border-t border-[color:var(--am-border)]">
                            <strong>Unbekannte Kostenstellen im Export:</strong> {{ implode(', ', $s['unknown_centers']) }} —
                            die betroffenen Positionen bleiben ohne Kostenstelle. Erst in den Stammdaten anlegen, dann erneut importieren.
                        </div>
                    @endif

                    @if(!empty($s['cost_center_diff']))
                        <div class="px-4 py-2.5 border-t border-[color:var(--am-border)]">
                            <div class="text-[11px] font-semibold text-[var(--am-text-secondary)] mb-1.5">
                                Abweichende Kostenstelle ({{ count($s['cost_center_diff']) }}) — gebucht wird auf <em>unsere</em> Zuordnung
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-[11px]">
                                    <thead>
                                        <tr class="text-[var(--am-text-muted)] uppercase tracking-wider">
                                            <th class="text-left py-1">Rufnummer</th><th class="text-left py-1">Asset-Träger</th>
                                            <th class="text-left py-1">bei Vodafone</th><th class="text-left py-1">bei uns</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[color:var(--am-border)]">
                                        @foreach($s['cost_center_diff'] as $d)
                                            <tr>
                                                <td class="py-1 font-mono">{{ $d['msisdn'] }}</td>
                                                <td class="py-1">{{ $d['holder'] }}</td>
                                                <td class="py-1">{{ $d['vodafone'] }}</td>
                                                <td class="py-1">{{ $d['ours'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="px-4 py-2.5 text-[11px] text-[var(--am-text-secondary)] border-t border-[color:var(--am-border)]">
                        → <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="text-[var(--am-accent)] hover:underline">Kostenaufteilung ansehen</a>
                    </div>
                </div>
            @endif

            {{-- Reset-Ergebnis --}}
            @if($resetResult)
                <div class="rounded-xl bg-[var(--am-surface)] border border-rose-200 shadow-sm overflow-hidden">
                    <div class="px-4 py-2.5 text-xs font-semibold bg-rose-50 text-rose-700">
                        Import zurückgesetzt
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Kostenpositionen gelöscht</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $resetResult['cost_lines'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Import-Assets gelöscht (Laptop/Internet/Drucker)</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $resetResult['assets'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Funktionskonten gelöscht</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $resetResult['holders'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Kostenstellen der Funktionskonten zurückgesetzt</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $resetResult['cleared_cost_centers'] }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- ── Lizenz-Abrechnung des Wiederverkäufers (ADR 0019) ──────────────────────── --}}
            @include('asset-manager::livewire.costs._license-invoice')

            {{-- Danger-Zone: Import zurücksetzen --}}
            @if($canManage)
                <div class="rounded-xl bg-rose-50 border border-rose-200 p-5 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-rose-700">Import zurücksetzen</h3>
                        <p class="text-xs text-[var(--am-text-secondary)] mt-1">
                            Löscht <strong>alle importierten Kostenpositionen</strong>, die import-erzeugten
                            <strong>Assets</strong> (Laptop/Internet/Drucker) und die fälschlich angelegten
                            <strong>Funktionskonten</strong> (<code>{{ '@funktion.import.local' }}</code>) und setzt die
                            <strong>Kostenstellen aller Asset-Träger</strong> zurück.
                            Stammdaten (Gesellschaften, Kostenstellen, Kreditoren, Kostenarten) sowie
                            Intune-Geräte bleiben erhalten.
                        </p>
                    </div>
                    <x-asset-manager-button variant="danger" size="sm" wire:click="resetImport" wire:loading.attr="disabled" wire:target="resetImport"
                            wire:confirm="Wirklich ALLE importierten Kosten, Funktionskonten und Import-Assets löschen und die Kostenstellen aller Asset-Träger zurücksetzen? Stammdaten bleiben erhalten.">
                        @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                        Import zurücksetzen
                    </x-asset-manager-button>
                    <span wire:loading wire:target="resetImport" class="text-xs text-rose-600 ml-1">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') lösche …
                    </span>
                </div>
            @endif

        </div>
    </div>
</x-ui-page>
