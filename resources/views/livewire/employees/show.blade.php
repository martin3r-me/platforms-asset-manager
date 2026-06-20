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

                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                    @svg('heroicon-o-check', 'w-3.5 h-3.5')
                    Speichern
                </button>
                @if($saved)
                    <div class="text-[10px] text-emerald-600 text-center">Gespeichert.</div>
                @endif
            </form>

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
                            <div class="mt-1 text-[10px] text-emerald-600 text-center">Anonymisiert.</div>
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
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $employee->user_principal_name }}</p>
                        @if($employee->department || $employee->job_title)
                            <p class="text-xs text-gray-400 mt-1">
                                {{ $employee->job_title }}@if($employee->department && $employee->job_title) · @endif{{ $employee->department }}
                            </p>
                        @endif
                    </div>
                    @if(!$employee->is_active)
                        <span class="px-2 py-1 rounded-full bg-gray-500/10 text-gray-500 text-xs flex-shrink-0">Inaktiv</span>
                    @endif
                </div>
            </div>

            {{-- Counts --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $devices->count() }}</div>
                    <div class="text-xs text-gray-400">Intune-Geräte</div>
                </div>
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $items->count() }}</div>
                    <div class="text-xs text-gray-400">Sonstige Assets</div>
                </div>
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $licenses->count() }}</div>
                    <div class="text-xs text-gray-400">Lizenzen</div>
                </div>
                <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="text-2xl font-semibold text-violet-600 dark:text-violet-400">{{ number_format($totalCost, 2, ',', '.') }} €</div>
                    <div class="text-xs text-gray-400">pro Monat</div>
                </div>
            </div>

            {{-- Geräte aus Intune --}}
            @if($devices->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Intune-Geräte</h2>
                        <span class="text-xs text-gray-400">{{ $devices->count() }}</span>
                    </div>
                    <div class="divide-y divide-black/[0.03]">
                        @foreach($devices as $d)
                            <a href="{{ route('asset-manager.devices.show', $d) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-black/[0.02]">
                                @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-gray-400 flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $d->device_name }}</div>
                                    <div class="text-xs text-gray-400">{{ $d->manufacturer }} {{ $d->model }} · {{ $d->operating_system }}</div>
                                </div>
                                @php $dCost = (float) ($deviceRows[$d->id]['amount'] ?? 0); @endphp
                                @if($dCost > 0)
                                    <span class="text-xs text-gray-500 tabular-nums mr-1">{{ number_format($dCost, 2, ',', '.') }} €</span>
                                @endif
                                @php $c = $d->complianceBadgeColor() @endphp
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-{{ $c }}-500/10 text-{{ $c }}-600">{{ $d->complianceLabel() }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Sonstige Assets --}}
            @if($items->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Assets (Hardware)</h2>
                        <span class="text-xs text-gray-400">{{ $items->count() }}</span>
                    </div>
                    <div class="divide-y divide-black/[0.03]">
                        @foreach($items as $it)
                            <a href="{{ route('asset-manager.assets.show', $it) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-black/[0.02]">
                                @if($it->category?->icon) @svg($it->category->icon, 'w-4 h-4 text-gray-400 flex-shrink-0') @else @svg('heroicon-o-cube', 'w-4 h-4 text-gray-400 flex-shrink-0') @endif
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $it->name }}</div>
                                    <div class="text-xs text-gray-400">{{ $it->category?->name }} · {{ trim($it->manufacturer . ' ' . $it->model) }}</div>
                                </div>
                                @if($it->monthlyCost() > 0)
                                    <span class="text-xs text-gray-500 tabular-nums">{{ number_format($it->monthlyCost(), 2, ',', '.') }} €</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Lizenzen --}}
            @if($licenses->count() > 0)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Lizenzen</h2>
                        <span class="text-xs text-gray-400">{{ $licenses->count() }}</span>
                    </div>
                    <div class="divide-y divide-black/[0.03]">
                        @foreach($licenses as $lic)
                            @php $sku = $skuMap[$lic->sku_id] ?? null; @endphp
                            <div class="flex items-center gap-3 px-5 py-3">
                                @svg('heroicon-o-key', 'w-4 h-4 text-gray-400 flex-shrink-0')
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
                                    <span class="text-xs text-gray-500 tabular-nums">{{ number_format((float) $sku->unit_price, 2, ',', '.') }} €</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($devices->isEmpty() && $items->isEmpty() && $licenses->isEmpty())
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    @svg('heroicon-o-inbox', 'w-10 h-10 text-gray-300 mb-3')
                    <p class="text-sm text-gray-400">Diesem Mitarbeiter sind noch keine Assets oder Lizenzen zugewiesen.</p>
                </div>
            @endif
        </div>
    </div>
</x-ui-page>
