<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte', 'icon' => 'computer-desktop'],
        ]">
            <x-slot name="actions">
                @if($config && $config->isConfigured() && $config->last_sync_at)
                    <span class="text-xs text-gray-400">
                        @svg('heroicon-o-clock', 'w-3 h-3 inline -mt-0.5')
                        Letzter Sync {{ $config->last_sync_at->diffForHumans() }}
                    </span>
                @endif
                @if($config && $config->isConfigured() && $stats['total'] > 0)
                    <button wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all disabled:opacity-60">
                        @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                        Export CSV
                    </button>
                @endif
                <a href="{{ route('asset-manager.setup') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                    @svg('heroicon-o-wrench-screwdriver', 'w-3.5 h-3.5')
                    Connector
                </a>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Schnellfilter</h3>
                    <div class="px-2 pb-2 space-y-0.5">
                        @foreach([
                            ['all',          'Alle Geräte'],
                            ['no_user',      'Ohne Nutzer'],
                            ['inactive',     'Inaktiv (>30 T.)'],
                            ['noncompliant', 'Nicht konform'],
                            ['issues',       'Fehler / Konflikt'],
                            ['expiring',     'Läuft ab (90 T.)'],
                            ['unencrypted',  'Nicht verschlüsselt'],
                        ] as [$key, $label])
                            <button wire:click="setPreset('{{ $key }}')"
                                class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-[11px] rounded-md transition-colors {{ $preset === $key ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300 font-medium' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                                <span>{{ $label }}</span>
                                <span class="tabular-nums px-1.5 py-0.5 rounded-full text-[10px] {{ $preset === $key ? 'bg-violet-500/20 text-violet-700 dark:text-violet-300' : 'bg-[var(--ui-muted-10)] text-[var(--ui-muted)]' }}">{{ $presetCounts[$key] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Name, Nutzer, Seriennr..."
                            class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Compliance</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterCompliance" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="compliant">Konform</option>
                            <option value="noncompliant">Nicht konform</option>
                            <option value="inGracePeriod">Karenzzeit</option>
                            <option value="unknown">Unbekannt</option>
                            <option value="error">Fehler</option>
                        </select>
                    </div>
                </section>

                @if($osList->count() > 0)
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Betriebssystem</h3>
                        <div class="px-3 pb-3">
                            <select wire:model.live="filterOs" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="">Alle</option>
                                @foreach($osList as $os) <option value="{{ $os }}">{{ $os }}</option> @endforeach
                            </select>
                        </div>
                    </section>
                @endif

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Lifecycle</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterLifecycle" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="in_use">In Betrieb</option>
                            <option value="spare">Reserve / Lager</option>
                            <option value="repair">In Reparatur</option>
                            <option value="retired">Ausgemustert</option>
                            <option value="lost">Verloren / Gestohlen</option>
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Anzeige</h3>
                    <div class="px-3 pb-2 text-[11px]">
                        <div class="flex items-center justify-between py-1.5">
                            <span class="text-[var(--ui-muted)]">Pro Seite</span>
                            <select wire:model.live="perPage" class="px-2 py-1 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="15">15</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                    <button wire:click="resetColumnOrder" class="w-full text-left px-3 py-2 border-t border-[var(--ui-border)]/30 text-[11px] text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]">
                        @svg('heroicon-o-arrow-uturn-left', 'w-3 h-3 inline -mt-0.5 mr-1')
                        Spalten zurücksetzen
                    </button>
                </section>

                @if($search || $filterCompliance || $filterOs || $filterLifecycle || $preset !== 'all')
                    <button wire:click="$set('search', ''); $set('filterCompliance', ''); $set('filterOs', ''); $set('filterLifecycle', ''); setPreset('all')"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Filter zurücksetzen
                    </button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Master-Detail --}}
    <x-slot name="activity">
        <x-ui-page-sidebar :title="$selectedDevice ? 'Gerät' : ($selectedEmployee ? 'Mitarbeiter' : 'Details')" icon="heroicon-o-information-circle" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">

                @if($selectedDevice)
                    {{-- Detail: einzelnes Gerät --}}
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Auswahl</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="flex items-start gap-2 mb-2">
                            @svg('heroicon-o-computer-desktop', 'w-5 h-5 text-violet-500 flex-shrink-0')
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-[var(--ui-secondary)] truncate">{{ $selectedDevice->device_name ?? '—' }}</div>
                                <div class="text-[11px] text-[var(--ui-muted)] truncate">{{ trim($selectedDevice->manufacturer . ' ' . $selectedDevice->model) }}</div>
                            </div>
                        </div>

                        @php $c = $selectedDevice->complianceBadgeColor() @endphp
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium bg-{{ $c }}-500/10 text-{{ $c }}-600">
                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $c }}-500"></span>
                            {{ $selectedDevice->complianceLabel() }}
                        </span>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Eigenschaften</h3>
                        <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                            @foreach([
                                ['OS',           $selectedDevice->operating_system],
                                ['Version',      $selectedDevice->os_version],
                                ['Nutzer',       $selectedDevice->user_display_name],
                                ['UPN',          $selectedDevice->user_principal_name],
                                ['Management',   $selectedDevice->management_state],
                                ['Seriennr.',    $selectedDevice->serial_number],
                                ['Enrollt am',   $selectedDevice->enrolled_at?->format('d.m.Y')],
                                ['Letztes Check-In', $selectedDevice->last_check_in_at?->diffForHumans()],
                            ] as [$label, $value])
                                <div class="flex items-baseline justify-between gap-2 py-1.5 px-3">
                                    <dt class="text-[var(--ui-muted)] flex-shrink-0">{{ $label }}</dt>
                                    <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[60%] text-right">{{ $value ?: '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <div class="space-y-2">
                        <a href="{{ route('asset-manager.devices.show', $selectedDevice) }}" wire:navigate
                           class="block px-3 py-2 text-xs text-center font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all">
                            @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5 inline -mt-0.5')
                            Vollständige Detail-Seite
                        </a>
                        @if($selectedDevice->user_principal_name)
                            <button wire:click="selectEmployeeByUpn('{{ $selectedDevice->user_principal_name }}')"
                                class="block w-full px-3 py-2 text-xs text-center text-[var(--ui-secondary)] bg-white border border-[var(--ui-border)]/40 rounded-lg hover:bg-[var(--ui-muted-5)]">
                                @svg('heroicon-o-user', 'w-3.5 h-3.5 inline -mt-0.5')
                                Mitarbeiter-Profil anzeigen
                            </button>
                        @endif
                    </div>

                @elseif($selectedEmployee)
                    {{-- Detail: Mitarbeiter mit allen Geräten/Lizenzen --}}
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Mitarbeiter</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs font-semibold flex-shrink-0">
                                {{ $selectedEmployee->initials() }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-[var(--ui-secondary)] truncate">{{ $selectedEmployee->name }}</div>
                                <div class="text-[10px] text-[var(--ui-muted)] truncate">{{ $selectedEmployee->user_principal_name }}</div>
                            </div>
                        </div>
                        @if($selectedEmployee->department || $selectedEmployee->job_title)
                            <div class="mt-2 text-[10px] text-[var(--ui-muted)]">
                                {{ $selectedEmployee->job_title }}@if($selectedEmployee->department && $selectedEmployee->job_title) · @endif{{ $selectedEmployee->department }}
                            </div>
                        @endif
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Geräte ({{ $employeeDevices->count() }})</h3>
                        @if($employeeDevices->isEmpty())
                            <div class="px-3 pb-3 text-[11px] text-[var(--ui-muted)]">Keine Geräte zugewiesen.</div>
                        @else
                            <div class="divide-y divide-[var(--ui-border)]/30">
                                @foreach($employeeDevices as $d)
                                    <button wire:click="selectDevice({{ $d->id }})" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-[var(--ui-muted-5)] text-left">
                                        @svg('heroicon-o-computer-desktop', 'w-3.5 h-3.5 text-gray-400 flex-shrink-0')
                                        <div class="min-w-0 flex-1">
                                            <div class="text-[11px] font-medium text-[var(--ui-secondary)] truncate">{{ $d->device_name ?? '—' }}</div>
                                            <div class="text-[10px] text-[var(--ui-muted)] truncate">{{ $d->operating_system }} {{ $d->os_version }}</div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Lizenzen ({{ $employeeLicenses->count() }})</h3>
                        @if($employeeLicenses->isEmpty())
                            <div class="px-3 pb-3 text-[11px] text-[var(--ui-muted)]">Keine Lizenzen zugewiesen.</div>
                        @else
                            <ul class="divide-y divide-[var(--ui-border)]/30">
                                @foreach($employeeLicenses as $lic)
                                    <li class="px-3 py-2 text-[11px] text-[var(--ui-secondary)] truncate">
                                        @svg('heroicon-o-key', 'w-3 h-3 text-gray-400 inline -mt-0.5 mr-1')
                                        {{ $lic->sku_part_number ?? $lic->sku_id }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <a href="{{ route('asset-manager.employees.show', $selectedEmployee) }}" wire:navigate
                       class="block px-3 py-2 text-xs text-center font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all">
                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5 inline -mt-0.5')
                        Vollständiges Profil
                    </a>

                @else
                    {{-- Keine Auswahl: Hinweis --}}
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-gray-300 mb-3')
                        <p class="text-[11px] text-[var(--ui-muted)] mb-1">Wähle ein Gerät oder einen Nutzer aus der Tabelle.</p>
                        <p class="text-[10px] text-[var(--ui-muted)]/70">Sync-Informationen findest du im Connector.</p>
                    </div>

                    @if($canSync && $config && $config->isConfigured() && $config->sync_status !== 'running')
                        <button wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow"
                            class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm disabled:opacity-60">
                            <span wire:loading.remove wire:target="syncNow">@svg('heroicon-o-arrow-path', 'w-3 h-3') Jetzt synchronisieren</span>
                            <span wire:loading wire:target="syncNow">@svg('heroicon-o-arrow-path', 'w-3 h-3 animate-spin') Startet...</span>
                        </button>
                    @endif

                    @if($syncResult)
                        <div class="p-2 rounded-lg bg-violet-500/10 border border-violet-500/20 text-[11px] text-violet-700 dark:text-violet-400">
                            {{ $syncResult }}
                        </div>
                    @endif

                    <a href="{{ route('asset-manager.setup') }}" wire:navigate class="block text-center text-[10px] text-[var(--ui-muted)] hover:text-violet-500 mt-2">
                        Connector & vollständige Sync-Historie →
                    </a>
                @endif

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center mb-4">
                        @svg('heroicon-o-wrench-screwdriver', 'w-6 h-6 text-amber-500')
                    </div>
                    <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Connector nicht konfiguriert</h3>
                    <p class="text-xs text-gray-400 mb-4 max-w-xs">Trage die Azure App-Registration Credentials ein, um Intune-Gerätedaten zu synchronisieren.</p>
                    @if($canSync)
                        <a href="{{ route('asset-manager.setup') }}" wire:navigate
                           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                            Connector einrichten
                        </a>
                    @endif
                </div>
            @else

            {{-- Stat-Karten --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-gray-400">Geräte gesamt</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['compliant'] }}</div>
                    <div class="text-xs text-gray-400">Konform</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-red-600 dark:text-red-400">{{ $stats['noncompliant'] }}</div>
                    <div class="text-xs text-gray-400">Nicht konform</div>
                </div>
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-500 dark:text-gray-400">{{ $stats['unknown'] }}</div>
                    <div class="text-xs text-gray-400">Unbekannt</div>
                </div>
            </div>

            {{-- Aktive Filter --}}
            @if($search || $filterCompliance || $filterOs || $filterLifecycle || $preset !== 'all')
                @php
                    $presetLabels = ['no_user' => 'Ohne Nutzer', 'inactive' => 'Inaktiv (>30 T.)', 'noncompliant' => 'Nicht konform', 'issues' => 'Fehler / Konflikt', 'expiring' => 'Läuft ab (90 T.)', 'unencrypted' => 'Nicht verschlüsselt'];
                    $lifecycleLabels = ['in_use' => 'In Betrieb', 'spare' => 'Reserve / Lager', 'repair' => 'In Reparatur', 'retired' => 'Ausgemustert', 'lost' => 'Verloren / Gestohlen'];
                @endphp
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-400">Aktive Filter:</span>
                    @if($preset !== 'all')<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">{{ $presetLabels[$preset] ?? $preset }} <button wire:click="setPreset('all')">×</button></span>@endif
                    @if($search)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">Suche: "{{ Str::limit($search, 30) }}" <button wire:click="$set('search', '')">×</button></span>@endif
                    @if($filterCompliance)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">{{ $filterCompliance }} <button wire:click="$set('filterCompliance', '')">×</button></span>@endif
                    @if($filterOs)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">{{ $filterOs }} <button wire:click="$set('filterOs', '')">×</button></span>@endif
                    @if($filterLifecycle)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600">{{ $lifecycleLabels[$filterLifecycle] ?? $filterLifecycle }} <button wire:click="$set('filterLifecycle', '')">×</button></span>@endif
                </div>
            @endif

            {{-- Bulk-Result --}}
            @if($bulkResult)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                    <p class="text-sm text-emerald-700 dark:text-emerald-400">{{ $bulkResult }}</p>
                    <button wire:click="$set('bulkResult', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
                </div>
            @endif

            {{-- Bulk-Aktionsleiste (nur owner/admin, nur bei Auswahl) --}}
            @if($canManage && count($selected) > 0)
                <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-violet-600 text-white shadow-lg">
                    <span class="text-sm font-medium">{{ count($selected) }} ausgewählt</span>
                    <div class="h-4 w-px bg-white/30"></div>

                    {{-- Kostenstelle --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkCostCenter" class="px-2 py-1 text-xs rounded-md bg-white/15 border border-white/20 text-white [&>option]:text-gray-900">
                            <option value="">Kostenstelle…</option>
                            @foreach($costCenters as $cc)
                                <option value="{{ $cc->id }}">{{ $cc->code }}{{ $cc->name ? ' · ' . $cc->name : '' }}</option>
                            @endforeach
                        </select>
                        <button wire:click="bulkSetCostCenter" class="px-2.5 py-1 text-xs font-medium rounded-md bg-white/20 hover:bg-white/30 transition-colors">Setzen</button>
                    </div>

                    {{-- Lifecycle --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkLifecycle" class="px-2 py-1 text-xs rounded-md bg-white/15 border border-white/20 text-white [&>option]:text-gray-900">
                            <option value="">Lifecycle…</option>
                            <option value="in_use">In Betrieb</option>
                            <option value="spare">Reserve / Lager</option>
                            <option value="repair">In Reparatur</option>
                            <option value="retired">Ausgemustert</option>
                            <option value="lost">Verloren / Gestohlen</option>
                        </select>
                        <button wire:click="bulkSetLifecycle" class="px-2.5 py-1 text-xs font-medium rounded-md bg-white/20 hover:bg-white/30 transition-colors">Setzen</button>
                    </div>

                    <button wire:click="clearBulkSelection" class="ml-auto text-xs text-white/80 hover:text-white">Auswahl aufheben</button>
                </div>
            @endif

            {{-- Tabelle --}}
            @php
                $columnDefs = [
                    'device'      => ['label' => 'Gerät',           'sortField' => 'device_name'],
                    'user'        => ['label' => 'Nutzer',          'sortField' => null],
                    'os'          => ['label' => 'Betriebssystem',  'sortField' => null],
                    'status'      => ['label' => 'Status',          'sortField' => 'compliance_state'],
                    'lastCheckIn' => ['label' => 'Letztes Check-In','sortField' => 'last_check_in_at'],
                ];
            @endphp

            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                @if($devices->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-computer-desktop', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">
                            @if($search || $filterCompliance || $filterOs || $filterLifecycle || $preset !== 'all') Keine Geräte für diese Filter.
                            @else Noch keine Geräte synchronisiert. @endif
                        </p>
                    </div>
                @else
                    @if($canManage && $selectPage && count($selected) < $devices->total())
                        <div class="px-5 py-2 bg-violet-500/10 border-b border-violet-500/20 text-center text-xs text-violet-700 dark:text-violet-400">
                            {{ count($selected) }} auf dieser Seite ausgewählt.
                            <button wire:click="selectAllFiltered" class="font-medium underline hover:text-violet-900">Alle {{ $devices->total() }} gefilterten auswählen</button>
                        </div>
                    @endif
                    <table class="w-full text-sm">
                        <thead>
                            <tr wire:sortable="reorderColumns" wire:sortable.options="{ axis: 'x' }" class="border-b border-black/5 dark:border-white/5">
                                @if($canManage)
                                    <th class="w-10 px-5 py-3 bg-white/40 dark:bg-white/[0.02]">
                                        <input type="checkbox" wire:model.live="selectPage" class="rounded border-black/20 dark:border-white/20 text-violet-600 focus:ring-violet-500/30" />
                                    </th>
                                @endif
                                @foreach($columns as $colKey)
                                    @php $def = $columnDefs[$colKey] ?? null; @endphp
                                    @if($def)
                                        <th wire:sortable.item="{{ $colKey }}" wire:key="col-{{ $colKey }}"
                                            class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400 bg-white/40 dark:bg-white/[0.02]">
                                            <div class="flex items-center gap-2">
                                                <button wire:sortable.handle type="button" title="Spalte verschieben" class="text-gray-300 hover:text-gray-500 cursor-grab active:cursor-grabbing">
                                                    @svg('heroicon-o-bars-3', 'w-3.5 h-3.5')
                                                </button>
                                                @if($def['sortField'])
                                                    <button wire:click="sortBy('{{ $def['sortField'] }}')" class="flex items-center gap-1 hover:text-gray-600">
                                                        {{ $def['label'] }}
                                                        @if($sortField === $def['sortField']) @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                                    </button>
                                                @else
                                                    <span>{{ $def['label'] }}</span>
                                                @endif
                                            </div>
                                        </th>
                                    @endif
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($devices as $device)
                                @php
                                    $isSelected = $detailType === 'device' && $detailId === $device->id;
                                    $isChecked  = $canManage && in_array((string) $device->id, $selected, true);
                                @endphp
                                <tr wire:key="row-{{ $device->id }}"
                                    class="cursor-pointer transition-colors {{ $isSelected ? 'bg-violet-500/10' : ($isChecked ? 'bg-violet-500/5' : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]') }}">
                                    @if($canManage)
                                        <td class="px-5 py-3">
                                            <input type="checkbox" value="{{ $device->id }}" wire:model.live="selected" class="rounded border-black/20 dark:border-white/20 text-violet-600 focus:ring-violet-500/30" />
                                        </td>
                                    @endif
                                    @foreach($columns as $colKey)
                                        @switch($colKey)
                                            @case('device')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3">
                                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $device->device_name ?? '—' }}</div>
                                                    @if($device->model)
                                                        <div class="text-xs text-gray-400">{{ $device->manufacturer }} {{ $device->model }}</div>
                                                    @endif
                                                    @if($device->lifecycle_status || $device->isExpiringSoon())
                                                        <div class="flex flex-wrap items-center gap-1 mt-1">
                                                            @if($device->lifecycle_status)
                                                                @php $lc = $device->lifecycleBadgeColor(); @endphp
                                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-medium bg-{{ $lc }}-500/10 text-{{ $lc }}-600">{{ $device->lifecycleLabel() }}</span>
                                                            @endif
                                                            @if($device->isExpiringSoon())
                                                                <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-medium bg-amber-500/10 text-amber-600">@svg('heroicon-o-exclamation-triangle', 'w-2.5 h-2.5') läuft ab</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </td>
                                            @break
                                            @case('user')
                                                <td class="px-5 py-3">
                                                    @if($device->user_principal_name)
                                                        <button wire:click.stop="selectEmployeeByUpn('{{ $device->user_principal_name }}')" class="text-left hover:text-violet-600">
                                                            <div class="text-gray-700 dark:text-gray-300">{{ $device->user_display_name ?? '—' }}</div>
                                                            <div class="text-xs text-gray-400 truncate max-w-[180px]">{{ $device->user_principal_name }}</div>
                                                        </button>
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                            @break
                                            @case('os')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3">
                                                    <div class="text-gray-700 dark:text-gray-300">{{ $device->operating_system ?? '—' }}</div>
                                                    @if($device->os_version)<div class="text-xs text-gray-400">{{ $device->os_version }}</div>@endif
                                                </td>
                                            @break
                                            @case('status')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3">
                                                    @php $color = $device->complianceBadgeColor() @endphp
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-{{ $color }}-500/10 text-{{ $color }}-600">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                                                        {{ $device->complianceLabel() }}
                                                    </span>
                                                </td>
                                            @break
                                            @case('lastCheckIn')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ $device->last_check_in_at ? $device->last_check_in_at->diffForHumans() : '—' }}
                                                </td>
                                            @break
                                        @endswitch
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($devices->hasPages())
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">{{ $devices->links() }}</div>
                    @endif
                @endif
            </div>

            @endif {{-- Connector OK --}}

        </div>

        {{-- BOTTOM PANEL: Verteilungen --}}
        @if($config && $config->isConfigured() && $stats['total'] > 0)
            <div class="shrink-0 border-t border-[color:var(--ui-border)] bg-[var(--ui-muted-5)]" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="w-full p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--ui-muted-10)] text-[11px] uppercase tracking-wider text-[var(--ui-muted)]">
                    <span class="font-semibold">Verteilung</span>
                    <span class="text-[10px]">OS · Compliance</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up', 'w-3 h-3', ['x-show' => 'open', 'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--ui-border)] p-4 max-h-64 overflow-y-auto bg-white dark:bg-black/20">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-3">Betriebssysteme</div>
                            <div class="space-y-2">
                                @foreach($osBreakdown as $row)
                                    @php $pct = $stats['total'] > 0 ? round($row->count / $stats['total'] * 100) : 0; @endphp
                                    <div>
                                        <div class="flex items-baseline justify-between text-[11px] mb-0.5">
                                            <span class="text-[var(--ui-secondary)] truncate">{{ $row->os }}</span>
                                            <span class="text-[var(--ui-muted)] tabular-nums ml-2 flex-shrink-0">{{ $row->count }} ({{ $pct }}%)</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-[var(--ui-muted-10)] overflow-hidden">
                                            <div class="h-full bg-violet-500" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-3">Compliance-Status</div>
                            <div class="space-y-2">
                                @foreach($complianceBreakdown as $row)
                                    @php
                                        $pct = $stats['total'] > 0 ? round($row->count / $stats['total'] * 100) : 0;
                                        $color = match($row->compliance_state) {
                                            'compliant'=>'emerald','noncompliant'=>'red','inGracePeriod'=>'amber',
                                            'error'=>'red','conflict'=>'orange',default=>'gray',
                                        };
                                        $label = match($row->compliance_state) {
                                            'compliant'=>'Konform','noncompliant'=>'Nicht konform','inGracePeriod'=>'Karenzzeit',
                                            'error'=>'Fehler','conflict'=>'Konflikt',default=>'Unbekannt',
                                        };
                                    @endphp
                                    <div>
                                        <div class="flex items-baseline justify-between text-[11px] mb-0.5">
                                            <span class="inline-flex items-center gap-1.5 text-[var(--ui-secondary)]">
                                                <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                                                {{ $label }}
                                            </span>
                                            <span class="text-[var(--ui-muted)] tabular-nums">{{ $row->count }} ({{ $pct }}%)</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-[var(--ui-muted-10)] overflow-hidden">
                                            <div class="h-full bg-{{ $color }}-500" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
