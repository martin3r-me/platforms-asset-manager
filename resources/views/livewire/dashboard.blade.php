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

            {{-- Kopf --}}
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-[var(--am-text)] mb-1">Asset Manager</h1>
                    <p class="text-sm text-[var(--am-text-secondary)]">
                        Übersicht aller verwalteten Geräte aus Microsoft Intune
                    </p>
                </div>
                @if($config && $config->isConfigured())
                    <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.devices.index') }}" wire:navigate class="flex-shrink-0">
                        @svg('heroicon-o-computer-desktop', 'w-4 h-4')
                        Alle Geräte
                    </x-asset-manager-button>
                @else
                    <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.setup') }}" wire:navigate class="flex-shrink-0">
                        @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4')
                        Connector einrichten
                    </x-asset-manager-button>
                @endif
            </div>

            {{-- Kein Connector --}}
            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-12 text-center rounded-xl bg-[var(--am-surface)] border border-dashed border-[color:var(--am-border-strong)]">
                    @svg('heroicon-o-wrench-screwdriver', 'w-10 h-10 text-[var(--am-text-disabled)] mb-3')
                    <h3 class="text-sm font-medium text-[var(--am-text)] mb-1">Noch kein Connector eingerichtet</h3>
                    <p class="text-xs text-[var(--am-text-secondary)] mb-4 max-w-sm">Verbinde den Asset Manager mit deiner Azure App-Registration, um Intune-Gerätedaten zu importieren.</p>
                    <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.setup') }}" wire:navigate>
                        @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        Connector einrichten
                    </x-asset-manager-button>
                </div>
            @else

            {{-- Asset Manager Top-Karten --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.assets.index') }}" wire:navigate
                    label="Assets" :value="$assetCounts['items']" sub="Hardware gesamt"
                    icon="heroicon-o-cube-transparent" accent="violet" />

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.employees.index') }}" wire:navigate
                    label="Mitarbeiter" :value="$assetCounts['employees']" sub="Aktive"
                    icon="heroicon-o-users" accent="indigo" />

                @if($controllingEnabled ?? false)
                <x-asset-manager-stat-card
                    label="Kosten / Monat" :value="number_format($totalMonthly, 2, ',', '.') . ' €'" sub="HW + Lizenzen"
                    icon="heroicon-o-banknotes" accent="amber" value-class="text-amber-600" />
                @endif

                <x-asset-manager-stat-card
                    label="Freie Lizenzen" :value="$unusedLicenses" sub="SKUs mit Reserve"
                    icon="heroicon-o-key" accent="emerald" value-class="text-emerald-600" />
            </div>

            {{-- Intune-Geräte Stats (klickbar → gefilterte Geräteliste) --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index') }}" wire:navigate
                    label="Gesamt" :value="$stats['total']" sub="Geräte"
                    icon="heroicon-o-computer-desktop" accent="violet" />

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index', ['filterCompliance' => 'compliant']) }}" wire:navigate
                    label="Konform" :value="$stats['compliant']" sub="{{ $complianceQuote }}% der Flotte"
                    icon="heroicon-o-check-circle" accent="emerald" value-class="text-emerald-600" />

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index', ['preset' => 'noncompliant']) }}" wire:navigate
                    label="Nicht konform" :value="$stats['noncompliant']" sub="Non-Compliant"
                    icon="heroicon-o-x-circle" accent="red" value-class="text-red-600" />

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index', ['filterCompliance' => 'unknown']) }}" wire:navigate
                    label="Unbekannt" :value="$stats['unknown']" sub="Unknown / Error"
                    icon="heroicon-o-question-mark-circle" value-class="text-[var(--am-text-muted)]" />
            </div>

            {{-- ITAM-Handlungslisten --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index', ['preset' => 'inactive']) }}" wire:navigate
                    label="Inaktive Geräte" :value="$stats['inactive']" sub="Kein Check-In seit über 30 Tagen"
                    icon="heroicon-o-clock" accent="amber"
                    value-class="{{ $stats['inactive'] > 0 ? 'text-amber-600' : '' }}" />

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index', ['preset' => 'no_user']) }}" wire:navigate
                    label="Ohne Nutzer" :value="$stats['no_user']" sub="Keine Nutzer-Zuordnung"
                    icon="heroicon-o-user-minus" accent="indigo"
                    value-class="{{ $stats['no_user'] > 0 ? 'text-indigo-600' : '' }}" />

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.devices.index', ['preset' => 'expiring']) }}" wire:navigate
                    label="Garantie/Leasing läuft ab" :value="$stats['expiring']" sub="Innerhalb der nächsten 90 Tage"
                    icon="heroicon-o-shield-exclamation" accent="red"
                    value-class="{{ $stats['expiring'] > 0 ? 'text-red-600' : '' }}" />
            </div>

            {{-- Lizenz-Kacheln --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if($controllingEnabled ?? false)
                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.licenses.index') }}" wire:navigate
                    label="Lizenzkosten / Monat"
                    :value="$licenseCost > 0 ? number_format($licenseCost, 0, ',', '.') . ' €' : '—'"
                    sub="Basierend auf gepflegten Preisen"
                    icon="heroicon-o-currency-euro" accent="violet" />
                @endif

                <x-asset-manager-stat-card
                    href="{{ route('asset-manager.licenses.index') }}" wire:navigate
                    label="Ungenutzte Lizenzen" :value="$unusedLicenses"
                    sub="{{ $lastLicenseSync ? 'Letzter Sync ' . $lastLicenseSync->started_at->diffForHumans() : 'Noch kein Lizenz-Sync' }}"
                    icon="heroicon-o-key" accent="amber"
                    value-class="{{ $unusedLicenses > 0 ? 'text-amber-600' : '' }}" />
            </div>

            {{-- Content Grid --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {{-- Zuletzt aktualisierte Geräte --}}
                <x-asset-manager-panel title="Zuletzt aktualisierte Geräte" body-class="p-0" class="lg:col-span-2">
                    <x-slot name="actions">
                        <a href="{{ route('asset-manager.devices.index') }}" wire:navigate class="text-xs text-[var(--am-accent)] hover:underline">Alle ansehen</a>
                    </x-slot>
                    <div class="divide-y divide-[color:var(--am-border)]">
                        @forelse($recentDevices as $device)
                            <a href="{{ route('asset-manager.devices.show', $device) }}" wire:navigate
                               class="flex items-center gap-3 px-4 py-3 hover:bg-[var(--am-bg)] transition-colors group">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-[var(--am-bg)] flex items-center justify-center">
                                    @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-[var(--am-text-muted)]')
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--am-text)] truncate group-hover:text-[var(--am-accent)] transition-colors">
                                        {{ $device->device_name ?? 'Unbekannt' }}
                                    </div>
                                    <div class="text-xs text-[var(--am-text-muted)]">{{ $device->user_display_name ?? '—' }}</div>
                                </div>
                                <x-asset-manager-badge :color="$device->complianceBadgeColor()" dot class="flex-shrink-0">
                                    {{ $device->complianceLabel() }}
                                </x-asset-manager-badge>
                            </a>
                        @empty
                            <div class="px-4 py-8 text-center text-xs text-[var(--am-text-muted)]">
                                Noch keine Geräte synchronisiert.
                            </div>
                        @endforelse
                    </div>
                </x-asset-manager-panel>

                {{-- Sync-Status --}}
                <x-asset-manager-panel title="Sync-Status">
                    <div class="space-y-4">
                        @if($config)
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0
                                    {{ $config->sync_status === 'success' ? 'bg-emerald-500' : '' }}
                                    {{ $config->sync_status === 'error' ? 'bg-red-500' : '' }}
                                    {{ $config->sync_status === 'running' ? 'bg-amber-500 animate-pulse' : '' }}
                                    {{ $config->sync_status === 'idle' ? 'bg-gray-400' : '' }}">
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-[var(--am-text)]">
                                        @if($config->sync_status === 'success') Erfolgreich
                                        @elseif($config->sync_status === 'error') Fehler
                                        @elseif($config->sync_status === 'running') Läuft...
                                        @else Bereit
                                        @endif
                                    </div>
                                    @if($config->last_sync_at)
                                        <div class="text-xs text-[var(--am-text-muted)]">{{ $config->last_sync_at->diffForHumans() }}</div>
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
                                            <span class="text-[var(--am-text-secondary)]">{{ $label }}</span>
                                            <span class="font-medium text-[var(--am-text)]">{{ $value ?? 0 }}</span>
                                        </div>
                                    @endforeach
                                    @if($lastLog->duration_ms)
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-[var(--am-text-secondary)]">Dauer</span>
                                            <span class="font-medium text-[var(--am-text)]">{{ number_format($lastLog->duration_ms / 1000, 1) }}s</span>
                                        </div>
                                    @endif
                                </div>
                            @elseif($config->sync_status === 'error' && $config->sync_error)
                                <p class="text-xs text-red-700">{{ Str::limit($config->sync_error, 120) }}</p>
                            @endif
                        @endif

                        <a href="{{ route('asset-manager.setup') }}" wire:navigate
                           class="flex items-center gap-2 text-xs text-[var(--am-text-secondary)] hover:text-[var(--am-accent)] transition-colors">
                            @svg('heroicon-o-wrench-screwdriver', 'w-3.5 h-3.5')
                            Connector-Einstellungen
                        </a>
                    </div>
                </x-asset-manager-panel>
            </div>

            @endif {{-- Ende: Connector konfiguriert --}}

        </div>
    </x-ui-page-container>
</x-ui-page>
