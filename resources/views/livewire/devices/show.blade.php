<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte', 'href' => route('asset-manager.devices.index'), 'icon' => 'computer-desktop'],
            ['label' => $device->device_name ?? 'Gerät'],
        ]" />
    </x-slot>

    {{-- LINKS: Eigenschaften --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Eigenschaften" icon="heroicon-o-adjustments-horizontal" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                {{-- Compliance --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Compliance</h3>
                    @php $color = $device->complianceBadgeColor() @endphp
                    <div class="py-2 px-3 flex items-center justify-between text-[11px]">
                        <span class="text-[var(--ui-muted)]">Status</span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium bg-{{ $color }}-500/10 text-{{ $color }}-600 dark:text-{{ $color }}-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                            {{ $device->complianceLabel() }}
                        </span>
                    </div>
                    <div class="py-2 px-3 border-t border-[var(--ui-border)]/30 flex items-center justify-between text-[11px]">
                        <span class="text-[var(--ui-muted)]">Management</span>
                        <span class="font-medium text-[var(--ui-secondary)]">{{ $device->management_state ?? '—' }}</span>
                    </div>
                </section>

                {{-- Hardware --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Hardware</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        @foreach([
                            ['Hersteller',  $device->manufacturer],
                            ['Modell',      $device->model],
                            ['Typ',         $device->device_type],
                            ['Seriennr.',   $device->serial_number],
                        ] as [$label, $value])
                            <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                                <dt class="text-[var(--ui-muted)]">{{ $label }}</dt>
                                <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- OS --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Betriebssystem</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">System</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->operating_system ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Version</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right tabular-nums">{{ $device->os_version ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Nutzer --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Nutzer</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Name</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->user_display_name ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">E-Mail</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->user_principal_name ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Zeitstempel --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Zeitstempel</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Enrollt</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->enrolled_at?->format('d.m.Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Letztes Check-In</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->last_check_in_at?->diffForHumans() ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Intune ID --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Intune ID</h3>
                    <div class="py-1.5 px-3 text-[10px] font-mono text-[var(--ui-secondary)] break-all">{{ $device->intune_id }}</div>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Aktivitäten / Sync-History --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1">Letzte Synchronisierungen</div>
                @forelse($activities as $activity)
                    <div class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="text-[12px] font-medium text-[var(--ui-secondary)] leading-snug">
                                @if($activity->status === 'success')
                                    Sync erfolgreich
                                @elseif($activity->status === 'error')
                                    Sync fehlgeschlagen
                                @else
                                    Sync gestartet
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
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-3xl mx-auto space-y-5">

                {{-- Header --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent"></div>
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 flex items-center justify-center">
                            @svg('heroicon-o-computer-desktop', 'w-6 h-6 text-violet-500')
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">
                                {{ $device->device_name ?? 'Unbekanntes Gerät' }}
                            </h1>
                            @if($device->user_display_name)
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $device->user_display_name }}</p>
                            @endif
                        </div>
                        @php $color = $device->complianceBadgeColor() @endphp
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-{{ $color }}-500/10 text-{{ $color }}-600 dark:text-{{ $color }}-400 flex-shrink-0">
                            <span class="w-2 h-2 rounded-full bg-{{ $color }}-500"></span>
                            {{ $device->complianceLabel() }}
                        </span>
                    </div>
                </div>

                {{-- Quick Stats --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">Betriebssystem</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $device->operating_system ?? '—' }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">{{ $device->os_version ?? '' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">Hardware</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">{{ $device->manufacturer ?? '—' }}</div>
                        <div class="text-xs text-gray-400 mt-0.5 truncate">{{ $device->model ?? '' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">Letztes Check-In</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $device->last_check_in_at?->diffForHumans() ?? '—' }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">{{ $device->last_check_in_at?->format('d.m.Y H:i') ?? '' }}</div>
                    </div>
                </div>

                {{-- Nutzer-Zuweisung --}}
                @if($device->user_display_name || $device->user_principal_name)
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                        <div class="px-4 py-3 border-b border-black/5 dark:border-white/5">
                            <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Nutzer-Zuweisung</h2>
                        </div>
                        <div class="p-4 space-y-1">
                            <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $device->user_display_name ?? '—' }}</div>
                            @if($device->user_principal_name)
                                <div class="text-xs text-gray-400">{{ $device->user_principal_name }}</div>
                            @endif
                        </div>
                    </div>
                @endif

            </div>
        </div>

        {{-- BOTTOM PANEL: Raw Data --}}
        @if($device->raw_data)
            <div class="shrink-0 border-t border-[color:var(--ui-border)] bg-[var(--ui-muted-5)]" x-data="{ open: false }">
                <button
                    type="button"
                    @click="open = !open"
                    class="w-full cursor-pointer p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--ui-muted-10)] transition-colors text-[11px] uppercase tracking-wider text-[var(--ui-muted)]">
                    <span class="font-semibold">Rohdaten (Graph API)</span>
                    <span class="text-[10px]">{{ count((array) $device->raw_data) }} Felder</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up',   'w-3 h-3', ['x-show' => 'open',  'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--ui-border)] p-4 max-h-64 overflow-y-auto bg-white dark:bg-black/20">
                    <pre class="text-[10px] text-gray-500 dark:text-gray-400 font-mono whitespace-pre-wrap break-all">{{ json_encode($device->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
