<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Sicherheit & Compliance', 'icon' => 'shield-check'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-6">

            @if(!$config || !$config->isConfigured() || $total === 0)
                <div class="flex flex-col items-center justify-center py-16 text-center rounded-xl bg-white/40 dark:bg-white/5 border border-dashed border-black/10 dark:border-white/10">
                    @svg('heroicon-o-shield-check', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Noch keine Gerätedaten</h3>
                    <p class="text-xs text-gray-400 mb-4 max-w-sm">
                        @if(!$config || !$config->isConfigured())
                            Richte zuerst den Intune-Connector ein, um Compliance- und Sicherheitsdaten zu sehen.
                        @else
                            Sobald Geräte synchronisiert sind, erscheinen hier Compliance-Quote, Verschlüsselung und Auffälligkeiten.
                        @endif
                    </p>
                    <a href="{{ route('asset-manager.setup') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                        @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4')
                        Connector
                    </a>
                </div>
            @else

            {{-- Kennzahlen --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Compliance-Quote</div>
                    <div class="text-3xl font-semibold tracking-tight {{ $complianceQuote >= 90 ? 'text-emerald-600 dark:text-emerald-400' : ($complianceQuote >= 70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">{{ $complianceQuote }}%</div>
                    <div class="text-xs text-gray-400 mt-1">{{ $compliant }} von {{ $total }} konform</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Verschlüsselt</div>
                    <div class="text-3xl font-semibold tracking-tight {{ $encQuote >= 90 ? 'text-emerald-600 dark:text-emerald-400' : ($encQuote >= 70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">{{ $encQuote }}%</div>
                    <div class="text-xs text-gray-400 mt-1">{{ $encrypted }} verschlüsselt · {{ $encUnknown }} unbekannt</div>
                </div>

                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Geräte gesamt</div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $total }}</div>
                    <div class="text-xs text-gray-400 mt-1">aus Intune synchronisiert</div>
                </div>
            </div>

            {{-- Verteilungen --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- Compliance --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
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
                                    <span class="text-gray-400 tabular-nums">{{ $row->count }} ({{ $pct }}%)</span>
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
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
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
                                    <span class="text-gray-400 tabular-nums">{{ $count }} ({{ $pct }}%)</span>
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
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Betriebssystem-Versionen</h2>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                    @foreach($osBreakdown as $row)
                        @php $pct = $total > 0 ? round($row->count / $total * 100) : 0; @endphp
                        <div>
                            <div class="flex items-baseline justify-between text-xs mb-1">
                                <span class="text-gray-600 dark:text-gray-300 truncate">{{ $row->os }} <span class="text-gray-400">{{ $row->version }}</span></span>
                                <span class="text-gray-400 tabular-nums ml-2 flex-shrink-0">{{ $row->count }} ({{ $pct }}%)</span>
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
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
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
                           class="flex flex-col gap-1 rounded-lg border border-black/5 dark:border-white/10 p-4 hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                            <span class="text-2xl font-semibold tabular-nums {{ $count > 0 ? 'text-'.$color.'-600 dark:text-'.$color.'-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $count }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="px-5 pb-4 flex flex-wrap gap-4 text-xs text-gray-400">
                    <span>Ohne Nutzer-Zuordnung: <a href="{{ route('asset-manager.devices.index', ['preset' => 'no_user']) }}" wire:navigate class="text-violet-600 dark:text-violet-400 hover:underline">{{ $noUser }}</a></span>
                </div>
            </div>

            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
