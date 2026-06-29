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
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Funktionskonten gelöscht</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $resetResult['employees'] }}</td></tr>
                                <tr><td class="px-4 py-2 text-[var(--am-text-secondary)]">Mitarbeiter-Kostenstellen zurückgesetzt</td><td class="px-4 py-2 text-right tabular-nums font-medium text-[var(--am-text)]">{{ $resetResult['cleared_cost_centers'] }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Danger-Zone: Import zurücksetzen --}}
            @if($canManage)
                <div class="rounded-xl bg-rose-50 border border-rose-200 p-5 space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold text-rose-700">Import zurücksetzen</h3>
                        <p class="text-xs text-[var(--am-text-secondary)] mt-1">
                            Löscht <strong>alle importierten Kostenpositionen</strong>, die import-erzeugten
                            <strong>Assets</strong> (Laptop/Internet/Drucker) und die fälschlich angelegten
                            <strong>Funktionskonten</strong> (<code>{{ '@funktion.import.local' }}</code>) und setzt die
                            <strong>Kostenstellen aller Mitarbeiter</strong> zurück.
                            Stammdaten (Gesellschaften, Kostenstellen, Kreditoren, Kostenarten) sowie
                            Intune-Geräte bleiben erhalten.
                        </p>
                    </div>
                    <x-asset-manager-button variant="danger" size="sm" wire:click="resetImport" wire:loading.attr="disabled" wire:target="resetImport"
                            wire:confirm="Wirklich ALLE importierten Kosten, Funktionskonten und Import-Assets löschen und die Kostenstellen aller Mitarbeiter zurücksetzen? Stammdaten bleiben erhalten.">
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
