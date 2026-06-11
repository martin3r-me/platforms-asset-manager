<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-6">

            {{-- Hero --}}
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-violet-500/10 via-indigo-500/5 to-transparent dark:from-violet-500/20 dark:via-indigo-500/10 dark:to-transparent border border-white/20 dark:border-white/10 shadow-sm p-8">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/60 to-transparent"></div>
                <div class="absolute -top-24 -right-24 w-64 h-64 bg-violet-500/10 rounded-full blur-3xl"></div>
                <div class="relative flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-1">Asset Manager</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Übersicht aller verwalteten Geräte aus Microsoft Intune
                        </p>
                    </div>
                    @if($config && $config->isConfigured())
                        <a href="{{ route('asset-manager.devices.index') }}" wire:navigate
                           class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                            @svg('heroicon-o-computer-desktop', 'w-4 h-4')
                            Alle Geräte
                        </a>
                    @else
                        <a href="{{ route('asset-manager.setup') }}" wire:navigate
                           class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-violet-600 dark:text-violet-400 bg-violet-500/10 rounded-lg hover:bg-violet-500/20 transition-all">
                            @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4')
                            Connector einrichten
                        </a>
                    @endif
                </div>
            </div>

            {{-- Kein Connector --}}
            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-12 text-center rounded-xl bg-white/40 dark:bg-white/5 border border-dashed border-black/10 dark:border-white/10">
                    @svg('heroicon-o-wrench-screwdriver', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Noch kein Connector eingerichtet</h3>
                    <p class="text-xs text-gray-400 mb-4 max-w-sm">Verbinde den Asset Manager mit deiner Azure App-Registration, um Intune-Gerätedaten zu importieren.</p>
                    <a href="{{ route('asset-manager.setup') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                        @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        Connector einrichten
                    </a>
                </div>
            @else

            {{-- Stat-Karten --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Gesamt</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-500/10">
                            @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-violet-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-gray-400 mt-1">Geräte</div>
                </div>

                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Konform</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10">
                            @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-400">{{ $stats['compliant'] }}</div>
                    <div class="text-xs text-gray-400 mt-1">Compliant</div>
                </div>

                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-red-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Nicht konform</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-red-500/10">
                            @svg('heroicon-o-x-circle', 'w-4 h-4 text-red-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-red-600 dark:text-red-400">{{ $stats['noncompliant'] }}</div>
                    <div class="text-xs text-gray-400 mt-1">Non-Compliant</div>
                </div>

                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Unbekannt</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-500/10">
                            @svg('heroicon-o-question-mark-circle', 'w-4 h-4 text-gray-400')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-500 dark:text-gray-400">{{ $stats['unknown'] }}</div>
                    <div class="text-xs text-gray-400 mt-1">Unknown / Error</div>
                </div>
            </div>

            {{-- Content Grid --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {{-- Zuletzt aktualisierte Geräte --}}
                <div class="lg:col-span-2 relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Zuletzt aktualisierte Geräte</h2>
                        <a href="{{ route('asset-manager.devices.index') }}" wire:navigate class="text-xs text-violet-600 dark:text-violet-400 hover:underline">Alle ansehen</a>
                    </div>
                    <div class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                        @forelse($recentDevices as $device)
                            <a href="{{ route('asset-manager.devices.show', $device) }}" wire:navigate
                               class="flex items-center gap-3 px-5 py-3 hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors group">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-gray-500/10 flex items-center justify-center">
                                    @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-gray-400')
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate group-hover:text-violet-600 dark:group-hover:text-violet-400 transition-colors">
                                        {{ $device->device_name ?? 'Unbekannt' }}
                                    </div>
                                    <div class="text-xs text-gray-400">{{ $device->user_display_name ?? '—' }}</div>
                                </div>
                                @php $color = $device->complianceBadgeColor() @endphp
                                <span class="flex-shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $color }}-500/10 text-{{ $color }}-600 dark:text-{{ $color }}-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                                    {{ $device->complianceLabel() }}
                                </span>
                            </a>
                        @empty
                            <div class="px-5 py-8 text-center text-xs text-gray-400">
                                Noch keine Geräte synchronisiert.
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Sync-Status --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/30 to-transparent"></div>
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Sync-Status</h2>
                    </div>
                    <div class="p-5 space-y-4">
                        @if($config)
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0
                                    {{ $config->sync_status === 'success' ? 'bg-emerald-500' : '' }}
                                    {{ $config->sync_status === 'error' ? 'bg-red-500' : '' }}
                                    {{ $config->sync_status === 'running' ? 'bg-amber-500 animate-pulse' : '' }}
                                    {{ $config->sync_status === 'idle' ? 'bg-gray-400' : '' }}">
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        @if($config->sync_status === 'success') Erfolgreich
                                        @elseif($config->sync_status === 'error') Fehler
                                        @elseif($config->sync_status === 'running') Läuft...
                                        @else Bereit
                                        @endif
                                    </div>
                                    @if($config->last_sync_at)
                                        <div class="text-xs text-gray-400">{{ $config->last_sync_at->diffForHumans() }}</div>
                                    @endif
                                </div>
                            </div>

                            @if($lastLog && $lastLog->status === 'success')
                                <div class="space-y-2">
                                    @foreach([
                                        ['Synchronisiert', $lastLog->devices_synced],
                                        ['Hinzugefügt', $lastLog->devices_added],
                                        ['Aktualisiert', $lastLog->devices_updated],
                                        ['Entfernt', $lastLog->devices_removed],
                                    ] as [$label, $value])
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-400">{{ $label }}</span>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $value ?? 0 }}</span>
                                        </div>
                                    @endforeach
                                    @if($lastLog->duration_ms)
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-gray-400">Dauer</span>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($lastLog->duration_ms / 1000, 1) }}s</span>
                                        </div>
                                    @endif
                                </div>
                            @elseif($config->sync_status === 'error' && $config->sync_error)
                                <p class="text-xs text-red-500 dark:text-red-400">{{ Str::limit($config->sync_error, 120) }}</p>
                            @endif
                        @endif

                        <a href="{{ route('asset-manager.setup') }}" wire:navigate
                           class="flex items-center gap-2 text-xs text-gray-400 hover:text-violet-500 dark:hover:text-violet-400 transition-colors">
                            @svg('heroicon-o-wrench-screwdriver', 'w-3.5 h-3.5')
                            Connector-Einstellungen
                        </a>
                    </div>
                </div>
            </div>

            @endif {{-- Ende: Connector konfiguriert --}}

        </div>
    </x-ui-page-container>
</x-ui-page>
