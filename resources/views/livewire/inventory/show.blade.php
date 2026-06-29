<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Inventar', 'href' => route('asset-manager.inventory.index'), 'icon' => 'rectangle-group'],
            ['label' => $subject->name],
        ]">
            <x-slot name="actions">
                @if($canManage)
                    @if($subject->type === 'manual')
                        {{-- Phase 3: manuelle Assets werden direkt hier per Modal bearbeitet. --}}
                        <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="openAssign">
                            @svg('heroicon-o-user-plus', 'w-3.5 h-3.5')
                            Zuordnen
                        </x-ui-button>
                        <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="openEdit">
                            @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                            Bearbeiten
                        </x-ui-button>
                        <x-ui-button variant="danger-ghost" size="sm" rounded="lg" wire:click="openDelete">
                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                            Löschen
                        </x-ui-button>
                    @else
                        {{-- Phase 4: Geräte-Lifecycle/Beschaffung direkt hier per Modal. --}}
                        <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="openDeviceEdit">
                            @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                            Bearbeiten
                        </x-ui-button>
                    @endif
                @endif
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <x-ui-page-container padding="p-6" spacing="space-y-5">

        @if($flash)
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                <p class="text-sm text-emerald-700 dark:text-emerald-400">{{ $flash }}</p>
                <button wire:click="$set('flash', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
            </div>
        @endif

        {{-- Header --}}
        <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 flex items-center justify-center">
                    @svg($subject->icon, 'w-6 h-6 text-[color:var(--ui-primary)]')
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $subject->name }}</h1>
                        <x-asset-manager-badge :color="$subject->typeColor" size="xs"
                            :icon="$subject->type === 'intune' ? 'heroicon-o-cloud' : 'heroicon-o-wrench-screwdriver'">{{ $subject->typeLabel }}</x-asset-manager-badge>
                    </div>
                    @if($subject->manufacturer || $subject->model)
                        <p class="text-sm text-[color:var(--ui-secondary)]">{{ trim($subject->manufacturer . ' ' . $subject->model) }}</p>
                    @endif
                    @if($subject->assignedToLabel)
                        <p class="text-xs text-[color:var(--ui-secondary)] mt-0.5">zugeordnet an {{ $subject->assignedToLabel }}</p>
                    @endif
                </div>
                @if($subject->statusLabel !== '—')
                    <span class="flex-shrink-0">
                        <x-asset-manager-badge :color="$subject->statusColor" dot size="md">{{ $subject->statusLabel }}</x-asset-manager-badge>
                    </span>
                @endif
            </div>
        </div>

        {{-- Tab-Leiste (Alpine @entangle — NICHT x-ui-tab, dessen Active-State ist fehlerhaft) --}}
        <div x-data="{ tab: $wire.entangle('tab') }" class="space-y-5">
            <div class="flex flex-wrap gap-1.5 border-b border-[color:var(--ui-border)] pb-2">
                @php
                    $tabs = [
                        'overview'  => 'Übersicht',
                        'documents' => 'Dokumente',
                        'notes'     => 'Notizen',
                        'tickets'   => 'Tickets',
                        'invoices'  => 'Rechnungen',
                        'costs'     => 'Kosten',
                    ];
                    // „Verlauf" (Events + Sync-Logs) nur bei Geräten (Phase 6).
                    if ($device) { $tabs['verlauf'] = 'Verlauf'; }
                @endphp
                @foreach($tabs as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-[rgb(var(--ui-primary-rgb))] text-[color:var(--ui-on-primary)] shadow-sm' : 'text-[color:var(--ui-secondary)] hover:bg-[var(--ui-muted-10)]'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- ===================== ÜBERSICHT ===================== --}}
            <div x-show="tab === 'overview'" class="space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                    {{-- Details --}}
                    <x-ui-panel title="Details">
                        @if($canManage && $item)
                            <div class="flex justify-end -mt-1 mb-2">
                                <button type="button" wire:click="openEdit" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </div>
                        @endif
                        <dl class="text-sm divide-y divide-[color:var(--ui-muted)]">
                            @if($item)
                                @if($item->source === 'intune')
                                    <div class="pb-2 text-[11px] text-violet-700 dark:text-violet-400">
                                        @svg('heroicon-o-cloud', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') Intune-synced — Gerätedaten read-only.
                                    </div>
                                @endif
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Kategorie</dt><dd class="font-medium text-gray-800 dark:text-gray-200">{{ $item->category?->name ?? '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Seriennummer</dt><dd class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $item->serial_number ?: '—' }}</dd></div>
                            @else
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Seriennummer</dt><dd class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $device->serial_number ?: '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Betriebssystem</dt><dd class="font-medium text-gray-800 dark:text-gray-200">{{ trim(($device->operating_system ?? '') . ' ' . ($device->os_version ?? '')) ?: '—' }}</dd></div>
                                <div class="flex justify-between py-2 items-center"><dt class="text-[color:var(--ui-secondary)]">Compliance</dt><dd><x-asset-manager-badge :color="$device->complianceBadgeColor()" size="xs">{{ $device->complianceLabel() }}</x-asset-manager-badge></dd></div>
                                <div class="flex justify-between py-2 items-center"><dt class="text-[color:var(--ui-secondary)]">Verschlüsselung</dt><dd><x-asset-manager-badge :color="$device->encryptionBadgeColor()" size="xs">{{ $device->encryptionLabel() }}</x-asset-manager-badge></dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Speicher</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->storageSummary() }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Arbeitsspeicher</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->memoryLabel() }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Letzter Check-in</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->last_check_in_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Geräte-Typ</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->device_type ?: '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Management</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->management_state ?: '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Enrollment</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->enrollment_type ?: '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Enrollt am</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->enrolled_at?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Nutzer (UPN)</dt><dd class="text-gray-800 dark:text-gray-200 truncate max-w-[55%] text-right">{{ $device->user_principal_name ?: '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Intune-ID</dt><dd class="font-mono text-[10px] text-[color:var(--ui-secondary)] truncate max-w-[55%] text-right">{{ $device->intune_id ?: '—' }}</dd></div>
                            @endif
                        </dl>
                    </x-ui-panel>

                    {{-- Abschreibung --}}
                    <x-ui-panel title="Abschreibung">
                        @if($canManage && $item)
                            <div class="flex justify-end -mt-1 mb-2">
                                <button type="button" wire:click="openDepreciation" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </div>
                        @endif
                        @if($canManage && $device)
                            <div class="flex justify-end -mt-1 mb-2">
                                <button type="button" wire:click="openDeviceCost" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </div>
                        @endif
                        <dl class="text-sm divide-y divide-[color:var(--ui-muted)]">
                            @if($item)
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Kaufdatum</dt><dd class="text-gray-800 dark:text-gray-200">{{ $item->purchase_date?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Kaufpreis</dt><dd class="text-gray-800 dark:text-gray-200">{{ $item->purchase_price ? number_format((float) $item->purchase_price, 2, ',', '.') . ' €' : '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">AfA (Monate)</dt><dd class="text-gray-800 dark:text-gray-200">{{ $item->depreciation_months ?: '—' }}</dd></div>
                            @else
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Kaufdatum</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->purchase_date?->format('d.m.Y') ?? '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Kaufpreis</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->purchase_price ? number_format((float) $device->purchase_price, 2, ',', '.') . ' €' : '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Leasing / Monat</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->monthly_cost && (float) $device->monthly_cost > 0 ? number_format((float) $device->monthly_cost, 2, ',', '.') . ' €' : '—' }}</dd></div>
                                <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">AfA (Monate)</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->depreciation_months ?: '—' }}</dd></div>
                            @endif
                            <div class="flex justify-between py-2 pt-2"><dt class="font-medium text-gray-700 dark:text-gray-300">Monatlich</dt><dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $subject->monthlyCost > 0 ? number_format($subject->monthlyCost, 2, ',', '.') . ' €' : '—' }}</dd></div>
                        </dl>
                    </x-ui-panel>
                </div>

                {{-- Lifecycle & Beschaffung (nur Gerät) — manuell gepflegt, Intune liefert das nicht (ADR 0007). --}}
                @if($device)
                    <x-ui-panel :title="'Lifecycle & Beschaffung'">
                        @if($canManage)
                            <div class="flex justify-end -mt-1 mb-2">
                                <button type="button" wire:click="openDeviceEdit" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </div>
                        @endif
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 text-sm">
                            <div class="flex justify-between py-2 border-b border-[color:var(--ui-muted)]"><dt class="text-[color:var(--ui-secondary)]">Lifecycle-Status</dt><dd>@if($device->lifecycle_status)<x-asset-manager-badge :color="$subject->statusColor" size="xs">{{ $device->lifecycleLabel() }}</x-asset-manager-badge>@else <span class="text-[color:var(--ui-muted)]">—</span>@endif</dd></div>
                            <div class="flex justify-between py-2 border-b border-[color:var(--ui-muted)]"><dt class="text-[color:var(--ui-secondary)]">Standort</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->location ?: '—' }}</dd></div>
                            <div class="flex justify-between py-2 border-b border-[color:var(--ui-muted)]"><dt class="text-[color:var(--ui-secondary)]">Garantie bis</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->warranty_until?->format('d.m.Y') ?? '—' }}</dd></div>
                            <div class="flex justify-between py-2 border-b border-[color:var(--ui-muted)]"><dt class="text-[color:var(--ui-secondary)]">Leasing bis</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->lease_until?->format('d.m.Y') ?? '—' }}</dd></div>
                            <div class="flex justify-between py-2 border-b border-[color:var(--ui-muted)]"><dt class="text-[color:var(--ui-secondary)]">Kreditor</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->vendor?->name ?? '—' }}</dd></div>
                            <div class="flex justify-between py-2 border-b border-[color:var(--ui-muted)]"><dt class="text-[color:var(--ui-secondary)]">Bestellnr.</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->order_no ?: '—' }}</dd></div>
                            <div class="flex justify-between py-2"><dt class="text-[color:var(--ui-secondary)]">Bestelldatum</dt><dd class="text-gray-800 dark:text-gray-200">{{ $device->order_date?->format('d.m.Y') ?? '—' }}</dd></div>
                        </dl>
                    </x-ui-panel>
                @endif

                {{-- Zuordnungen (E6: Zeitraum-Verknüpfung; getrennt von Geräteausgaben) --}}
                <x-ui-panel title="Zuordnungen">
                    @if($canManage && $item)
                        <div class="flex justify-end -mt-1 mb-2">
                            <button type="button" wire:click="openAssign" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-user-plus', 'w-3 h-3') Zuordnen</button>
                        </div>
                    @endif
                    @if($assignments->isEmpty())
                        <p class="text-sm text-[color:var(--ui-secondary)] py-2">Noch keine Zuordnung erfasst.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] border-b border-[color:var(--ui-muted)]">
                                    <th class="py-2 pr-3">Mitarbeiter</th>
                                    <th class="py-2 pr-3">Zeitraum</th>
                                    <th class="py-2 pr-3">Quelle</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--ui-muted)]">
                                @foreach($assignments as $a)
                                    <tr>
                                        <td class="py-2 pr-3">
                                            @if($a->employee)
                                                <a href="{{ route('asset-manager.employees.show', $a->employee) }}" wire:navigate class="font-medium text-gray-800 dark:text-gray-200 hover:text-[color:var(--ui-primary)]">{{ $a->employee->name }}</a>
                                            @else
                                                <span class="text-[color:var(--ui-secondary)]">— gelöscht —</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 text-[color:var(--ui-secondary)] text-xs">
                                            {{ $a->assigned_at?->format('d.m.Y') ?? '—' }}@if($a->returned_at) – {{ $a->returned_at->format('d.m.Y') }}@endif
                                        </td>
                                        <td class="py-2 pr-3">
                                            @if($a->source === \Platform\AssetManager\Models\AssetAssignment::SOURCE_INTUNE)
                                                <x-asset-manager-badge color="violet" size="xs" icon="heroicon-o-cloud">Intune</x-asset-manager-badge>
                                            @else
                                                <x-asset-manager-badge color="gray" size="xs">Manuell</x-asset-manager-badge>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            @if($a->isOpen())
                                                <x-asset-manager-badge color="emerald" size="xs">aktuell</x-asset-manager-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-ui-panel>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    {{-- Notizen (Vorschau) --}}
                    <x-ui-panel title="Notizen">
                        @php $notes = $item ? $item->notes : $device->notes; @endphp
                        @if($notes)
                            <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $notes }}</p>
                        @else
                            <p class="text-sm text-[color:var(--ui-secondary)]">Keine Notizen.</p>
                        @endif
                    </x-ui-panel>

                    {{-- Verknüpfungen (reine Querlinks) --}}
                    <x-ui-panel title="Verknüpfungen">
                        <ul class="text-sm space-y-2">
                            @if($item && $item->assignee)
                                <li><a href="{{ route('asset-manager.employees.show', $item->assignee) }}" wire:navigate class="text-[color:var(--ui-primary)] hover:underline">→ Mitarbeiter-Profil ({{ $item->assignee->name }})</a></li>
                            @elseif($device && $device->assignee)
                                <li><a href="{{ route('asset-manager.employees.show', $device->assignee) }}" wire:navigate class="text-[color:var(--ui-primary)] hover:underline">→ Mitarbeiter-Profil ({{ $device->assignee->name }})</a></li>
                            @endif
                            @if($device && $device->deviceModel())
                                <li class="text-[color:var(--ui-secondary)]">Geräte-Modell: <span class="text-gray-700 dark:text-gray-300">{{ trim($device->deviceModel()->manufacturer . ' ' . $device->deviceModel()->model) }}</span></li>
                            @endif
                            @if(($item && !$item->assignee) && !$device)
                                <li class="text-[color:var(--ui-secondary)]">Keine Verknüpfungen.</li>
                            @endif
                        </ul>
                    </x-ui-panel>
                </div>

                {{-- Geräteausgaben (nur Gerät, E6) --}}
                @if($device)
                    <x-ui-panel title="Geräteausgaben">
                        <div class="flex items-center justify-between -mt-1 mb-2 gap-2">
                            <div>
                                @if($hasOpenHandover)
                                    <x-asset-manager-badge color="emerald" size="xs" icon="heroicon-o-check-circle">Aktuell ausgegeben</x-asset-manager-badge>
                                @elseif($device->user_principal_name)
                                    <x-asset-manager-badge color="amber" size="xs" icon="heroicon-o-exclamation-triangle">Ohne offene Ausgabe</x-asset-manager-badge>
                                @endif
                            </div>
                            @can('asset-manager.manage')
                                <a href="{{ route('asset-manager.handovers.index', ['device' => $device->id, 'new' => 1]) }}"
                                   class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-clipboard-document-check', 'w-3 h-3') Ausgabe erfassen</a>
                            @endcan
                        </div>
                        @if($handoverLines->isEmpty())
                            <p class="text-sm text-[color:var(--ui-secondary)] py-1">Keine Geräteausgaben für dieses Gerät.</p>
                        @else
                            <ul class="divide-y divide-[color:var(--ui-muted)] text-sm">
                                @foreach($handoverLines as $line)
                                    <li class="flex items-center justify-between py-2">
                                        <div>
                                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $line->handover?->employee?->name ?? '—' }}</span>
                                            <span class="ml-2 text-xs text-[color:var(--ui-secondary)]">
                                                @if($line->returned_at) zurückgegeben am {{ $line->returned_at->format('d.m.Y') }} @else offen @endif
                                            </span>
                                        </div>
                                        @if($line->handover)
                                            <a href="{{ route('asset-manager.handovers.pdf', $line->handover) }}" target="_blank" class="text-xs text-[color:var(--ui-primary)] hover:underline">
                                                @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5 inline -mt-0.5') PDF
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui-panel>
                @endif
            </div>

            {{-- ===================== NOTIZEN ===================== --}}
            <div x-show="tab === 'notes'" x-cloak>
                <x-ui-panel title="Notizen">
                    @if($canManage && $item)
                        <div class="flex justify-end -mt-1 mb-2">
                            <button type="button" wire:click="openNotes" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                        </div>
                    @endif
                    @if($canManage && $device)
                        <div class="flex justify-end -mt-1 mb-2">
                            <button type="button" wire:click="openDeviceNotes" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                        </div>
                    @endif
                    @php $notes = $item ? $item->notes : $device->notes; @endphp
                    @if($notes)
                        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $notes }}</p>
                    @else
                        <p class="text-sm text-[color:var(--ui-secondary)]">Keine Notizen hinterlegt.</p>
                    @endif
                </x-ui-panel>
            </div>

            {{-- ===================== KOSTEN ===================== --}}
            <div x-show="tab === 'costs'" x-cloak>
                <x-ui-panel title="Kosten (je Objekt)">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="rounded-lg bg-[var(--ui-muted-5)] p-4">
                            <div class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Monatlich (AfA/Leasing)</div>
                            <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $subject->monthlyCost > 0 ? number_format($subject->monthlyCost, 2, ',', '.') . ' €' : '—' }}</div>
                        </div>
                        <div class="rounded-lg bg-[var(--ui-muted-5)] p-4">
                            <div class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufpreis</div>
                            <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ ($item ? $item->purchase_price : $device->purchase_price) ? number_format((float) ($item ? $item->purchase_price : $device->purchase_price), 2, ',', '.') . ' €' : '—' }}</div>
                        </div>
                        <div class="rounded-lg bg-[var(--ui-muted-5)] p-4">
                            <div class="text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">AfA-Dauer</div>
                            <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ ($item ? $item->depreciation_months : $device->depreciation_months) ?: '—' }} {{ ($item ? $item->depreciation_months : $device->depreciation_months) ? 'Monate' : '' }}</div>
                        </div>
                    </div>
                    <p class="text-[11px] text-[color:var(--ui-secondary)] mt-3">Rohe AfA/Leasing-Kosten je Objekt — nicht die kostenstellen-zugeteilte Summe der Kostenaufteilung.</p>
                </x-ui-panel>
            </div>

            {{-- ===================== VERLAUF (nur Gerät, Phase 6) ===================== --}}
            @if($device)
                <div x-show="tab === 'verlauf'" x-cloak class="space-y-5">
                    {{-- Events --}}
                    <x-ui-panel title="Verlauf">
                        @forelse($events as $ev)
                            <div class="py-2 border-b border-[color:var(--ui-muted)] last:border-0">
                                <x-asset-manager-badge :color="$ev->eventColor()" dot size="xs">{{ $ev->eventLabel() }}</x-asset-manager-badge>
                                @if($ev->old_value !== null || $ev->new_value !== null)
                                    <div class="text-[11px] text-[color:var(--ui-secondary)] mt-1">{{ $ev->old_value ?: '—' }} → {{ $ev->new_value ?: '—' }}</div>
                                @endif
                                <div class="text-[10px] text-[color:var(--ui-secondary)] mt-0.5">{{ $ev->created_at?->diffForHumans() }}@if($ev->actor) · {{ $ev->actor->name }}@endif</div>
                            </div>
                        @empty
                            <p class="text-sm text-[color:var(--ui-secondary)]">Noch keine Änderungen erfasst.</p>
                        @endforelse
                    </x-ui-panel>

                    {{-- Sync-Logs --}}
                    <x-ui-panel title="Letzte Synchronisierungen">
                        @forelse($syncLogs as $log)
                            @php $syncColor = ['success' => 'emerald', 'error' => 'red', 'started' => 'amber'][$log->status] ?? 'gray'; @endphp
                            <div class="py-2 border-b border-[color:var(--ui-muted)] last:border-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">
                                        @if($log->status === 'success') Sync erfolgreich
                                        @elseif($log->status === 'error') Sync fehlgeschlagen
                                        @else Sync gestartet @endif
                                    </span>
                                    <x-asset-manager-badge :color="$syncColor" size="xs">{{ $log->status }}</x-asset-manager-badge>
                                </div>
                                @if($log->status === 'success')
                                    <div class="text-[11px] text-[color:var(--ui-secondary)] mt-0.5">
                                        {{ $log->devices_synced ?? 0 }} synchronisiert
                                        @if(($log->devices_added ?? 0) > 0) · +{{ $log->devices_added }} neu @endif
                                        @if(($log->devices_removed ?? 0) > 0) · −{{ $log->devices_removed }} entfernt @endif
                                    </div>
                                @elseif($log->status === 'error' && $log->error_message)
                                    <div class="text-[11px] text-red-700 mt-0.5 break-words">{{ Str::limit($log->error_message, 140) }}</div>
                                @endif
                                <div class="text-[10px] text-[color:var(--ui-secondary)] mt-0.5">{{ $log->started_at?->diffForHumans() }}@if($log->duration_ms) · {{ number_format($log->duration_ms / 1000, 1) }}s @endif</div>
                            </div>
                        @empty
                            <p class="text-sm text-[color:var(--ui-secondary)]">Noch keine Synchronisierungen.</p>
                        @endforelse
                    </x-ui-panel>

                    {{-- Rohdaten (Graph-API) --}}
                    @if($device->raw_data)
                        <x-ui-panel title="Rohdaten (Graph-API)">
                            <div x-data="{ open: false }">
                                <button type="button" @click="open = !open" class="text-[11px] text-[color:var(--ui-primary)] hover:underline inline-flex items-center gap-1">
                                    <span x-text="open ? 'Ausblenden' : 'Anzeigen'"></span> ({{ count((array) $device->raw_data) }} Felder)
                                </button>
                                <pre x-show="open" x-cloak class="mt-2 text-[10px] text-[color:var(--ui-secondary)] font-mono whitespace-pre-wrap break-all max-h-72 overflow-y-auto">{{ json_encode($device->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </x-ui-panel>
                    @endif
                </div>
            @endif

            {{-- ===================== PLATZHALTER ===================== --}}
            <div x-show="tab === 'documents'" x-cloak>@include('asset-manager::livewire.inventory.partials.tab-placeholder', ['title' => 'Dokumente', 'icon' => 'heroicon-o-document-text'])</div>
            <div x-show="tab === 'tickets'" x-cloak>@include('asset-manager::livewire.inventory.partials.tab-placeholder', ['title' => 'Tickets', 'icon' => 'heroicon-o-ticket'])</div>
            <div x-show="tab === 'invoices'" x-cloak>@include('asset-manager::livewire.inventory.partials.tab-placeholder', ['title' => 'Rechnungen', 'icon' => 'heroicon-o-banknotes'])</div>
        </div>
    </x-ui-page-container>

    {{-- Modals innerhalb <x-ui-page>. Phase 3: nur manuelle Assets (Geräte-Modals folgen Phase 4). --}}
    @if($canManage && $item)
        @include('asset-manager::livewire.inventory.partials.modal-edit')
        @include('asset-manager::livewire.inventory.partials.modal-assign')
        @include('asset-manager::livewire.inventory.partials.modal-depreciation')
        @include('asset-manager::livewire.inventory.partials.modal-notes')
        @include('asset-manager::livewire.inventory.partials.modal-delete')
    @endif

    {{-- Phase 4: Geräte-Modals (nur intune). --}}
    @if($canManage && $device)
        @include('asset-manager::livewire.inventory.partials.modal-device-edit')
        @include('asset-manager::livewire.inventory.partials.modal-device-cost')
        @include('asset-manager::livewire.inventory.partials.modal-device-notes')
    @endif
</x-ui-page>
