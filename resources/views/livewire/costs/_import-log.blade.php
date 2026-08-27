{{--
    Import-Protokoll: die letzten Läufe aller Quellen (Excel, Vodafone, Lizenz-Abrechnung).

    Ersetzt die frühere eigene Seite, die nur die importierten Kostenpositionen des Excel-Imports
    auflistete — also den Endzustand einer von drei Quellen, ohne Probeläufe, Befunde und Fehlschläge.
    Hier steht der Vorgang: wer wann was hochgeladen hat, was dabei herauskam und was auffiel.
--}}

<x-asset-manager-panel title="Import-Protokoll">
    @if($runs->isEmpty())
        <p class="text-xs text-[var(--am-text-secondary)] m-0">
            Noch keine Läufe protokolliert. Jeder Import und jede Vorschau erscheint hier — mit
            Kennzahlen, Befunden und Laufzeit.
        </p>
    @else
        <div class="space-y-2">
            @foreach($runs as $run)
                @php $isOpen = $expandedRunId === $run->id; @endphp

                <div class="rounded-lg border {{ $run->failed() ? 'border-red-200' : 'border-[color:var(--am-border)]' }} overflow-hidden">
                    {{-- Kopfzeile: immer sichtbar, klickbar --}}
                    <button type="button" wire:click="toggleRun({{ $run->id }})"
                            class="w-full text-left px-3 py-2 flex items-start gap-3 hover:bg-[var(--am-bg)] transition-colors">
                        @svg('heroicon-o-chevron-right', 'w-3.5 h-3.5 mt-1 flex-shrink-0 text-[var(--am-text-muted)] transition-transform ' . ($isOpen ? 'rotate-90' : ''))

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <x-asset-manager-badge :color="$run->sourceBadgeColor()" size="xs">
                                    {{ $run->sourceLabel() }}
                                </x-asset-manager-badge>
                                <x-asset-manager-badge :color="$run->statusBadgeColor()" size="xs">
                                    {{ $run->statusLabel() }}
                                </x-asset-manager-badge>
                                @if($run->dry_run)
                                    <x-asset-manager-badge color="slate" size="xs">Probelauf</x-asset-manager-badge>
                                @endif
                                @if($run->findings() !== [])
                                    <span class="text-[10px] text-amber-700">
                                        {{ count($run->findings()) }} {{ count($run->findings()) === 1 ? 'Befund' : 'Befunde' }}
                                    </span>
                                @endif
                            </div>

                            <div class="text-xs text-[var(--am-text)] mt-1 truncate">{{ $run->headline ?? '—' }}</div>

                            <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5 truncate">
                                {{ $run->created_at?->format('d.m.Y H:i') }}
                                @if($run->file_name) · {{ $run->file_name }} @endif
                                @if($run->durationLabel()) · {{ $run->durationLabel() }} @endif
                                @if($run->user) · {{ $run->user->name }} @endif
                            </div>
                        </div>
                    </button>

                    {{-- Detail: Kennzahlen und Befunde des Laufs --}}
                    @if($isOpen)
                        <div class="px-3 pb-3 pt-1 border-t border-[color:var(--am-border-subtle)] space-y-3">
                            @if($run->failed())
                                <div class="rounded-md bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-800">
                                    {{ $run->error }}
                                </div>
                            @endif

                            @if($run->metrics() !== [])
                                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1.5">
                                    @foreach($run->metrics() as $label => $value)
                                        <div class="min-w-0">
                                            <dt class="text-[10px] text-[var(--am-text-muted)] truncate">{{ $label }}</dt>
                                            <dd class="text-xs text-[var(--am-text)] tabular-nums truncate m-0">{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif

                            @if($run->findings() !== [])
                                <ul class="space-y-1">
                                    @foreach($run->findings() as $finding)
                                        <li class="flex gap-2 text-xs text-[var(--am-text-secondary)]">
                                            @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 flex-shrink-0 mt-0.5 text-amber-600')
                                            <span>{{ $finding }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if($run->dry_run && ! $run->failed())
                                <p class="text-[10px] text-[var(--am-text-muted)] m-0">
                                    Probelauf — es wurde nichts geschrieben. Die Zahlen zeigen, was ein
                                    scharfer Lauf bewirkt hätte.
                                </p>
                            @endif

                            @if($run->source === \Platform\AssetManager\Models\AssetImportRun::SOURCE_LICENSE_INVOICE && ! $run->dry_run && ! $run->failed())
                                <a href="{{ route('asset-manager.licenses.contracts') }}" wire:navigate
                                   class="inline-flex items-center gap-1 text-[11px] text-[var(--am-accent)] hover:underline">
                                    @svg('heroicon-o-document-currency-euro', 'w-3 h-3')
                                    Vertragszeilen dieses Monats ansehen
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-[11px] text-[var(--am-text-muted)] mt-3">
            Die letzten {{ $runs->count() }} Läufe. Probeläufe stehen mit drin — ihre Zahlen sind die
            Grundlage der Entscheidung, ob ein Import scharf laufen soll.
        </p>
    @endif
</x-asset-manager-panel>
