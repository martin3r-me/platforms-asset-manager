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
                        <p class="text-sm text-gray-500 dark:text-[color:var(--ui-secondary)]">
                            Übersicht aller verwalteten Geräte aus Microsoft Intune
                        </p>
                    </div>
                    @if($config && $config->isConfigured())
                        <x-ui-button variant="primary" size="md" rounded="lg" href="{{ route('asset-manager.devices.index') }}" wire:navigate class="flex-shrink-0">
                            @svg('heroicon-o-computer-desktop', 'w-4 h-4')
                            Alle Geräte
                        </x-ui-button>
                    @else
                        <x-ui-button variant="primary" size="md" rounded="lg" href="{{ route('asset-manager.setup') }}" wire:navigate class="flex-shrink-0">
                            @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4')
                            Connector einrichten
                        </x-ui-button>
                    @endif
                </div>
            </div>

            {{-- Kein Connector --}}
            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-12 text-center rounded-xl bg-white/40 dark:bg-white/5 border border-dashed border-black/10 dark:border-white/10">
                    @svg('heroicon-o-wrench-screwdriver', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                    <h3 class="text-sm font-medium text-[color:var(--ui-body-color)] mb-1">Noch kein Connector eingerichtet</h3>
                    <p class="text-xs text-[color:var(--ui-secondary)] mb-4 max-w-sm">Verbinde den Asset Manager mit deiner Azure App-Registration, um Intune-Gerätedaten zu importieren.</p>
                    <x-ui-button variant="primary" size="md" rounded="lg" href="{{ route('asset-manager.setup') }}" wire:navigate>
                        @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        Connector einrichten
                    </x-ui-button>
                </div>
            @else

            {{-- Asset Manager Top-Karten --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('asset-manager.assets.index') }}" wire:navigate
                    class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Assets</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-500/10">
                            @svg('heroicon-o-cube-transparent', 'w-4 h-4 text-violet-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $assetCounts['items'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Hardware gesamt</div>
                </a>

                <a href="{{ route('asset-manager.employees.index') }}" wire:navigate
                    class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Mitarbeiter</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-500/10">
                            @svg('heroicon-o-users', 'w-4 h-4 text-indigo-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $assetCounts['employees'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Aktive</div>
                </a>

                @if($controllingEnabled ?? false)
                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-amber-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Kosten / Monat</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/10">
                            @svg('heroicon-o-banknotes', 'w-4 h-4 text-amber-500')
                        </div>
                    </div>
                    <div class="text-2xl font-semibold tracking-tight text-amber-600 dark:text-amber-400">{{ number_format($totalMonthly, 2, ',', '.') }} €</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">HW + Lizenzen</div>
                </div>
                @endif

                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Freie Lizenzen</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10">
                            @svg('heroicon-o-key', 'w-4 h-4 text-emerald-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-400">{{ $unusedLicenses }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">SKUs mit Reserve</div>
                </div>
            </div>

            {{-- Intune-Geräte Stats (klickbar → gefilterte Geräteliste) --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('asset-manager.devices.index') }}" wire:navigate
                    class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Gesamt</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-500/10">
                            @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-violet-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Geräte</div>
                </a>

                <a href="{{ route('asset-manager.devices.index', ['filterCompliance' => 'compliant']) }}" wire:navigate
                    class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Konform</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10">
                            @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-400">{{ $stats['compliant'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">{{ $complianceQuote }}% der Flotte</div>
                </a>

                <a href="{{ route('asset-manager.devices.index', ['preset' => 'noncompliant']) }}" wire:navigate
                    class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-red-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Nicht konform</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-red-500/10">
                            @svg('heroicon-o-x-circle', 'w-4 h-4 text-red-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-red-600 dark:text-red-400">{{ $stats['noncompliant'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Non-Compliant</div>
                </a>

                <a href="{{ route('asset-manager.devices.index', ['filterCompliance' => 'unknown']) }}" wire:navigate
                    class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Unbekannt</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-500/10">
                            @svg('heroicon-o-question-mark-circle', 'w-4 h-4 text-[color:var(--ui-secondary)]')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-500 dark:text-[color:var(--ui-secondary)]">{{ $stats['unknown'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Unknown / Error</div>
                </a>
            </div>

            {{-- ITAM-Handlungslisten --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <a href="{{ route('asset-manager.devices.index', ['preset' => 'inactive']) }}" wire:navigate
                   class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-amber-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Inaktive Geräte</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/10">
                            @svg('heroicon-o-clock', 'w-4 h-4 text-amber-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight {{ $stats['inactive'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $stats['inactive'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Kein Check-In seit über 30 Tagen</div>
                </a>

                <a href="{{ route('asset-manager.devices.index', ['preset' => 'no_user']) }}" wire:navigate
                   class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Ohne Nutzer</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-500/10">
                            @svg('heroicon-o-user-minus', 'w-4 h-4 text-indigo-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight {{ $stats['no_user'] > 0 ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $stats['no_user'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Keine Nutzer-Zuordnung</div>
                </a>

                <a href="{{ route('asset-manager.devices.index', ['preset' => 'expiring']) }}" wire:navigate
                   class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-red-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Garantie/Leasing läuft ab</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-red-500/10">
                            @svg('heroicon-o-shield-exclamation', 'w-4 h-4 text-red-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight {{ $stats['expiring'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $stats['expiring'] }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Innerhalb der nächsten 90 Tage</div>
                </a>
            </div>

            {{-- Lizenz-Kacheln --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if($controllingEnabled ?? false)
                <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate
                   class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Lizenzkosten / Monat</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-500/10">
                            @svg('heroicon-o-currency-euro', 'w-4 h-4 text-violet-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">
                        {{ $licenseCost > 0 ? number_format($licenseCost, 0, ',', '.') . ' €' : '—' }}
                    </div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">Basierend auf gepflegten Preisen</div>
                </a>
                @endif

                <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate
                   class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-amber-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-[color:var(--ui-secondary)]">Ungenutzte Lizenzen</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/10">
                            @svg('heroicon-o-key', 'w-4 h-4 text-amber-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight {{ $unusedLicenses > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ $unusedLicenses }}
                    </div>
                    <div class="text-xs text-[color:var(--ui-secondary)] mt-1">
                        @if($lastLicenseSync)
                            Letzter Sync {{ $lastLicenseSync->started_at->diffForHumans() }}
                        @else
                            Noch kein Lizenz-Sync
                        @endif
                    </div>
                </a>
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
                    <div class="divide-y divide-[color:var(--ui-muted)]">
                        @forelse($recentDevices as $device)
                            <a href="{{ route('asset-manager.devices.show', $device) }}" wire:navigate
                               class="flex items-center gap-3 px-5 py-3 hover:bg-[color:var(--ui-muted-10)] transition-colors group">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-gray-500/10 flex items-center justify-center">
                                    @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-[color:var(--ui-secondary)]')
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate group-hover:text-violet-600 dark:group-hover:text-violet-400 transition-colors">
                                        {{ $device->device_name ?? 'Unbekannt' }}
                                    </div>
                                    <div class="text-xs text-[color:var(--ui-secondary)]">{{ $device->user_display_name ?? '—' }}</div>
                                </div>
                                <x-asset-manager-badge :color="$device->complianceBadgeColor()" dot class="flex-shrink-0">
                                    {{ $device->complianceLabel() }}
                                </x-asset-manager-badge>
                            </a>
                        @empty
                            <div class="px-5 py-8 text-center text-xs text-[color:var(--ui-secondary)]">
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
                                        <div class="text-xs text-[color:var(--ui-secondary)]">{{ $config->last_sync_at->diffForHumans() }}</div>
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
                                            <span class="text-[color:var(--ui-secondary)]">{{ $label }}</span>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $value ?? 0 }}</span>
                                        </div>
                                    @endforeach
                                    @if($lastLog->duration_ms)
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-[color:var(--ui-secondary)]">Dauer</span>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($lastLog->duration_ms / 1000, 1) }}s</span>
                                        </div>
                                    @endif
                                </div>
                            @elseif($config->sync_status === 'error' && $config->sync_error)
                                <p class="text-xs text-red-700">{{ Str::limit($config->sync_error, 120) }}</p>
                            @endif
                        @endif

                        <a href="{{ route('asset-manager.setup') }}" wire:navigate
                           class="flex items-center gap-2 text-xs text-[color:var(--ui-secondary)] hover:text-violet-500 dark:hover:text-violet-400 transition-colors">
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
