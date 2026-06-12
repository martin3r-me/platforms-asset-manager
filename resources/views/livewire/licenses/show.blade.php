<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lizenzen', 'href' => route('asset-manager.licenses.index'), 'icon' => 'key'],
            ['label' => $sku->display_name ?? $sku->sku_part_number],
        ]" />
    </x-slot>

    {{-- LINKS: Eigenschaften --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Eigenschaften" icon="heroicon-o-adjustments-horizontal" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                {{-- Auslastung --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Auslastung</h3>
                    @php $pct = $sku->utilizationPercent(); $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                    <div class="py-2 px-3">
                        <div class="flex items-baseline justify-between mb-1.5">
                            <span class="text-[11px] text-[var(--ui-muted)]">Genutzt</span>
                            <span class="text-[13px] font-semibold text-{{ $color }}-600 dark:text-{{ $color }}-400 tabular-nums">{{ $pct }}%</span>
                        </div>
                        <div class="w-full h-1.5 rounded-full bg-[var(--ui-muted-10)] overflow-hidden">
                            <div class="h-full bg-{{ $color }}-500 transition-all" style="width: {{ min($pct, 100) }}%"></div>
                        </div>
                    </div>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Gekauft</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $sku->purchased_units }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Genutzt</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $sku->consumed_units }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Frei</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $sku->available_units }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Preis --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Preis</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Stückpreis / Monat</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">
                                @if($sku->unit_price !== null)
                                    {{ number_format((float) $sku->unit_price, 2, ',', '.') }} €
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Gesamt / Monat</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 font-semibold tabular-nums">
                                @if($sku->unit_price !== null)
                                    {{ number_format($sku->monthlyCost(), 2, ',', '.') }} €
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                {{-- SKU-Info --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">SKU</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Part Number</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $sku->sku_part_number }}</dd>
                        </div>
                        <div class="py-1.5 px-3">
                            <div class="text-[var(--ui-muted)] mb-1">SKU ID</div>
                            <div class="text-[10px] font-mono text-[var(--ui-secondary)] break-all">{{ $sku->sku_id }}</div>
                        </div>
                        @if($sku->synced_at)
                            <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                                <dt class="text-[var(--ui-muted)]">Letzter Sync</dt>
                                <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $sku->synced_at->diffForHumans() }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Aktivitäten --}}
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
                                <div>{{ $activity->skus_synced ?? 0 }} SKUs · {{ $activity->assignments_synced ?? 0 }} Zuweisungen</div>
                                @if(($activity->assignments_added ?? 0) > 0)   <div>+{{ $activity->assignments_added }} neu</div> @endif
                                @if(($activity->assignments_removed ?? 0) > 0) <div>−{{ $activity->assignments_removed }} entfernt</div> @endif
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
            <div class="space-y-5">

                {{-- Header-Karte --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent"></div>
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 flex items-center justify-center">
                            @svg('heroicon-o-key', 'w-6 h-6 text-violet-500')
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {{ $sku->display_name ?? $sku->sku_part_number }}
                            </h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sku->sku_part_number }}</p>
                        </div>
                        <div class="flex-shrink-0 text-right">
                            @php $pct = $sku->utilizationPercent(); $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                            <div class="text-2xl font-semibold text-{{ $color }}-600 dark:text-{{ $color }}-400">{{ $pct }}%</div>
                            <div class="text-xs text-gray-400">{{ $sku->consumed_units }}/{{ $sku->purchased_units }} genutzt</div>
                        </div>
                    </div>
                </div>

                {{-- Suche --}}
                <div class="relative max-w-sm">
                    <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                        @svg('heroicon-o-magnifying-glass', 'w-4 h-4 text-gray-400')
                    </div>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name oder E-Mail suchen..."
                        class="w-full pl-9 pr-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all"
                    />
                </div>

                {{-- Tabelle --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>

                    @if($assignments->isEmpty())
                        <div class="flex flex-col items-center justify-center py-16 text-center">
                            @svg('heroicon-o-users', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                            <p class="text-sm text-gray-400">
                                {{ $search ? 'Keine Nutzer gefunden.' : 'Keine Lizenzzuweisungen gefunden.' }}
                            </p>
                        </div>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-black/5 dark:border-white/5">
                                    <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Name</th>
                                    <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">E-Mail</th>
                                    <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Zugewiesen seit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                                @foreach($assignments as $assignment)
                                    <tr class="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                                        <td class="px-5 py-3">
                                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ $assignment->display_name ?? '—' }}
                                            </div>
                                        </td>
                                        <td class="px-5 py-3 text-gray-500 dark:text-gray-400">
                                            {{ $assignment->user_principal_name }}
                                        </td>
                                        <td class="px-5 py-3 text-gray-500 dark:text-gray-400">
                                            {{ $assignment->assigned_at ? $assignment->assigned_at->format('d.m.Y') : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if($assignments->hasPages())
                            <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                                {{ $assignments->links() }}
                            </div>
                        @endif
                    @endif
                </div>

            </div>
        </div>

        {{-- BOTTOM PANEL: Raw Data --}}
        @if($sku->raw_data)
            <div class="shrink-0 border-t border-[color:var(--ui-border)] bg-[var(--ui-muted-5)]" x-data="{ open: false }">
                <button
                    type="button"
                    @click="open = !open"
                    class="w-full cursor-pointer p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--ui-muted-10)] transition-colors text-[11px] uppercase tracking-wider text-[var(--ui-muted)]">
                    <span class="font-semibold">Rohdaten (Graph API)</span>
                    <span class="text-[10px]">{{ count((array) $sku->raw_data) }} Felder</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up',   'w-3 h-3', ['x-show' => 'open',  'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--ui-border)] p-4 max-h-64 overflow-y-auto bg-white dark:bg-black/20">
                    <pre class="text-[10px] text-gray-500 dark:text-gray-400 font-mono whitespace-pre-wrap break-all">{{ json_encode($sku->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
