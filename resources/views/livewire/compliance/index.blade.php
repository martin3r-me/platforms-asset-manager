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
                <div class="flex flex-col items-center justify-center py-16 text-center rounded-xl bg-white/40 dark:bg-white/5 border border-dashed border-black/10 dark:border-white/10">
                    @svg('heroicon-o-shield-check', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Noch keine Gerätedaten</h3>
                    <p class="text-xs text-[color:var(--ui-secondary)] mb-4 max-w-sm">
                        @if(!$config || !$config->isConfigured())
                            Richte zuerst den Intune-Connector ein, um Compliance- und Sicherheitsdaten zu sehen.
                        @else
                            Sobald Geräte synchronisiert sind, erscheinen hier Compliance-Quote, Verschlüsselung und Auffälligkeiten.
                        @endif
                    </p>
                    <x-ui-button variant="primary" size="md" rounded="lg" href="{{ route('asset-manager.setup') }}" wire:navigate>
                        @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4')
                        Connector
                    </x-ui-button>
                </div>
            @else

            {{-- Kennzahlen --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)] mb-2">Compliance-Quote</div>
                    <div class="text-3xl font-semibold tracking-tight {{ $complianceQuote >= 90 ? 'text-emerald-700' : ($complianceQuote >= 70 ? 'text-amber-700' : 'text-red-700') }}">{{ $complianceQuote }}%</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">{{ $compliant }} von {{ $total }} konform</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)] mb-2">Verschlüsselt</div>
                    <div class="text-3xl font-semibold tracking-tight {{ $encQuote >= 90 ? 'text-emerald-700' : ($encQuote >= 70 ? 'text-amber-700' : 'text-red-700') }}">{{ $encQuote }}%</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">{{ $encrypted }} verschlüsselt · {{ $encUnknown }} unbekannt</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)] mb-2">Geräte gesamt</div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $total }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">aus Intune synchronisiert</div>
                </div>
            </div>

            {{-- Verteilungen --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- Compliance --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                    <div class="px-5 py-4 border-b border-[color:var(--ui-muted)]">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Compliance-Verteilung</h2>
                    </div>
                    <div class="p-5 space-y-3">
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
                                    <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                        <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>{{ $label }}
                                    </span>
                                    <span class="text-[color:var(--ui-secondary)] tabular-nums">{{ $row->count }} ({{ $pct }}%)</span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                                    <div class="h-full bg-{{ $color }}-500" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Verschlüsselung --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                    <div class="px-5 py-4 border-b border-[color:var(--ui-muted)]">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Verschlüsselung</h2>
                    </div>
                    <div class="p-5 space-y-3">
                        @foreach([
                            ['Verschlüsselt', $encrypted, 'emerald'],
                            ['Nicht verschlüsselt', $unencrypted, 'red'],
                            ['Unbekannt', $encUnknown, 'gray'],
                        ] as [$label, $count, $color])
                            @php $pct = $total > 0 ? round($count / $total * 100) : 0; @endphp
                            <div>
                                <div class="flex items-baseline justify-between text-xs mb-1">
                                    <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                        <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>{{ $label }}
                                    </span>
                                    <span class="text-[color:var(--ui-secondary)] tabular-nums">{{ $count }} ({{ $pct }}%)</span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                                    <div class="h-full bg-{{ $color }}-500" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- OS-Versionen --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="px-5 py-4 border-b border-[color:var(--ui-muted)]">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Betriebssystem-Versionen</h2>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                    @foreach($osBreakdown as $row)
                        @php $pct = $total > 0 ? round($row->count / $total * 100) : 0; @endphp
                        <div>
                            <div class="flex items-baseline justify-between text-xs mb-1">
                                <span class="text-gray-600 dark:text-gray-300 truncate">{{ $row->os }} <span class="text-[color:var(--ui-secondary)]">{{ $row->version }}</span></span>
                                <span class="text-[color:var(--ui-secondary)] tabular-nums ml-2 flex-shrink-0">{{ $row->count }} ({{ $pct }}%)</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                                <div class="h-full bg-violet-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Handlungsbedarf → verlinkt in die gefilterte Geräteliste --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="px-5 py-4 border-b border-[color:var(--ui-muted)]">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Handlungsbedarf</h2>
                </div>
                <div class="p-5 grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach([
                        ['noncompliant', 'Nicht konform', $noncompliant, 'red'],
                        ['unencrypted',  'Nicht verschlüsselt', $unencrypted, 'red'],
                        ['inactive',     'Inaktiv (>30 T.)', $stale, 'amber'],
                        ['expiring',     'Garantie/Leasing läuft ab', $expiring, 'amber'],
                    ] as [$preset, $label, $count, $color])
                        <a href="{{ route('asset-manager.devices.index', ['preset' => $preset]) }}" wire:navigate
                           class="flex flex-col gap-1 rounded-lg border border-black/5 dark:border-white/10 p-4 hover:bg-[color:var(--ui-muted-10)] transition-colors">
                            <span class="text-2xl font-semibold tabular-nums {{ $count > 0 ? 'text-'.$color.'-700' : 'text-gray-900 dark:text-gray-100' }}">{{ $count }}</span>
                            <span class="text-xs text-[color:var(--ui-secondary)]">{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="px-5 pb-4 flex flex-wrap gap-4 text-xs text-[color:var(--ui-secondary)]">
                    <span>Ohne Nutzer-Zuordnung: <a href="{{ route('asset-manager.devices.index', ['preset' => 'no_user']) }}" wire:navigate class="text-[color:var(--ui-primary)] hover:underline">{{ $noUser }}</a></span>
                </div>
            </div>

            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
