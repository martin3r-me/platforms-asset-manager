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
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">

                {{-- Auslastung --}}
                <x-asset-manager-panel title="Auslastung">
                    @php $pct = $sku->utilizationPercent(); $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                    <div class="mb-1">
                        <div class="flex items-baseline justify-between mb-1.5">
                            <span class="text-[11px] text-[var(--am-text-secondary)]">Genutzt</span>
                            <span class="text-[13px] font-semibold text-{{ $color }}-700 tabular-nums">{{ $pct }}%</span>
                        </div>
                        <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                            <div class="h-full bg-{{ $color }}-500 transition-all" style="width: {{ min($pct, 100) }}%"></div>
                        </div>
                    </div>
                    <x-asset-manager-detail-list>
                        <x-asset-manager-detail-row label="Gekauft"><span class="tabular-nums">{{ $sku->purchased_units }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="Genutzt"><span class="tabular-nums">{{ $sku->consumed_units }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="Frei"><span class="tabular-nums">{{ $sku->available_units }}</span></x-asset-manager-detail-row>
                    </x-asset-manager-detail-list>
                </x-asset-manager-panel>

                {{-- Preis --}}
                <x-asset-manager-panel title="Preis">
                    <x-asset-manager-detail-list>
                        <x-asset-manager-detail-row label="Stückpreis / Monat">
                            @if($sku->unit_price !== null)
                                <span class="tabular-nums">{{ number_format((float) $sku->unit_price, 2, ',', '.') }} €</span>
                            @else
                                <span class="text-[var(--am-text-muted)]">—</span>
                            @endif
                        </x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="Gesamt / Monat">
                            @if($sku->unit_price !== null)
                                <span class="font-semibold tabular-nums">{{ number_format($sku->monthlyCost(), 2, ',', '.') }} €</span>
                            @else
                                <span class="text-[var(--am-text-muted)]">—</span>
                            @endif
                        </x-asset-manager-detail-row>
                    </x-asset-manager-detail-list>
                </x-asset-manager-panel>

                {{-- SKU-Info --}}
                <x-asset-manager-panel title="SKU">
                    <x-asset-manager-detail-list>
                        <x-asset-manager-detail-row label="Part Number"><span class="block truncate">{{ $sku->sku_part_number }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="SKU ID" mono><span class="block truncate text-[var(--am-text-muted)]">{{ $sku->sku_id }}</span></x-asset-manager-detail-row>
                        @if($sku->synced_at)
                            <x-asset-manager-detail-row label="Letzter Sync"><span class="tabular-nums">{{ $sku->synced_at->diffForHumans() }}</span></x-asset-manager-detail-row>
                        @endif
                    </x-asset-manager-detail-list>
                </x-asset-manager-panel>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Aktivitäten --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-1">Letzte Synchronisierungen</div>
                @forelse($activities as $activity)
                    <div class="p-3 rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="text-[12px] font-medium text-[var(--am-text)] leading-snug">
                                @if($activity->status === 'success')
                                    Sync erfolgreich
                                @elseif($activity->status === 'error')
                                    Sync fehlgeschlagen
                                @else
                                    Sync gestartet
                                @endif
                            </div>
                            @php $syncColor = ['success' => 'emerald', 'error' => 'red', 'started' => 'amber'][$activity->status] ?? 'gray'; @endphp
                            <x-asset-manager-badge :color="$syncColor" size="xs" class="flex-shrink-0">{{ $activity->status }}</x-asset-manager-badge>
                        </div>
                        @if($activity->status === 'success')
                            <div class="text-[10px] text-[var(--am-text-secondary)] mb-1 space-y-0.5">
                                <div>{{ $activity->skus_synced ?? 0 }} SKUs · {{ $activity->assignments_synced ?? 0 }} Zuweisungen</div>
                                @if(($activity->assignments_added ?? 0) > 0)   <div>+{{ $activity->assignments_added }} neu</div> @endif
                                @if(($activity->assignments_removed ?? 0) > 0) <div>−{{ $activity->assignments_removed }} entfernt</div> @endif
                            </div>
                        @elseif($activity->status === 'error' && $activity->error_message)
                            <div class="text-[10px] text-red-700 mb-1 break-words">{{ Str::limit($activity->error_message, 120) }}</div>
                        @endif
                        <div class="flex items-center gap-1.5 text-[10px] text-[var(--am-text-muted)]">
                            @svg('heroicon-o-clock', 'w-3 h-3 opacity-60')
                            <span>{{ $activity->started_at->diffForHumans() }}</span>
                            @if($activity->duration_ms)
                                <span class="ml-auto">{{ number_format($activity->duration_ms / 1000, 1) }}s</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[var(--am-text-muted)]">
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
                <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-[var(--am-accent-surface)] flex items-center justify-center">
                            @svg('heroicon-o-key', 'w-6 h-6 text-[var(--am-accent)]')
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-lg font-semibold text-[var(--am-text)]">
                                {{ $sku->display_name ?? $sku->sku_part_number }}
                            </h1>
                            <p class="text-sm text-[var(--am-text-secondary)]">{{ $sku->sku_part_number }}</p>
                        </div>
                        <div class="flex-shrink-0 text-right">
                            @php $pct = $sku->utilizationPercent(); $color = $pct >= 95 ? 'red' : ($pct >= 80 ? 'amber' : 'emerald'); @endphp
                            <div class="text-2xl font-semibold text-{{ $color }}-700">{{ $pct }}%</div>
                            <div class="text-xs text-[var(--am-text-secondary)]">{{ $sku->consumed_units }}/{{ $sku->purchased_units }} genutzt</div>
                        </div>
                    </div>
                </div>

                {{-- Suche --}}
                <div class="relative max-w-sm">
                    <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none z-10">
                        @svg('heroicon-o-magnifying-glass', 'w-4 h-4 text-[var(--am-text-muted)]')
                    </div>
                    <x-asset-manager-input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name oder E-Mail suchen..."
                        class="pl-9"
                    />
                </div>

                {{-- Tabelle --}}
                <div class="overflow-hidden rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm">

                    @if($assignments->isEmpty())
                        <div class="flex flex-col items-center justify-center py-16 text-center">
                            @svg('heroicon-o-users', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                            <p class="text-sm text-[var(--am-text-secondary)]">
                                {{ $search ? 'Keine Nutzer gefunden.' : 'Keine Lizenzzuweisungen gefunden.' }}
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-[var(--am-bg)] border-b border-[color:var(--am-border)]">
                                        <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Name</th>
                                        <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">E-Mail</th>
                                        <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Zugewiesen seit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[color:var(--am-border)]">
                                    @foreach($assignments as $assignment)
                                        <tr class="hover:bg-[var(--am-bg)] transition-colors">
                                            <td class="px-5 py-3">
                                                <div class="font-medium text-[var(--am-text)]">
                                                    {{ $assignment->display_name ?? '—' }}
                                                </div>
                                            </td>
                                            <td class="px-5 py-3 text-[var(--am-text-secondary)]">
                                                {{ $assignment->user_principal_name }}
                                            </td>
                                            <td class="px-5 py-3 text-[var(--am-text-secondary)]">
                                                {{ $assignment->assigned_at ? $assignment->assigned_at->format('d.m.Y') : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if($assignments->hasPages())
                            <div class="px-5 py-3 border-t border-[color:var(--am-border)]">
                                {{ $assignments->links() }}
                            </div>
                        @endif
                    @endif
                </div>

            </div>
        </div>

        {{-- BOTTOM PANEL: Raw Data --}}
        @if($sku->raw_data)
            <div class="shrink-0 border-t border-[color:var(--am-border)] bg-[var(--am-bg)]" x-data="{ open: false }">
                <button
                    type="button"
                    @click="open = !open"
                    class="w-full cursor-pointer p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--am-surface)] transition-colors text-[11px] uppercase tracking-wider text-[var(--am-text-secondary)]">
                    <span class="font-semibold">Rohdaten (Graph API)</span>
                    <span class="text-[10px] text-[var(--am-text-muted)]">{{ count((array) $sku->raw_data) }} Felder</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up',   'w-3 h-3', ['x-show' => 'open',  'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--am-border)] p-4 max-h-64 overflow-y-auto bg-[var(--am-surface)]">
                    <pre class="text-[10px] text-[var(--am-text-secondary)] font-mono whitespace-pre-wrap break-all">{{ json_encode($sku->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
