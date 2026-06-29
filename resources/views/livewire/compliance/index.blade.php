<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Sicherheit & Compliance', 'icon' => 'shield-check'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-6">

            @if(!$config || !$config->isConfigured() || $total === 0)
                <div class="flex flex-col items-center justify-center py-16 text-center rounded-xl bg-[var(--am-bg)] border border-dashed border-[color:var(--am-border)]">
                    @svg('heroicon-o-shield-check', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                    <h3 class="text-sm font-medium text-[var(--am-text-secondary)] mb-1">Noch keine Gerätedaten</h3>
                    <p class="text-xs text-[var(--am-text-muted)] mb-4 max-w-sm">
                        @if(!$config || !$config->isConfigured())
                            Richte zuerst den Intune-Connector ein, um Compliance- und Sicherheitsdaten zu sehen.
                        @else
                            Sobald Geräte synchronisiert sind, erscheinen hier Compliance-Quote, Verschlüsselung und Auffälligkeiten.
                        @endif
                    </p>
                    <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.setup') }}" wire:navigate>
                        @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4')
                        Connector
                    </x-asset-manager-button>
                </div>
            @else

            {{-- Kennzahlen --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-asset-manager-stat-card
                    label="Compliance-Quote"
                    :value="$complianceQuote.'%'"
                    sub="{{ $compliant }} von {{ $total }} konform"
                    accent="emerald"
                    value-class="{{ $complianceQuote >= 90 ? 'text-emerald-600' : ($complianceQuote >= 70 ? 'text-amber-600' : 'text-red-600') }}" />

                <x-asset-manager-stat-card
                    label="Verschlüsselt"
                    :value="$encQuote.'%'"
                    sub="{{ $encrypted }} verschlüsselt · {{ $encUnknown }} unbekannt"
                    accent="violet"
                    value-class="{{ $encQuote >= 90 ? 'text-emerald-600' : ($encQuote >= 70 ? 'text-amber-600' : 'text-red-600') }}" />

                <x-asset-manager-stat-card
                    label="Geräte gesamt"
                    :value="$total"
                    sub="aus Intune synchronisiert"
                    accent="navy" />
            </div>

            {{-- Verteilungen --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- Compliance --}}
                <x-asset-manager-panel title="Compliance-Verteilung">
                    <div class="space-y-3">
                        @foreach($complianceBreakdown as $row)
                            @php
                                $pct = $total > 0 ? round($row->count / $total * 100) : 0;
                                $color = match($row->compliance_state) {
                                    'compliant'=>'emerald','noncompliant'=>'red','inGracePeriod'=>'amber','error'=>'red','conflict'=>'orange',default=>'gray',
                                };
                                $label = match($row->compliance_state) {
                                    'compliant'=>'Konform','noncompliant'=>'Nicht konform','inGracePeriod'=>'Karenzzeit','error'=>'Fehler','conflict'=>'Konflikt',default=>'Unbekannt',
                                };
                            @endphp
                            <div>
                                <div class="flex items-baseline justify-between text-xs mb-1">
                                    <span class="inline-flex items-center gap-1.5 text-[var(--am-text-secondary)]">
                                        <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>{{ $label }}
                                    </span>
                                    <span class="text-[var(--am-text-muted)] tabular-nums">{{ $row->count }} ({{ $pct }}%)</span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                    <div class="h-full bg-{{ $color }}-500" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-asset-manager-panel>

                {{-- Verschlüsselung --}}
                <x-asset-manager-panel title="Verschlüsselung">
                    <div class="space-y-3">
                        @foreach([
                            ['Verschlüsselt', $encrypted, 'emerald'],
                            ['Nicht verschlüsselt', $unencrypted, 'red'],
                            ['Unbekannt', $encUnknown, 'gray'],
                        ] as [$label, $count, $color])
                            @php $pct = $total > 0 ? round($count / $total * 100) : 0; @endphp
                            <div>
                                <div class="flex items-baseline justify-between text-xs mb-1">
                                    <span class="inline-flex items-center gap-1.5 text-[var(--am-text-secondary)]">
                                        <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>{{ $label }}
                                    </span>
                                    <span class="text-[var(--am-text-muted)] tabular-nums">{{ $count }} ({{ $pct }}%)</span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                    <div class="h-full bg-{{ $color }}-500" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-asset-manager-panel>
            </div>

            {{-- OS-Versionen --}}
            <x-asset-manager-panel title="Betriebssystem-Versionen">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                    @foreach($osBreakdown as $row)
                        @php $pct = $total > 0 ? round($row->count / $total * 100) : 0; @endphp
                        <div>
                            <div class="flex items-baseline justify-between text-xs mb-1">
                                <span class="text-[var(--am-text-secondary)] truncate">{{ $row->os }} <span class="text-[var(--am-text-muted)]">{{ $row->version }}</span></span>
                                <span class="text-[var(--am-text-muted)] tabular-nums ml-2 flex-shrink-0">{{ $row->count }} ({{ $pct }}%)</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                <div class="h-full bg-[var(--am-accent)]" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-asset-manager-panel>

            {{-- Handlungsbedarf → verlinkt in die gefilterte Geräteliste --}}
            <x-asset-manager-panel title="Handlungsbedarf">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach([
                        ['noncompliant', 'Nicht konform', $noncompliant, 'red'],
                        ['unencrypted',  'Nicht verschlüsselt', $unencrypted, 'red'],
                        ['inactive',     'Inaktiv (>30 T.)', $stale, 'amber'],
                        ['expiring',     'Garantie/Leasing läuft ab', $expiring, 'amber'],
                    ] as [$preset, $label, $count, $color])
                        <a href="{{ route('asset-manager.devices.index', ['preset' => $preset]) }}" wire:navigate
                           class="flex flex-col gap-1 rounded-lg border border-[color:var(--am-border)] p-4 hover:bg-[var(--am-bg)] transition-colors">
                            <span class="text-2xl font-semibold tabular-nums {{ $count > 0 ? 'text-'.$color.'-700' : 'text-[var(--am-text)]' }}">{{ $count }}</span>
                            <span class="text-xs text-[var(--am-text-secondary)]">{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-4 text-xs text-[var(--am-text-secondary)]">
                    <span>Ohne Nutzer-Zuordnung: <a href="{{ route('asset-manager.devices.index', ['preset' => 'no_user']) }}" wire:navigate class="text-[var(--am-accent)] hover:underline">{{ $noUser }}</a></span>
                </div>
            </x-asset-manager-panel>

            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
