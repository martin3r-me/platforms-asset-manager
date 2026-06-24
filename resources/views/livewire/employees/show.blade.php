<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Mitarbeiter', 'href' => route('asset-manager.employees.index'), 'icon' => 'users'],
            ['label' => $employee->name],
        ]" />
    </x-slot>

    {{-- LINKS: Eigenschaften (editierbar) --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Profil" icon="heroicon-o-user" width="w-72" :defaultOpen="true">
            {{-- Profil bearbeiten nur Owner/Admin (E1/ADR 0004) — Backend: save() Gate. Member sehen
                 die Stammdaten read-only im Haupt-Content. --}}
            @can('asset-manager.manage')
            <form wire:submit="save" class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Identität</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Anzeigename</label>
                            <input type="text" wire:model="displayName" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">E-Mail</label>
                            <input type="email" wire:model="email" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        <div class="text-[10px] text-[var(--ui-muted)]">
                            <strong>UPN:</strong> <span class="font-mono">{{ $employee->user_principal_name }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Organisation</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Position</label>
                            <input type="text" wire:model="jobTitle" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Abteilung</label>
                            <input type="text" wire:model="department" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Kostenstelle</label>
                            <input type="text" wire:model="costCenter" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <div class="px-3 py-2">
                        <label class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)]">
                            <input type="checkbox" wire:model="isActive" class="rounded border-[var(--ui-border)]/40" />
                            Aktiv
                        </label>
                    </div>
                </section>

                <x-ui-button type="submit" variant="primary" size="md" rounded="lg" class="w-full">
                    @svg('heroicon-o-check', 'w-3.5 h-3.5')
                    Speichern
                </x-ui-button>
                @if($saved)
                    <div class="text-[10px] text-emerald-700 text-center">Gespeichert.</div>
                @endif
            </form>
            @endcan

            {{-- DSGVO-Einzel-Anonymisierung (E2 / ADR 0005) — nur Owner/Admin, mit Bestätigung. --}}
            @can('asset-manager.manage')
                <div class="px-4 pb-4 -mt-1">
                    <div class="pt-3 border-t border-[var(--ui-border)]/40">
                        <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] mb-1.5">DSGVO</div>
                        <button type="button"
                                wire:click="anonymize"
                                wire:confirm="Diese Person anonymisieren? Anzeigename, E-Mail und UPN werden pseudonymisiert und die verknüpften Geräte/Lizenzen maskiert. Das lässt sich nicht rückgängig machen."
                                class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium text-red-600 bg-red-500/10 rounded-lg hover:bg-red-500/20 transition-all">
                            @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                            Anonymisieren
                        </button>
                        @if($anonymized)
                            <div class="mt-1 text-[10px] text-emerald-700 text-center">Anonymisiert.</div>
                        @endif
                    </div>
                </div>
            @endcan
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Kosten-Übersicht --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Kosten" icon="heroicon-o-banknotes" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                <div class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] mb-1">Gesamt pro Monat</div>
                    <div class="text-2xl font-semibold text-[var(--ui-secondary)] tabular-nums">{{ number_format($totalCost, 2, ',', '.') }} €</div>
                </div>
                <div class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm space-y-1.5">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--ui-muted)]">Hardware (AfA)</span>
                        <span class="font-medium text-[var(--ui-secondary)] tabular-nums">{{ number_format($hardwareCost, 2, ',', '.') }} €</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--ui-muted)]">Geräte (Intune)</span>
                        <span class="font-medium text-[var(--ui-secondary)] tabular-nums">{{ number_format($deviceCost, 2, ',', '.') }} €</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--ui-muted)]">Lizenzen</span>
                        <span class="font-medium text-[var(--ui-secondary)] tabular-nums">{{ number_format($licenseCost, 2, ',', '.') }} €</span>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Header --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                <div class="flex items-start gap-4">
                    <div class="w-14 h-14 rounded-full bg-gradient-to-br from-violet-500/20 to-indigo-500/20 flex items-center justify-center text-violet-600 font-semibold text-lg flex-shrink-0">
                        {{ $employee->initials() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $employee->name }}</h1>
                        <p class="text-sm text-[color:var(--ui-secondary)]">{{ $employee->user_principal_name }}</p>
                        @if($employee->department || $employee->job_title)
                            <p class="text-xs text-[color:var(--ui-secondary)] mt-1">
                                {{ $employee->job_title }}@if($employee->department && $employee->job_title) · @endif{{ $employee->department }}
                            </p>
                        @endif
                    </div>
                    @if(!$employee->is_active)
                        <x-asset-manager-badge color="gray" size="sm" class="flex-shrink-0">Inaktiv</x-asset-manager-badge>
                    @endif
                </div>
            </div>

            {{-- Counts --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $devices->count() }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">Intune-Geräte</div>
                </div>
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $items->count() }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">Sonstige Assets</div>
                </div>
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $licenses->count() }}</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">Lizenzen</div>
                </div>
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-[color:var(--ui-primary)]">{{ number_format($totalCost, 2, ',', '.') }} €</div>
                    <div class="text-xs text-[color:var(--ui-secondary)]">pro Monat</div>
                </div>
            </div>

            {{-- Geräte aus Intune --}}
            @if($devices->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Intune-Geräte</h2>
                        <span class="text-xs text-[color:var(--ui-secondary)]">{{ $devices->count() }}</span>
                    </div>
                    <div class="divide-y divide-[color:var(--ui-muted)]">
                        @foreach($devices as $d)
                            <a href="{{ route('asset-manager.devices.show', $d) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-[color:var(--ui-muted-10)]">
                                @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-[color:var(--ui-secondary)] flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $d->device_name }}</div>
                                    <div class="text-xs text-[color:var(--ui-secondary)]">{{ $d->manufacturer }} {{ $d->model }} · {{ $d->operating_system }}</div>
                                </div>
                                @php $dCost = (float) ($deviceRows[$d->id]['amount'] ?? 0); @endphp
                                @if($dCost > 0)
                                    <span class="text-xs text-[color:var(--ui-secondary)] tabular-nums mr-1">{{ number_format($dCost, 2, ',', '.') }} €</span>
                                @endif
                                <x-asset-manager-badge :color="$d->complianceBadgeColor()" size="xs">{{ $d->complianceLabel() }}</x-asset-manager-badge>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Geräteausgaben (Übergabeprotokolle) — read-only; Anlegen von der Geräteseite/globalen Liste --}}
            @if($handovers->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Geräteausgaben</h2>
                        <span class="text-xs text-[color:var(--ui-secondary)]">{{ $handovers->count() }}</span>
                    </div>
                    <div class="divide-y divide-[color:var(--ui-muted)]">
                        @foreach($handovers as $ho)
                            <div class="flex items-center gap-3 px-5 py-3">
                                @svg('heroicon-o-clipboard-document-check', 'w-4 h-4 text-[color:var(--ui-secondary)] flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                        {{ $ho->lines->map(fn($l) => $l->deviceName())->take(3)->implode(', ') ?: '—' }}
                                    </div>
                                    <div class="text-xs text-[color:var(--ui-secondary)]">
                                        {{ $ho->issued_at?->format('d.m.Y') ?? '—' }} · {{ $ho->lines->count() }} Gerät(e) · {{ $ho->isSigned() ? 'unterschrieben' : 'nicht unterschrieben' }}
                                    </div>
                                </div>
                                @php $stColor = [
                                    'open'               => 'emerald',
                                    'partially_returned' => 'amber',
                                    'returned'           => 'gray',
                                ][$ho->status] ?? 'gray'; @endphp
                                <x-asset-manager-badge :color="$stColor" size="xs">{{ $ho->statusLabel() }}</x-asset-manager-badge>
                                <a href="{{ route('asset-manager.handovers.pdf', $ho->id) }}" target="_blank" class="text-[color:var(--ui-secondary)] hover:text-violet-600" title="Protokoll-PDF">@svg('heroicon-o-document-arrow-down', 'w-4 h-4')</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Sonstige Assets --}}
            @if($items->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Assets (Hardware)</h2>
                        <span class="text-xs text-[color:var(--ui-secondary)]">{{ $items->count() }}</span>
                    </div>
                    <div class="divide-y divide-[color:var(--ui-muted)]">
                        @foreach($items as $it)
                            <a href="{{ route('asset-manager.assets.show', $it) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-[color:var(--ui-muted-10)]">
                                @if($it->category?->icon) @svg($it->category->icon, 'w-4 h-4 text-[color:var(--ui-secondary)] flex-shrink-0') @else @svg('heroicon-o-cube', 'w-4 h-4 text-[color:var(--ui-secondary)] flex-shrink-0') @endif
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $it->name }}</div>
                                    <div class="text-xs text-[color:var(--ui-secondary)]">{{ $it->category?->name }} · {{ trim($it->manufacturer . ' ' . $it->model) }}</div>
                                </div>
                                @if($it->monthlyCost() > 0)
                                    <span class="text-xs text-[color:var(--ui-secondary)] tabular-nums">{{ number_format($it->monthlyCost(), 2, ',', '.') }} €</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Lizenzen --}}
            @if($licenses->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-[color:var(--ui-muted)] flex items-center justify-between">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[color:var(--ui-body-color)]">Lizenzen</h2>
                        <span class="text-xs text-[color:var(--ui-secondary)]">{{ $licenses->count() }}</span>
                    </div>
                    <div class="divide-y divide-[color:var(--ui-muted)]">
                        @foreach($licenses as $lic)
                            @php $sku = $skuMap[$lic->sku_id] ?? null; @endphp
                            <div class="flex items-center gap-3 px-5 py-3">
                                @svg('heroicon-o-key', 'w-4 h-4 text-[color:var(--ui-secondary)] flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                        @if($sku)
                                            <a href="{{ route('asset-manager.licenses.show', $sku) }}" wire:navigate class="hover:text-violet-600">
                                                {{ $sku->display_name ?? $sku->sku_part_number }}
                                            </a>
                                        @else
                                            {{ $lic->sku_part_number }}
                                        @endif
                                    </div>
                                </div>
                                @if($sku && $sku->unit_price !== null)
                                    <span class="text-xs text-[color:var(--ui-secondary)] tabular-nums">{{ number_format((float) $sku->unit_price, 2, ',', '.') }} €</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($devices->isEmpty() && $items->isEmpty() && $licenses->isEmpty())
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    @svg('heroicon-o-inbox', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
                    <p class="text-sm text-[color:var(--ui-secondary)]">Diesem Mitarbeiter sind noch keine Assets oder Lizenzen zugewiesen.</p>
                </div>
            @endif
        </div>
    </div>
</x-ui-page>
