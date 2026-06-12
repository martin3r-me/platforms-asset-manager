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

                {{-- Suche --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-2.5 flex items-center pointer-events-none">
                                @svg('heroicon-o-magnifying-glass', 'w-3.5 h-3.5 text-gray-400')
                            </div>
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="search"
                                placeholder="Name, Nutzer, Seriennr..."
                                class="w-full pl-8 pr-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all"
                            />
                        </div>
                    </div>
                </section>

                {{-- Compliance --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Compliance-Status</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterCompliance"
                            class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all">
                            <option value="">Alle</option>
                            <option value="compliant">Konform</option>
                            <option value="noncompliant">Nicht konform</option>
                            <option value="inGracePeriod">Karenzzeit</option>
                            <option value="unknown">Unbekannt</option>
                            <option value="error">Fehler</option>
                        </select>
                    </div>
                </section>

                {{-- OS --}}
                @if($osList->count() > 0)
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Betriebssystem</h3>
                        <div class="px-3 pb-3">
                            <select wire:model.live="filterOs"
                                class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all">
                                <option value="">Alle</option>
                                @foreach($osList as $os)
                                    <option value="{{ $os }}">{{ $os }}</option>
                                @endforeach
                            </select>
                        </div>
                    </section>
                @endif

                {{-- Anzeige --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Anzeige</h3>
                    <div class="px-3 pb-2 text-[11px]">
                        <div class="flex items-center justify-between py-1.5">
                            <span class="text-[var(--ui-muted)]">Pro Seite</span>
                            <select wire:model.live="perPage"
                                class="px-2 py-1 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-violet-500/30">
                                <option value="15">15</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                    <button wire:click="resetColumnOrder"
                            class="w-full text-left px-3 py-2 border-t border-[var(--ui-border)]/30 text-[11px] text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)] hover:text-[var(--ui-secondary)] transition-colors">
                        @svg('heroicon-o-arrow-uturn-left', 'w-3 h-3 inline -mt-0.5 mr-1')
                        Spalten zurücksetzen
                    </button>
                </section>

                {{-- Filter zurücksetzen --}}
                @if($search || $filterCompliance || $filterOs)
                    <button wire:click="$set('search', ''); $set('filterCompliance', ''); $set('filterOs', '')"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10 transition-colors">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Alle Filter zurücksetzen
                    </button>
                @endif

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Sync-Aktivitäten --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Sync" icon="heroicon-o-arrow-path" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">

                {{-- Connector-Status --}}
                @if($config && $config->isConfigured())
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-2 h-2 rounded-full
                                {{ $config->sync_status === 'success' ? 'bg-emerald-500' : '' }}
                                {{ $config->sync_status === 'error'   ? 'bg-red-500'     : '' }}
                                {{ $config->sync_status === 'running' ? 'bg-amber-500 animate-pulse' : '' }}
                                {{ $config->sync_status === 'idle'    ? 'bg-gray-400'    : '' }}">
                            </div>
                            <div class="text-[11px] font-medium text-[var(--ui-secondary)]">
                                @if($config->sync_status === 'success') Aktiv
                                @elseif($config->sync_status === 'error') Fehler
                                @elseif($config->sync_status === 'running') Läuft...
                                @else Bereit
                                @endif
                            </div>
                            @if($config->last_sync_at)
                                <div class="text-[10px] text-[var(--ui-muted)] ml-auto">{{ $config->last_sync_at->diffForHumans() }}</div>
                            @endif
                        </div>

                        @if($canSync && $config->sync_status !== 'running')
                            <button wire:click="syncNow"
                                    wire:loading.attr="disabled"
                                    wire:target="syncNow"
                                    class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-[11px] font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-md hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm disabled:opacity-60">
                                <span wire:loading.remove wire:target="syncNow" class="flex items-center gap-1.5">
                                    @svg('heroicon-o-arrow-path', 'w-3 h-3')
                                    Jetzt synchronisieren
                                </span>
                                <span wire:loading wire:target="syncNow" class="flex items-center gap-1.5">
                                    @svg('heroicon-o-arrow-path', 'w-3 h-3 animate-spin')
                                    Startet...
                                </span>
                            </button>
                        @endif
                    </section>
                @endif

                {{-- Sync-Feedback --}}
                @if($syncResult)
                    <div class="p-2.5 rounded-lg bg-violet-500/10 border border-violet-500/20 text-[11px] text-violet-700 dark:text-violet-400">
                        {{ $syncResult }}
                    </div>
                @endif

                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1 pt-2">Letzte Synchronisierungen</div>

                @forelse($activities as $activity)
                    <div class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="text-[12px] font-medium text-[var(--ui-secondary)] leading-snug">
                                @if($activity->status === 'success')     Sync erfolgreich
                                @elseif($activity->status === 'error')   Sync fehlgeschlagen
                                @else                                    Sync gestartet
                                @endif
                            </div>
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[9px] font-medium rounded-full flex-shrink-0
                                {{ $activity->status === 'success' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : '' }}
                                {{ $activity->status === 'error'   ? 'bg-red-500/10 text-red-600 dark:text-red-400'           : '' }}
                                {{ $activity->status === 'started' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'      : '' }}">
                                {{ $activity->status }}
                            </span>
                        </div>
                        @if($activity->status === 'success')
                            <div class="text-[10px] text-[var(--ui-muted)] mb-1 space-y-0.5">
                                <div>{{ $activity->devices_synced ?? 0 }} synchronisiert</div>
                                @if(($activity->devices_added ?? 0) > 0)   <div>+{{ $activity->devices_added }} neu</div> @endif
                                @if(($activity->devices_removed ?? 0) > 0) <div>−{{ $activity->devices_removed }} entfernt</div> @endif
                            </div>
                        @elseif($activity->status === 'error' && $activity->error_message)
                            <div class="text-[10px] text-red-500 mb-1 break-words">{{ Str::limit($activity->error_message, 120) }}</div>
                        @endif
                        <div class="flex items-center gap-1.5 text-[10px] text-[var(--ui-muted)]">
                            @svg('heroicon-o-clock', 'w-3 h-3 opacity-60')
                            <span>{{ $activity->started_at->diffForHumans() }}</span>
                            @if($activity->duration_ms)
                                <span class="ml-auto">{{ number_format($activity->duration_ms / 1000, 1) }}s</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[var(--ui-muted)]">
                        Noch keine Aktivitäten.
                    </div>
                @endforelse
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Kein Connector --}}
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

            {{-- Connector Fehler --}}
            @elseif($config->sync_status === 'error' && $config->sync_error)
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-red-500 flex-shrink-0 mt-0.5')
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-red-700 dark:text-red-400">Letzter Sync fehlgeschlagen</p>
                        <p class="text-xs text-red-600/80 dark:text-red-400/80 mt-0.5">{{ $config->sync_error }}</p>
                    </div>
                </div>

            @else

            {{-- Stat-Karten --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Geräte gesamt</div>
                </div>
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['compliant'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Konform</div>
                </div>
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-red-500/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-red-600 dark:text-red-400">{{ $stats['noncompliant'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Nicht konform</div>
                </div>
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-gray-500 dark:text-gray-400">{{ $stats['unknown'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Unbekannt</div>
                </div>
            </div>

            {{-- Aktive Filter (Badges) --}}
            @if($search || $filterCompliance || $filterOs)
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-400">Aktive Filter:</span>
                    @if($search)
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-400">
                            Suche: "{{ Str::limit($search, 30) }}"
                            <button wire:click="$set('search', '')" class="hover:text-violet-800">×</button>
                        </span>
                    @endif
                    @if($filterCompliance)
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-400">
                            Status: {{ $filterCompliance }}
                            <button wire:click="$set('filterCompliance', '')" class="hover:text-violet-800">×</button>
                        </span>
                    @endif
                    @if($filterOs)
                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-400">
                            OS: {{ $filterOs }}
                            <button wire:click="$set('filterOs', '')" class="hover:text-violet-800">×</button>
                        </span>
                    @endif
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

            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>

                @if($devices->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-computer-desktop', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">
                            @if($search || $filterCompliance || $filterOs)
                                Keine Geräte gefunden für diese Filtereinstellungen.
                            @else
                                Noch keine Geräte synchronisiert.
                            @endif
                        </p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr wire:sortable="reorderColumns"
                                wire:sortable.options="{ axis: 'x' }"
                                class="border-b border-black/5 dark:border-white/5">
                                @foreach($columns as $colKey)
                                    @php $def = $columnDefs[$colKey] ?? null; @endphp
                                    @if($def)
                                        <th wire:sortable.item="{{ $colKey }}"
                                            wire:key="col-head-{{ $colKey }}"
                                            class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400 bg-white/40 dark:bg-white/[0.02]">
                                            <div class="flex items-center gap-2">
                                                <button wire:sortable.handle
                                                        type="button"
                                                        title="Spalte verschieben"
                                                        class="text-gray-300 hover:text-gray-500 dark:hover:text-gray-300 cursor-grab active:cursor-grabbing">
                                                    @svg('heroicon-o-bars-3', 'w-3.5 h-3.5')
                                                </button>
                                                @if($def['sortField'])
                                                    <button wire:click="sortBy('{{ $def['sortField'] }}')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
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
                                <tr wire:key="row-{{ $device->id }}" class="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors group">
                                    @foreach($columns as $colKey)
                                        @switch($colKey)
                                            @case('device')
                                                <td class="px-5 py-3">
                                                    <a href="{{ route('asset-manager.devices.show', $device) }}" wire:navigate
                                                       class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors">
                                                        {{ $device->device_name ?? '—' }}
                                                    </a>
                                                    @if($device->model)
                                                        <div class="text-xs text-gray-400">{{ $device->manufacturer }} {{ $device->model }}</div>
                                                    @endif
                                                </td>
                                            @break
                                            @case('user')
                                                <td class="px-5 py-3">
                                                    <div class="text-gray-700 dark:text-gray-300">{{ $device->user_display_name ?? '—' }}</div>
                                                    @if($device->user_principal_name)
                                                        <div class="text-xs text-gray-400 truncate max-w-[180px]">{{ $device->user_principal_name }}</div>
                                                    @endif
                                                </td>
                                            @break
                                            @case('os')
                                                <td class="px-5 py-3">
                                                    <div class="text-gray-700 dark:text-gray-300">{{ $device->operating_system ?? '—' }}</div>
                                                    @if($device->os_version)
                                                        <div class="text-xs text-gray-400">{{ $device->os_version }}</div>
                                                    @endif
                                                </td>
                                            @break
                                            @case('status')
                                                <td class="px-5 py-3">
                                                    @php $color = $device->complianceBadgeColor() @endphp
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                                        bg-{{ $color }}-500/10 text-{{ $color }}-600 dark:text-{{ $color }}-400">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                                                        {{ $device->complianceLabel() }}
                                                    </span>
                                                </td>
                                            @break
                                            @case('lastCheckIn')
                                                <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400">
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
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                            {{ $devices->links() }}
                        </div>
                    @endif
                @endif
            </div>

            @endif {{-- Connector OK --}}

        </div>

        {{-- BOTTOM PANEL: Verteilungen --}}
        @if($config && $config->isConfigured() && $stats['total'] > 0)
            <div class="shrink-0 border-t border-[color:var(--ui-border)] bg-[var(--ui-muted-5)]" x-data="{ open: false }">
                <button
                    type="button"
                    @click="open = !open"
                    class="w-full cursor-pointer p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--ui-muted-10)] transition-colors text-[11px] uppercase tracking-wider text-[var(--ui-muted)]">
                    <span class="font-semibold">Verteilung</span>
                    <span class="text-[10px]">OS · Compliance</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up',   'w-3 h-3', ['x-show' => 'open',  'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--ui-border)] p-4 max-h-64 overflow-y-auto bg-white dark:bg-black/20">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        {{-- OS-Verteilung --}}
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
                                            <div class="h-full bg-violet-500 transition-all" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Compliance-Verteilung --}}
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-3">Compliance-Status</div>
                            <div class="space-y-2">
                                @foreach($complianceBreakdown as $row)
                                    @php
                                        $pct = $stats['total'] > 0 ? round($row->count / $stats['total'] * 100) : 0;
                                        $color = match($row->compliance_state) {
                                            'compliant'     => 'emerald',
                                            'noncompliant'  => 'red',
                                            'inGracePeriod' => 'amber',
                                            'error'         => 'red',
                                            'conflict'      => 'orange',
                                            default         => 'gray',
                                        };
                                        $label = match($row->compliance_state) {
                                            'compliant'     => 'Konform',
                                            'noncompliant'  => 'Nicht konform',
                                            'inGracePeriod' => 'Karenzzeit',
                                            'error'         => 'Fehler',
                                            'conflict'      => 'Konflikt',
                                            default         => 'Unbekannt',
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
                                            <div class="h-full bg-{{ $color }}-500 transition-all" style="width: {{ $pct }}%"></div>
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
