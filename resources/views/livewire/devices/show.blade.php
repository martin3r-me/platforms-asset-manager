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

                {{-- Sicherheit & Hardware --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Sicherheit & Hardware</h3>
                    <div class="py-2 px-3 flex items-center justify-between text-[11px]">
                        <span class="text-[var(--ui-muted)]">Verschlüsselung</span>
                        @php $enc = $device->encryptionBadgeColor(); @endphp
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium bg-{{ $enc }}-500/10 text-{{ $enc }}-600 dark:text-{{ $enc }}-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $enc }}-500"></span>
                            {{ $device->encryptionLabel() }}
                        </span>
                    </div>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px] border-t border-[var(--ui-border)]/30">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Enrollment</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->enrollment_type ?: '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Speicher</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->storageSummary() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[var(--ui-muted)]">Arbeitsspeicher</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->memoryLabel() }}</dd>
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
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1">Verlauf</div>
                @forelse($events as $ev)
                    @php $ec = $ev->eventColor(); @endphp
                    <div class="p-2.5 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <div class="flex items-center gap-1.5 mb-0.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $ec }}-500 flex-shrink-0"></span>
                            <span class="text-[11px] font-medium text-[var(--ui-secondary)]">{{ $ev->eventLabel() }}</span>
                        </div>
                        @if($ev->old_value !== null || $ev->new_value !== null)
                            <div class="text-[10px] text-[var(--ui-muted)] break-words pl-3">{{ $ev->old_value ?: '—' }} → {{ $ev->new_value ?: '—' }}</div>
                        @endif
                        <div class="text-[10px] text-[var(--ui-muted)] pl-3 mt-0.5">{{ $ev->created_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[var(--ui-muted)]">Noch keine Änderungen erfasst.</div>
                @endforelse

                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1 pt-2">Letzte Synchronisierungen</div>
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

                {{-- Zuweisung & Lifecycle --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Zuweisung & Lifecycle</h2>
                        @if($canManage && !$editingLifecycle)
                            <button wire:click="editLifecycle" class="text-xs text-violet-600 hover:underline">Bearbeiten</button>
                        @endif
                    </div>

                    @if($lifecycleFlash)
                        <div class="px-4 pt-3 text-[11px] text-emerald-600">{{ $lifecycleFlash }}</div>
                    @endif

                    @if(!$editingLifecycle)
                        <div class="p-4 space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                @php $lc = $device->lifecycleBadgeColor(); @endphp
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-{{ $lc }}-500/10 text-{{ $lc }}-600 dark:text-{{ $lc }}-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-{{ $lc }}-500"></span>
                                    {{ $device->lifecycleLabel() }}
                                </span>
                                @if($device->isExpiringSoon())
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                        @svg('heroicon-o-exclamation-triangle', 'w-3 h-3')
                                        Läuft ab {{ $device->earliestExpiry()?->format('d.m.Y') }}
                                    </span>
                                @endif
                            </div>

                            <dl class="text-[11px] divide-y divide-black/[0.04]">
                                <div class="flex justify-between py-1.5"><dt class="text-gray-400">Standort</dt><dd class="text-gray-600 dark:text-gray-300">{{ $device->location ?: '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-400">Garantie bis</dt><dd class="text-gray-600 dark:text-gray-300 tabular-nums">{{ $device->warranty_until?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-400">Leasing bis</dt><dd class="text-gray-600 dark:text-gray-300 tabular-nums">{{ $device->lease_until?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-400">Lieferant</dt><dd class="text-gray-600 dark:text-gray-300">{{ optional($device->vendor)->name ?? '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-400">Bestell-Nr.</dt><dd class="text-gray-600 dark:text-gray-300">{{ $device->order_no ?: '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-400">Bestelldatum</dt><dd class="text-gray-600 dark:text-gray-300 tabular-nums">{{ $device->order_date?->format('d.m.Y') ?? '—' }}</dd></div>
                            </dl>
                        </div>
                    @else
                        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Status</label>
                                <select wire:model="lStatus" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– kein Status –</option>
                                    <option value="in_use">In Betrieb</option>
                                    <option value="spare">Reserve / Lager</option>
                                    <option value="repair">In Reparatur</option>
                                    <option value="defect">Defekt / Kaputt</option>
                                    <option value="retired">Ausgemustert</option>
                                    <option value="lost">Verloren / Gestohlen</option>
                                </select>
                                @error('lStatus')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Standort</label>
                                <input type="text" wire:model="lLocation" maxlength="255" placeholder="z. B. Büro Bonn, Lager" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Garantie bis</label>
                                <input type="date" wire:model="lWarranty" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                @error('lWarranty')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Leasing bis</label>
                                <input type="date" wire:model="lLease" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Lieferant</label>
                                <select wire:model="lVendor" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– kein Lieferant –</option>
                                    @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Bestell-Nr.</label>
                                <input type="text" wire:model="lOrderNo" maxlength="255" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Bestelldatum</label>
                                <input type="date" wire:model="lOrderDate" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div class="sm:col-span-2 flex items-center gap-2">
                                <button wire:click="saveLifecycle" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Speichern</button>
                                <button wire:click="cancelLifecycle" class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">Abbrechen</button>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Kosten --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Kosten</h2>
                        @if($canManage && !$editingCost)
                            <button wire:click="editCost" class="text-xs text-violet-600 hover:underline">Bearbeiten</button>
                        @endif
                    </div>

                    @if($flash)
                        <div class="px-4 pt-3 text-[11px] text-emerald-600">{{ $flash }}</div>
                    @endif

                    @if(!$editingCost)
                        <div class="p-4 space-y-2">
                            <div class="flex items-baseline gap-2">
                                <span class="text-lg font-semibold text-gray-800 dark:text-gray-100 tabular-nums">{{ $resolvedCost > 0 ? number_format($resolvedCost, 2, ',', '.') . ' €' : '—' }}</span>
                                <span class="text-xs text-gray-400">/ Monat</span>
                            </div>
                            <div class="text-[11px] text-gray-400">
                                @if($device->monthly_cost || ($device->purchase_price && $device->depreciation_months))
                                    Override am Gerät
                                @elseif($deviceModel && ($deviceModel->monthly_cost || ($deviceModel->purchase_price && $deviceModel->depreciation_months)))
                                    Modell-Default ({{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: 'Modell' }})
                                @else
                                    kein Preis hinterlegt — am Geräte-Modell oder hier pflegen
                                @endif
                            </div>
                            <dl class="text-[11px] divide-y divide-black/[0.04] pt-1">
                                <div class="flex justify-between py-1"><dt class="text-gray-400">Kostenart</dt><dd class="text-gray-600">{{ optional($costTypes->firstWhere('id', $resolvedCostTypeId))->name ?? '— (zählt nicht im Pivot)' }}</dd></div>
                                <div class="flex justify-between py-1"><dt class="text-gray-400">Kostenstelle</dt><dd class="text-gray-600">{{ $device->cost_center_id ? (optional($costCenters->firstWhere('id', $device->cost_center_id))->code ?? '—') : 'über Nutzer' }}</dd></div>
                            </dl>
                        </div>
                    @else
                        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Leasing / Monat (€)</label>
                                <input type="number" step="0.01" min="0" wire:model="oMonthly" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                @error('oMonthly')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Kaufpreis (€) / AfA-Monate</label>
                                <div class="flex gap-2">
                                    <input type="number" step="0.01" min="0" wire:model="oPurchase" placeholder="Preis" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <input type="number" step="1" min="1" wire:model="oDep" placeholder="Mon." class="w-24 px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                </div>
                                @error('oPurchase')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Kaufdatum (optional)</label>
                                <input type="date" wire:model="oPurchaseDate" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Kostenart</label>
                                <select wire:model="oCostType" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– vom Modell –</option>
                                    @foreach($costTypes as $ct)<option value="{{ $ct->id }}">{{ $ct->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Kostenstelle (Override)</label>
                                <select wire:model="oCostCenter" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– über Nutzer –</option>
                                    @foreach($costCenters as $cc)<option value="{{ $cc->id }}">{{ $cc->name ? $cc->code . ' — ' . $cc->name : $cc->code }}</option>@endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
                                <button wire:click="saveCost" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Speichern</button>
                                <button wire:click="cancelCost" class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">Abbrechen</button>
                                <span class="text-[11px] text-gray-400">Leasing-Rate <em>oder</em> Kaufpreis + AfA-Monate. Leer = Modell-Default.</span>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Notiz --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Notiz</h2>
                        @if($canManage && !$editingNotes)
                            <button wire:click="editNotes" class="text-xs text-violet-600 hover:underline">Bearbeiten</button>
                        @endif
                    </div>

                    @if($notesFlash)
                        <div class="px-4 pt-3 text-[11px] text-emerald-600">{{ $notesFlash }}</div>
                    @endif

                    @if(!$editingNotes)
                        <div class="p-4">
                            @if($device->notes)
                                <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $device->notes }}</p>
                            @else
                                <p class="text-[11px] text-gray-400">Keine Notiz hinterlegt.</p>
                            @endif
                        </div>
                    @else
                        <div class="p-4 space-y-2">
                            <textarea wire:model="oNotes" rows="4" maxlength="2000"
                                placeholder="z. B. Leihgerät, wartet auf Rückgabe, Display-Schaden gemeldet …"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--ui-border)] bg-white"></textarea>
                            @error('oNotes')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            <div class="flex items-center gap-2">
                                <button wire:click="saveNotes" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Speichern</button>
                                <button wire:click="cancelNotes" class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">Abbrechen</button>
                            </div>
                        </div>
                    @endif
                </div>

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
