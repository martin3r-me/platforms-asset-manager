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

            <div class="rounded-xl bg-white border border-black/5 shadow-sm p-6 space-y-5">
                <div>
                    <h2 class="text-sm font-semibold text-[var(--ui-secondary)]">Kostenaufteilung importieren</h2>
                    <p class="text-xs text-[var(--ui-muted)] mt-1">
                        Lade <strong>Kostenaufteilung_IT.xlsx</strong> hoch. Erst <em>Vorschau (Dry-Run)</em> — schreibt nichts —
                        und bei stimmigen Zahlen <em>Import starten</em>. Wiederholter Import überschreibt statt zu duplizieren.
                    </p>
                    <p class="text-[11px] text-[var(--ui-muted)] mt-1">
                        Importiert werden Opex-Kostenarten (Mobilfunk, Leasing, Internet, Drucker, Abos, BPEvent, HGK, necta).
                        MS-Lizenzen &amp; gekaufte Hardware kommen aus dem Graph-Sync bzw. Inventar.
                    </p>
                </div>

                {{-- Upload --}}
                <div>
                    <label class="block text-xs text-gray-500 mb-1.5">Excel-Datei (.xlsx)</label>
                    <input type="file" wire:model="file" accept=".xlsx,.xls"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-violet-600 file:text-white hover:file:bg-violet-700 file:cursor-pointer cursor-pointer">
                    @error('file')<span class="text-[11px] text-red-500">{{ $message }}</span>@enderror

                    <div wire:loading wire:target="file" class="text-[11px] text-gray-400 mt-1">Lade hoch …</div>

                    @if($file && !$errors->has('file'))
                        <div class="text-[11px] text-emerald-600 mt-1">
                            @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 inline') {{ $file->getClientOriginalName() }} bereit
                        </div>
                    @endif
                </div>

                {{-- Aktionen --}}
                <div class="flex items-center gap-2">
                    <button wire:click="preview" wire:loading.attr="disabled" wire:target="preview,runImport,file"
                            @disabled(!$file)
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-black/[0.04] rounded-lg hover:bg-black/[0.07] disabled:opacity-40 transition-all">
                        @svg('heroicon-o-eye', 'w-3.5 h-3.5')
                        Vorschau (Dry-Run)
                    </button>
                    <button wire:click="runImport" wire:loading.attr="disabled" wire:target="preview,runImport,file"
                            wire:confirm="Import wirklich schreiben? (idempotent — überschreibt vorherigen Upload-Batch)"
                            @disabled(!$file)
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 disabled:opacity-40 transition-all">
                        @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                        Import starten
                    </button>
                    <span wire:loading wire:target="preview,runImport" class="text-xs text-violet-600 ml-1">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 inline animate-spin') verarbeite …
                    </span>
                </div>
            </div>

            {{-- Fehler --}}
            @if($error)
                <div class="rounded-xl bg-red-500/5 border border-red-500/20 p-4 text-sm text-red-700">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') {{ $error }}
                </div>
            @endif

            {{-- Ergebnis --}}
            @if($result)
                <div class="rounded-xl bg-white border {{ $wasDryRun ? 'border-amber-500/30' : 'border-emerald-500/30' }} shadow-sm overflow-hidden">
                    <div class="px-4 py-2.5 text-xs font-semibold {{ $wasDryRun ? 'bg-amber-500/10 text-amber-700' : 'bg-emerald-500/10 text-emerald-700' }}">
                        {{ $wasDryRun ? 'Vorschau (Dry-Run — nichts geschrieben)' : 'Import abgeschlossen' }}
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-black/[0.03]">
                            @foreach($result as $sheet => $value)
                                @if($sheet === '_mode') @continue @endif
                                <tr>
                                    <td class="px-4 py-2 text-gray-600 capitalize">{{ $sheet }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-medium text-gray-800">
                                        {{ is_int($value) ? $value . ' Positionen' : $value }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($wasDryRun)
                        <div class="px-4 py-2.5 text-[11px] text-gray-400 border-t border-black/5">
                            Zahlen ok? Dann auf <strong>Import starten</strong> klicken. Danach unter
                            <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="text-violet-500 hover:underline">Kostenaufteilung</a> prüfen.
                        </div>
                    @else
                        <div class="px-4 py-2.5 text-[11px] text-gray-400 border-t border-black/5">
                            → <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="text-violet-500 hover:underline">Kostenaufteilung ansehen</a>
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>
</x-ui-page>
