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
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Compliance</h3>
                    <div class="py-2 px-3 flex items-center justify-between text-[11px]">
                        <span class="text-[color:var(--ui-secondary)]">Status</span>
                        <x-asset-manager-badge :color="$device->complianceBadgeColor()" dot size="xs">{{ $device->complianceLabel() }}</x-asset-manager-badge>
                    </div>
                    <div class="py-2 px-3 border-t border-[var(--ui-border)]/30 flex items-center justify-between text-[11px]">
                        <span class="text-[color:var(--ui-secondary)]">Management</span>
                        <span class="font-medium text-[var(--ui-secondary)]">{{ $device->management_state ?? '—' }}</span>
                    </div>
                </section>

                {{-- Hardware --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Hardware</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        @foreach([
                            ['Hersteller',  $device->manufacturer],
                            ['Modell',      $device->model],
                            ['Typ',         $device->device_type],
                            ['Seriennr.',   $device->serial_number],
                        ] as [$label, $value])
                            <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                                <dt class="text-[color:var(--ui-secondary)]">{{ $label }}</dt>
                                <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- OS --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Betriebssystem</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">System</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->operating_system ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Version</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right tabular-nums">{{ $device->os_version ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Sicherheit & Hardware --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Sicherheit & Hardware</h3>
                    <div class="py-2 px-3 flex items-center justify-between text-[11px]">
                        <span class="text-[color:var(--ui-secondary)]">Verschlüsselung</span>
                        <x-asset-manager-badge :color="$device->encryptionBadgeColor()" dot size="xs">{{ $device->encryptionLabel() }}</x-asset-manager-badge>
                    </div>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px] border-t border-[var(--ui-border)]/30">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Enrollment</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->enrollment_type ?: '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Speicher</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->storageSummary() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Arbeitsspeicher</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->memoryLabel() }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Nutzer --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Nutzer</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Name</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->user_display_name ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">E-Mail</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 truncate max-w-[55%] text-right">{{ $device->user_principal_name ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Zeitstempel --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Zeitstempel</h3>
                    <dl class="divide-y divide-[var(--ui-border)]/30 text-[11px]">
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Enrollt</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->enrolled_at?->format('d.m.Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 py-1.5 px-3">
                            <dt class="text-[color:var(--ui-secondary)]">Letztes Check-In</dt>
                            <dd class="text-[var(--ui-secondary)] m-0 tabular-nums">{{ $device->last_check_in_at?->diffForHumans() ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Intune ID --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-3 pt-3 pb-1.5">Intune ID</h3>
                    <div class="py-1.5 px-3 text-[10px] font-mono text-[var(--ui-secondary)] break-all">{{ $device->intune_id }}</div>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Aktivitäten / Sync-History --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-1">Verlauf</div>
                @forelse($events as $ev)
                    @php $ec = $ev->eventColor(); @endphp
                    <div class="p-2.5 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <div class="flex items-center gap-1.5 mb-0.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $ec }}-500 flex-shrink-0"></span>
                            <span class="text-[11px] font-medium text-[var(--ui-secondary)]">{{ $ev->eventLabel() }}</span>
                        </div>
                        @if($ev->old_value !== null || $ev->new_value !== null)
                            <div class="text-[10px] text-[color:var(--ui-secondary)] break-words pl-3">{{ $ev->old_value ?: '—' }} → {{ $ev->new_value ?: '—' }}</div>
                        @endif
                        <div class="text-[10px] text-[color:var(--ui-secondary)] pl-3 mt-0.5">
                            {{ $ev->created_at?->diffForHumans() }}@if($ev->actor) · {{ $ev->actor->name }}@endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[color:var(--ui-secondary)]">Noch keine Änderungen erfasst.</div>
                @endforelse

                <div class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-1 pt-2">Zuordnungs-Verlauf</div>
                @forelse($assignments as $a)
                    <div class="p-2.5 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <div class="flex items-center gap-1.5 mb-0.5">
                            <span class="w-1.5 h-1.5 rounded-full {{ $a->returned_at ? 'bg-gray-400' : 'bg-emerald-500' }} flex-shrink-0"></span>
                            <span class="text-[11px] font-medium text-[var(--ui-secondary)]">{{ $a->employee?->name ?? '—' }}</span>
                        </div>
                        <div class="text-[10px] text-[color:var(--ui-secondary)] pl-3">
                            {{ $a->assigned_at?->format('d.m.Y') ?? '—' }} – {{ $a->returned_at?->format('d.m.Y') ?? 'laufend' }}@if($a->source === 'intune') · Intune @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[color:var(--ui-secondary)]">Noch keine Zuordnung erfasst.</div>
                @endforelse

                <div class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)] px-1 pt-2">Letzte Synchronisierungen</div>
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
                            @php $syncColor = ['success' => 'emerald', 'error' => 'red', 'started' => 'amber'][$activity->status] ?? 'gray'; @endphp
                            <x-asset-manager-badge :color="$syncColor" size="xs" class="flex-shrink-0">{{ $activity->status }}</x-asset-manager-badge>
                        </div>
                        @if($activity->status === 'success')
                            <div class="text-[10px] text-[color:var(--ui-secondary)] mb-1 space-y-0.5">
                                <div>{{ $activity->devices_synced ?? 0 }} synchronisiert</div>
                                @if(($activity->devices_added ?? 0) > 0)   <div>+{{ $activity->devices_added }} neu</div> @endif
                                @if(($activity->devices_removed ?? 0) > 0) <div>−{{ $activity->devices_removed }} entfernt</div> @endif
                            </div>
                        @elseif($activity->status === 'error' && $activity->error_message)
                            <div class="text-[10px] text-red-700 mb-1 break-words">{{ Str::limit($activity->error_message, 120) }}</div>
                        @endif
                        <div class="flex items-center gap-1.5 text-[10px] text-[color:var(--ui-secondary)]">
                            @svg('heroicon-o-clock', 'w-3 h-3 opacity-60')
                            <span>{{ $activity->started_at->diffForHumans() }}</span>
                            @if($activity->duration_ms)
                                <span class="ml-auto">{{ number_format($activity->duration_ms / 1000, 1) }}s</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[color:var(--ui-secondary)]">
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
                                <p class="text-sm text-[color:var(--ui-secondary)]">{{ $device->user_display_name }}</p>
                            @endif
                        </div>
                        <x-asset-manager-badge :color="$device->complianceBadgeColor()" dot size="md" class="flex-shrink-0">{{ $device->complianceLabel() }}</x-asset-manager-badge>
                    </div>
                </div>

                {{-- Quick Stats --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Betriebssystem</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $device->operating_system ?? '—' }}</div>
                        <div class="text-xs text-[color:var(--ui-secondary)] mt-0.5">{{ $device->os_version ?? '' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Hardware</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">{{ $device->manufacturer ?? '—' }}</div>
                        <div class="text-xs text-[color:var(--ui-secondary)] mt-0.5 truncate">{{ $device->model ?? '' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Letztes Check-In</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $device->last_check_in_at?->diffForHumans() ?? '—' }}</div>
                        <div class="text-xs text-[color:var(--ui-secondary)] mt-0.5">{{ $device->last_check_in_at?->format('d.m.Y H:i') ?? '' }}</div>
                    </div>
                </div>

                {{-- Nutzer-Zuweisung --}}
                @if($device->user_display_name || $device->user_principal_name)
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                        <div class="px-4 py-3 border-b border-[color:var(--ui-muted)]">
                            <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Nutzer-Zuweisung</h2>
                        </div>
                        <div class="p-4 space-y-1">
                            <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $device->user_display_name ?? '—' }}</div>
                            @if($device->user_principal_name)
                                <div class="text-xs text-[color:var(--ui-secondary)]">{{ $device->user_principal_name }}</div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Zuweisung & Lifecycle --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Zuweisung & Lifecycle</h2>
                        @if($canManage && !$editingLifecycle)
                            <button wire:click="editLifecycle" class="text-xs text-[color:var(--ui-primary)] hover:underline">Bearbeiten</button>
                        @endif
                    </div>

                    @if($lifecycleFlash)
                        <div class="px-4 pt-3 text-[11px] text-emerald-700">{{ $lifecycleFlash }}</div>
                    @endif

                    @if(!$editingLifecycle)
                        <div class="p-4 space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-asset-manager-badge :color="$device->lifecycleBadgeColor()" dot size="sm">{{ $device->lifecycleLabel() }}</x-asset-manager-badge>
                                @if($device->isExpiringSoon())
                                    <x-asset-manager-badge color="amber" size="xs" icon="heroicon-o-exclamation-triangle">Läuft ab {{ $device->earliestExpiry()?->format('d.m.Y') }}</x-asset-manager-badge>
                                @endif
                            </div>

                            <dl class="text-[11px] divide-y divide-[color:var(--ui-muted)]">
                                <div class="flex justify-between py-1.5"><dt class="text-[color:var(--ui-secondary)]">Standort</dt><dd class="text-gray-600 dark:text-gray-300">{{ $device->location ?: '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-[color:var(--ui-secondary)]">Garantie bis</dt><dd class="text-gray-600 dark:text-gray-300 tabular-nums">{{ $device->warranty_until?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-[color:var(--ui-secondary)]">Leasing bis</dt><dd class="text-gray-600 dark:text-gray-300 tabular-nums">{{ $device->lease_until?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-[color:var(--ui-secondary)]">Lieferant</dt><dd class="text-gray-600 dark:text-gray-300">{{ optional($device->vendor)->name ?? '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-[color:var(--ui-secondary)]">Bestell-Nr.</dt><dd class="text-gray-600 dark:text-gray-300">{{ $device->order_no ?: '—' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-[color:var(--ui-secondary)]">Bestelldatum</dt><dd class="text-gray-600 dark:text-gray-300 tabular-nums">{{ $device->order_date?->format('d.m.Y') ?? '—' }}</dd></div>
                            </dl>
                        </div>
                    @else
                        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Status</label>
                                <select wire:model="lStatus" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– kein Status –</option>
                                    <option value="in_use">In Betrieb</option>
                                    <option value="spare">Reserve / Lager</option>
                                    <option value="repair">In Reparatur</option>
                                    <option value="defect">Defekt / Kaputt</option>
                                    <option value="retired">Ausgemustert</option>
                                    <option value="lost">Verloren / Gestohlen</option>
                                </select>
                                @error('lStatus')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Standort</label>
                                <input type="text" wire:model="lLocation" maxlength="255" placeholder="z. B. Büro Bonn, Lager" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Garantie bis</label>
                                <input type="date" wire:model="lWarranty" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                @error('lWarranty')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Leasing bis</label>
                                <input type="date" wire:model="lLease" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Lieferant</label>
                                <select wire:model="lVendor" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– kein Lieferant –</option>
                                    @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Bestell-Nr.</label>
                                <input type="text" wire:model="lOrderNo" maxlength="255" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Bestelldatum</label>
                                <input type="date" wire:model="lOrderDate" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div class="sm:col-span-2 flex items-center gap-2">
                                <x-ui-button variant="primary" size="sm" rounded="lg" wire:click="saveLifecycle">Speichern</x-ui-button>
                                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="cancelLifecycle">Abbrechen</x-ui-button>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Geräteausgaben (Übergabeprotokolle) --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Übergabeprotokolle</h2>
                        @can('asset-manager.manage')
                            <a href="{{ route('asset-manager.handovers.index', ['device' => $device->id, 'new' => 1]) }}"
                               class="text-xs text-[color:var(--ui-primary)] hover:underline">Ausgabe erfassen</a>
                        @endcan
                    </div>
                    <div class="p-4 space-y-3">
                        @if($hasOpenHandover)
                            <x-asset-manager-badge color="emerald" size="xs" icon="heroicon-o-check-circle">Aktuell ausgegeben</x-asset-manager-badge>
                        @elseif($device->user_principal_name)
                            <x-asset-manager-badge color="amber" size="xs" icon="heroicon-o-exclamation-triangle">Ohne offene Ausgabe</x-asset-manager-badge>
                        @endif

                        @if($handoverLines->isEmpty())
                            <p class="text-[11px] text-[color:var(--ui-secondary)]">Noch keine Ausgabe erfasst.</p>
                        @else
                            <ul class="text-[11px] divide-y divide-[color:var(--ui-muted)]">
                                @foreach($handoverLines as $hl)
                                    <li class="flex items-center justify-between gap-2 py-1.5">
                                        <div class="min-w-0 truncate">
                                            <span class="text-gray-700 dark:text-gray-300">{{ $hl->handover?->employee?->display_name ?: ($hl->handover?->employee?->user_principal_name ?? '—') }}</span>
                                            <span class="text-[color:var(--ui-secondary)]">· {{ $hl->handover?->issued_at?->format('d.m.Y') ?? '—' }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            @if($hl->returned_at)
                                                <x-asset-manager-badge color="gray" size="xs" :pill="false">zurück {{ $hl->returned_at->format('d.m.Y') }}</x-asset-manager-badge>
                                            @else
                                                <x-asset-manager-badge color="emerald" size="xs" :pill="false">ausgegeben</x-asset-manager-badge>
                                            @endif
                                            <a href="{{ route('asset-manager.handovers.pdf', $hl->handover_id) }}" target="_blank"
                                               class="text-[color:var(--ui-secondary)] hover:text-violet-600" title="Protokoll-PDF">@svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5 inline')</a>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Kosten --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Kosten</h2>
                        @if($canManage && !$editingCost)
                            <button wire:click="editCost" class="text-xs text-[color:var(--ui-primary)] hover:underline">Bearbeiten</button>
                        @endif
                    </div>

                    @if($flash)
                        <div class="px-4 pt-3 text-[11px] text-emerald-700">{{ $flash }}</div>
                    @endif

                    @if(!$editingCost)
                        <div class="p-4 space-y-2">
                            <div class="flex items-baseline gap-2">
                                <span class="text-lg font-semibold text-gray-800 dark:text-gray-100 tabular-nums">{{ $resolvedCost > 0 ? number_format($resolvedCost, 2, ',', '.') . ' €' : '—' }}</span>
                                <span class="text-xs text-[color:var(--ui-secondary)]">/ Monat</span>
                            </div>
                            <div class="text-[11px] text-[color:var(--ui-secondary)]">
                                @if($device->monthly_cost || ($device->purchase_price && $device->depreciation_months))
                                    Override am Gerät
                                @elseif($deviceModel && ($deviceModel->monthly_cost || ($deviceModel->purchase_price && $deviceModel->depreciation_months)))
                                    Modell-Default ({{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: 'Modell' }})
                                @else
                                    kein Preis hinterlegt — am Geräte-Modell oder hier pflegen
                                @endif
                            </div>
                            <dl class="text-[11px] divide-y divide-[color:var(--ui-muted)] pt-1">
                                <div class="flex justify-between py-1"><dt class="text-[color:var(--ui-secondary)]">Kostenart</dt><dd class="text-gray-600">{{ optional($costTypes->firstWhere('id', $resolvedCostTypeId))->name ?? '— (zählt nicht im Pivot)' }}</dd></div>
                                <div class="flex justify-between py-1"><dt class="text-[color:var(--ui-secondary)]">Kostenstelle</dt><dd class="text-gray-600">{{ $device->cost_center_id ? (optional($costCenters->firstWhere('id', $device->cost_center_id))->code ?? '—') : 'über Nutzer' }}</dd></div>
                            </dl>
                        </div>
                    @else
                        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Leasing / Monat (€)</label>
                                <input type="number" step="0.01" min="0" wire:model="oMonthly" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                @error('oMonthly')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Kaufpreis (€) / AfA-Monate</label>
                                <div class="flex gap-2">
                                    <input type="number" step="0.01" min="0" wire:model="oPurchase" placeholder="Preis" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <input type="number" step="1" min="1" wire:model="oDep" placeholder="Mon." class="w-24 px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                </div>
                                @error('oPurchase')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Kaufdatum (optional)</label>
                                <input type="date" wire:model="oPurchaseDate" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Kostenart</label>
                                <select wire:model="oCostType" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– vom Modell –</option>
                                    @foreach($costTypes as $ct)<option value="{{ $ct->id }}">{{ $ct->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Kostenstelle (Override)</label>
                                <select wire:model="oCostCenter" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="">– über Nutzer –</option>
                                    @foreach($costCenters as $cc)<option value="{{ $cc->id }}">{{ $cc->name ? $cc->code . ' — ' . $cc->name : $cc->code }}</option>@endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
                                <x-ui-button variant="primary" size="sm" rounded="lg" wire:click="saveCost">Speichern</x-ui-button>
                                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="cancelCost">Abbrechen</x-ui-button>
                                <span class="text-[11px] text-[color:var(--ui-secondary)]">Leasing-Rate <em>oder</em> Kaufpreis + AfA-Monate. Leer = Modell-Default.</span>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Notiz --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Notiz</h2>
                        @if($canManage && !$editingNotes)
                            <button wire:click="editNotes" class="text-xs text-[color:var(--ui-primary)] hover:underline">Bearbeiten</button>
                        @endif
                    </div>

                    @if($notesFlash)
                        <div class="px-4 pt-3 text-[11px] text-emerald-700">{{ $notesFlash }}</div>
                    @endif

                    @if(!$editingNotes)
                        <div class="p-4">
                            @if($device->notes)
                                <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $device->notes }}</p>
                            @else
                                <p class="text-[11px] text-[color:var(--ui-secondary)]">Keine Notiz hinterlegt.</p>
                            @endif
                        </div>
                    @else
                        <div class="p-4 space-y-2">
                            <textarea wire:model="oNotes" rows="4" maxlength="2000"
                                placeholder="z. B. Leihgerät, wartet auf Rückgabe, Display-Schaden gemeldet …"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--ui-border)] bg-white"></textarea>
                            @error('oNotes')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                            <div class="flex items-center gap-2">
                                <x-ui-button variant="primary" size="sm" rounded="lg" wire:click="saveNotes">Speichern</x-ui-button>
                                <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="cancelNotes">Abbrechen</x-ui-button>
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
                    class="w-full cursor-pointer p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--ui-muted-10)] transition-colors text-[11px] uppercase tracking-wider text-[color:var(--ui-secondary)]">
                    <span class="font-semibold">Rohdaten (Graph API)</span>
                    <span class="text-[10px]">{{ count((array) $device->raw_data) }} Felder</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up',   'w-3 h-3', ['x-show' => 'open',  'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--ui-border)] p-4 max-h-64 overflow-y-auto bg-white dark:bg-black/20">
                    <pre class="text-[10px] text-[color:var(--ui-secondary)] font-mono whitespace-pre-wrap break-all">{{ json_encode($device->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
