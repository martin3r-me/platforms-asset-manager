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
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="openAssign">
                            @svg('heroicon-o-user-plus', 'w-3.5 h-3.5')
                            Zuordnen
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="openEdit">
                            @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                            Bearbeiten
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="danger" size="sm" wire:click="openDelete">
                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                            Löschen
                        </x-asset-manager-button>
                    @else
                        {{-- Phase 4: Geräte-Lifecycle/Beschaffung direkt hier per Modal. --}}
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="openDeviceEdit">
                            @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                            Bearbeiten
                        </x-asset-manager-button>
                    @endif
                @endif
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Steckbrief (typ-übergreifende Schlüsseldaten aus AssetSubject). --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Steckbrief" icon="heroicon-o-identification" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">

                <section class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-3 pt-3 pb-1.5">Klassifikation</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-[var(--am-text-secondary)]">Typ</span>
                            <x-asset-manager-badge :color="$subject->typeColor" size="xs"
                                :icon="$subject->type === 'intune' ? 'heroicon-o-cloud' : 'heroicon-o-wrench-screwdriver'">{{ $subject->typeLabel }}</x-asset-manager-badge>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-[var(--am-text-secondary)]">Status</span>
                            @if($subject->statusLabel === '—')
                                <span class="text-[11px] text-[var(--am-text-disabled)]">—</span>
                            @else
                                <x-asset-manager-badge :color="$subject->statusColor" dot size="xs">{{ $subject->statusLabel }}</x-asset-manager-badge>
                            @endif
                        </div>
                        @if($subject->manufacturer || $subject->model)
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] text-[var(--am-text-secondary)]">Modell</span>
                                <span class="text-[11px] text-[var(--am-text)] text-right">{{ trim($subject->manufacturer . ' ' . $subject->model) }}</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-[var(--am-text-secondary)]">Seriennr.</span>
                            <span class="text-[11px] font-mono text-[var(--am-text)] text-right break-all">{{ $subject->serialNumber ?: '—' }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-3 pt-3 pb-1.5">Zuordnung &amp; Kosten</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-[var(--am-text-secondary)]">Zugeordnet</span>
                            <span class="text-[11px] text-[var(--am-text)] text-right">{{ $subject->assignedToLabel ?: '—' }}</span>
                        </div>
                        @if($device && $device->location)
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] text-[var(--am-text-secondary)]">Standort</span>
                                <span class="text-[11px] text-[var(--am-text)] text-right">{{ $device->location }}</span>
                            </div>
                        @endif
                        @if($device && $device->costCenter)
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] text-[var(--am-text-secondary)]">Kostenstelle</span>
                                <span class="text-[11px] text-[var(--am-text)] text-right">{{ $device->costCenter->name }}</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-[var(--am-text-secondary)]">Monatskosten</span>
                            <span class="text-[11px] tabular-nums text-[var(--am-text)]">
                                {{ $subject->monthlyCost > 0 ? number_format($subject->monthlyCost, 2, ',', '.') . ' €' : '—' }}
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Aktionen (spiegelt die Actionbar als große Buttons) + Absprünge. --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktionen" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                @if($canManage)
                    <section class="space-y-2">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-1">Bearbeiten</h3>
                        @if($subject->type === 'manual')
                            <x-asset-manager-button variant="primary" size="md" class="w-full" wire:click="openAssign">
                                @svg('heroicon-o-user-plus', 'w-4 h-4')
                                Zuordnen
                            </x-asset-manager-button>
                            <x-asset-manager-button variant="secondary" size="md" class="w-full" wire:click="openEdit">
                                @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                Bearbeiten
                            </x-asset-manager-button>
                            <x-asset-manager-button variant="danger" size="md" class="w-full" wire:click="openDelete">
                                @svg('heroicon-o-trash', 'w-4 h-4')
                                Löschen
                            </x-asset-manager-button>
                        @else
                            <x-asset-manager-button variant="primary" size="md" class="w-full" wire:click="openDeviceEdit">
                                @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                Lifecycle / Beschaffung bearbeiten
                            </x-asset-manager-button>
                        @endif
                    </section>
                @endif

                <section class="space-y-2">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-1">Absprünge</h3>
                    @if($subject->type === 'intune')
                        <x-asset-manager-button variant="secondary" size="md" class="w-full"
                                     href="{{ route('asset-manager.handovers.index') }}" wire:navigate>
                            @svg('heroicon-o-clipboard-document-check', 'w-4 h-4')
                            Geräteausgaben
                        </x-asset-manager-button>
                    @endif
                    <x-asset-manager-button variant="secondary" size="md" class="w-full"
                                 href="{{ route('asset-manager.inventory.index') }}" wire:navigate>
                        @svg('heroicon-o-rectangle-group', 'w-4 h-4')
                        Zurück zum Inventar
                    </x-asset-manager-button>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

        @if($flash)
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                <p class="text-sm text-emerald-700">{{ $flash }}</p>
                <button wire:click="$set('flash', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
            </div>
        @endif

        {{-- Header --}}
        <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-[var(--am-bg)] flex items-center justify-center">
                    @svg($subject->icon, 'w-6 h-6 text-[var(--am-text-secondary)]')
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-lg font-semibold text-[var(--am-text)] truncate">{{ $subject->name }}</h1>
                        <x-asset-manager-badge :color="$subject->typeColor" size="xs"
                            :icon="$subject->type === 'intune' ? 'heroicon-o-cloud' : 'heroicon-o-wrench-screwdriver'">{{ $subject->typeLabel }}</x-asset-manager-badge>
                    </div>
                    @if($subject->manufacturer || $subject->model)
                        <p class="text-sm text-[var(--am-text-secondary)]">{{ trim($subject->manufacturer . ' ' . $subject->model) }}</p>
                    @endif
                    @if($subject->assignedToLabel)
                        <p class="text-xs text-[var(--am-text-muted)] mt-0.5">zugeordnet an {{ $subject->assignedToLabel }}</p>
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
            <x-asset-manager-tabs :tabs="$tabs" />

            {{-- ===================== ÜBERSICHT ===================== --}}
            <div x-show="tab === 'overview'" class="space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                    {{-- Details --}}
                    <x-asset-manager-panel title="Details">
                        @if($canManage && $item)
                            <x-slot name="actions">
                                <button type="button" wire:click="openEdit" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </x-slot>
                        @endif
                        <x-asset-manager-detail-list>
                            @if($item)
                                @if($item->source === 'intune')
                                    <div class="pb-2 text-[11px] text-[var(--am-accent)]">
                                        @svg('heroicon-o-cloud', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') Intune-synced — Gerätedaten read-only.
                                    </div>
                                @endif
                                <x-asset-manager-detail-row label="Kategorie">{{ $item->category?->name ?? '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Seriennummer" mono>{{ $item->serial_number ?: '—' }}</x-asset-manager-detail-row>
                            @else
                                <x-asset-manager-detail-row label="Seriennummer" mono>{{ $device->serial_number ?: '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Betriebssystem">{{ trim(($device->operating_system ?? '') . ' ' . ($device->os_version ?? '')) ?: '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Compliance"><x-asset-manager-badge :color="$device->complianceBadgeColor()" size="xs">{{ $device->complianceLabel() }}</x-asset-manager-badge></x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Verschlüsselung"><x-asset-manager-badge :color="$device->encryptionBadgeColor()" size="xs">{{ $device->encryptionLabel() }}</x-asset-manager-badge></x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Speicher">{{ $device->storageSummary() }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Arbeitsspeicher">{{ $device->memoryLabel() }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Letzter Check-in">{{ $device->last_check_in_at?->format('d.m.Y H:i') ?? '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Geräte-Typ">{{ $device->device_type ?: '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Management">{{ $device->management_state ?: '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Enrollment">{{ $device->enrollment_type ?: '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Enrollt am">{{ $device->enrolled_at?->format('d.m.Y') ?? '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Nutzer (UPN)"><span class="block truncate">{{ $device->user_principal_name ?: '—' }}</span></x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Intune-ID" mono><span class="block truncate text-[var(--am-text-muted)]">{{ $device->intune_id ?: '—' }}</span></x-asset-manager-detail-row>
                            @endif
                        </x-asset-manager-detail-list>
                    </x-asset-manager-panel>

                    {{-- Abschreibung --}}
                    <x-asset-manager-panel title="Abschreibung">
                        @if($canManage && $item)
                            <x-slot name="actions">
                                <button type="button" wire:click="openDepreciation" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </x-slot>
                        @elseif($canManage && $device)
                            <x-slot name="actions">
                                <button type="button" wire:click="openDeviceCost" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </x-slot>
                        @endif
                        <x-asset-manager-detail-list>
                            @if($item)
                                <x-asset-manager-detail-row label="Kaufdatum">{{ $item->purchase_date?->format('d.m.Y') ?? '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Kaufpreis">{{ $item->purchase_price ? number_format((float) $item->purchase_price, 2, ',', '.') . ' €' : '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="AfA (Monate)">{{ $item->depreciation_months ?: '—' }}</x-asset-manager-detail-row>
                            @else
                                <x-asset-manager-detail-row label="Kaufdatum">{{ $device->purchase_date?->format('d.m.Y') ?? '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Kaufpreis">{{ $device->purchase_price ? number_format((float) $device->purchase_price, 2, ',', '.') . ' €' : '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="Leasing / Monat">{{ $device->monthly_cost && (float) $device->monthly_cost > 0 ? number_format((float) $device->monthly_cost, 2, ',', '.') . ' €' : '—' }}</x-asset-manager-detail-row>
                                <x-asset-manager-detail-row label="AfA (Monate)">{{ $device->depreciation_months ?: '—' }}</x-asset-manager-detail-row>
                            @endif
                            <div class="flex items-center justify-between gap-3 py-2">
                                <dt class="font-medium text-[var(--am-text-secondary)]">Monatlich</dt>
                                <dd class="font-semibold text-[var(--am-text)]">{{ $subject->monthlyCost > 0 ? number_format($subject->monthlyCost, 2, ',', '.') . ' €' : '—' }}</dd>
                            </div>
                        </x-asset-manager-detail-list>
                    </x-asset-manager-panel>
                </div>

                {{-- Lifecycle & Beschaffung (nur Gerät) — manuell gepflegt, Intune liefert das nicht (ADR 0007). --}}
                @if($device)
                    <x-asset-manager-panel title="Lifecycle & Beschaffung">
                        @if($canManage)
                            <x-slot name="actions">
                                <button type="button" wire:click="openDeviceEdit" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            </x-slot>
                        @endif
                        <x-asset-manager-detail-list :cols="2">
                            <x-asset-manager-detail-row label="Lifecycle-Status" bordered>@if($device->lifecycle_status)<x-asset-manager-badge :color="$subject->statusColor" size="xs">{{ $device->lifecycleLabel() }}</x-asset-manager-badge>@else <span class="text-[var(--am-text-disabled)]">—</span>@endif</x-asset-manager-detail-row>
                            <x-asset-manager-detail-row label="Standort" bordered>{{ $device->location ?: '—' }}</x-asset-manager-detail-row>
                            <x-asset-manager-detail-row label="Garantie bis" bordered>{{ $device->warranty_until?->format('d.m.Y') ?? '—' }}</x-asset-manager-detail-row>
                            <x-asset-manager-detail-row label="Leasing bis" bordered>{{ $device->lease_until?->format('d.m.Y') ?? '—' }}</x-asset-manager-detail-row>
                            <x-asset-manager-detail-row label="Kreditor" bordered>{{ $device->vendor?->name ?? '—' }}</x-asset-manager-detail-row>
                            <x-asset-manager-detail-row label="Bestellnr." bordered>{{ $device->order_no ?: '—' }}</x-asset-manager-detail-row>
                            <x-asset-manager-detail-row label="Bestelldatum">{{ $device->order_date?->format('d.m.Y') ?? '—' }}</x-asset-manager-detail-row>
                        </x-asset-manager-detail-list>
                    </x-asset-manager-panel>
                @endif

                {{-- Zuordnungen (E6: Zeitraum-Verknüpfung; getrennt von Geräteausgaben) --}}
                <x-asset-manager-panel title="Zuordnungen">
                    @if($canManage && $item)
                        <x-slot name="actions">
                            <button type="button" wire:click="openAssign" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-user-plus', 'w-3 h-3') Zuordnen</button>
                        </x-slot>
                    @endif
                    @if($assignments->isEmpty())
                        <p class="text-sm text-[var(--am-text-secondary)] py-1">Noch keine Zuordnung erfasst.</p>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                                    <th class="py-2 pr-3">Mitarbeiter</th>
                                    <th class="py-2 pr-3">Zeitraum</th>
                                    <th class="py-2 pr-3">Quelle</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($assignments as $a)
                                    <tr>
                                        <td class="py-2 pr-3">
                                            @if($a->employee)
                                                <a href="{{ route('asset-manager.employees.show', $a->employee) }}" wire:navigate class="font-medium text-[var(--am-text)] hover:text-[var(--am-accent)]">{{ $a->employee->name }}</a>
                                            @else
                                                <span class="text-[var(--am-text-muted)]">— gelöscht —</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 text-[var(--am-text-muted)] text-xs">
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
                        </div>
                    @endif
                </x-asset-manager-panel>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    {{-- Notizen (Vorschau) --}}
                    <x-asset-manager-panel title="Notizen">
                        @php $notes = $item ? $item->notes : $device->notes; @endphp
                        @if($notes)
                            <p class="text-sm text-[var(--am-text)] whitespace-pre-wrap">{{ $notes }}</p>
                        @else
                            <p class="text-sm text-[var(--am-text-secondary)]">Keine Notizen.</p>
                        @endif
                    </x-asset-manager-panel>

                    {{-- Verknüpfungen (reine Querlinks) --}}
                    <x-asset-manager-panel title="Verknüpfungen">
                        <ul class="text-sm space-y-2">
                            @if($item && $item->assignee)
                                <li><a href="{{ route('asset-manager.employees.show', $item->assignee) }}" wire:navigate class="text-[var(--am-accent)] hover:underline">→ Mitarbeiter-Profil ({{ $item->assignee->name }})</a></li>
                            @elseif($device && $device->assignee)
                                <li><a href="{{ route('asset-manager.employees.show', $device->assignee) }}" wire:navigate class="text-[var(--am-accent)] hover:underline">→ Mitarbeiter-Profil ({{ $device->assignee->name }})</a></li>
                            @endif
                            @if($device && $device->deviceModel())
                                <li class="text-[var(--am-text-secondary)]">Geräte-Modell: <span class="text-[var(--am-text)]">{{ trim($device->deviceModel()->manufacturer . ' ' . $device->deviceModel()->model) }}</span></li>
                            @endif
                            @if(($item && !$item->assignee) && !$device)
                                <li class="text-[var(--am-text-secondary)]">Keine Verknüpfungen.</li>
                            @endif
                        </ul>
                    </x-asset-manager-panel>
                </div>

                {{-- Geräteausgaben (nur Gerät, E6) --}}
                @if($device)
                    <x-asset-manager-panel title="Geräteausgaben">
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
                                   class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-clipboard-document-check', 'w-3 h-3') Ausgabe erfassen</a>
                            @endcan
                        </div>
                        @if($handoverLines->isEmpty())
                            <p class="text-sm text-[var(--am-text-secondary)] py-1">Keine Geräteausgaben für dieses Gerät.</p>
                        @else
                            <ul class="divide-y divide-[color:var(--am-border)] text-sm">
                                @foreach($handoverLines as $line)
                                    <li class="flex items-center justify-between py-2">
                                        <div>
                                            <span class="font-medium text-[var(--am-text)]">{{ $line->handover?->employee?->name ?? '—' }}</span>
                                            <span class="ml-2 text-xs text-[var(--am-text-muted)]">
                                                @if($line->returned_at) zurückgegeben am {{ $line->returned_at->format('d.m.Y') }} @else offen @endif
                                            </span>
                                        </div>
                                        @if($line->handover)
                                            <a href="{{ route('asset-manager.handovers.pdf', $line->handover) }}" target="_blank" class="text-xs text-[var(--am-accent)] hover:underline">
                                                @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5 inline -mt-0.5') PDF
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-asset-manager-panel>
                @endif
            </div>

            {{-- ===================== NOTIZEN ===================== --}}
            <div x-show="tab === 'notes'" x-cloak>
                <x-asset-manager-panel title="Notizen">
                    @if($canManage && $item)
                        <x-slot name="actions">
                            <button type="button" wire:click="openNotes" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                        </x-slot>
                    @elseif($canManage && $device)
                        <x-slot name="actions">
                            <button type="button" wire:click="openDeviceNotes" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                        </x-slot>
                    @endif
                    @php $notes = $item ? $item->notes : $device->notes; @endphp
                    @if($notes)
                        <p class="text-sm text-[var(--am-text)] whitespace-pre-wrap">{{ $notes }}</p>
                    @else
                        <p class="text-sm text-[var(--am-text-secondary)]">Keine Notizen hinterlegt.</p>
                    @endif
                </x-asset-manager-panel>
            </div>

            {{-- ===================== KOSTEN ===================== --}}
            <div x-show="tab === 'costs'" x-cloak>
                <x-asset-manager-panel title="Kosten (je Objekt)">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-4">
                            <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Monatlich (AfA/Leasing)</div>
                            <div class="text-lg font-semibold text-[var(--am-text)]">{{ $subject->monthlyCost > 0 ? number_format($subject->monthlyCost, 2, ',', '.') . ' €' : '—' }}</div>
                        </div>
                        <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-4">
                            <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufpreis</div>
                            <div class="text-lg font-semibold text-[var(--am-text)]">{{ ($item ? $item->purchase_price : $device->purchase_price) ? number_format((float) ($item ? $item->purchase_price : $device->purchase_price), 2, ',', '.') . ' €' : '—' }}</div>
                        </div>
                        <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-4">
                            <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">AfA-Dauer</div>
                            <div class="text-lg font-semibold text-[var(--am-text)]">{{ ($item ? $item->depreciation_months : $device->depreciation_months) ?: '—' }} {{ ($item ? $item->depreciation_months : $device->depreciation_months) ? 'Monate' : '' }}</div>
                        </div>
                    </div>
                    <p class="text-[11px] text-[var(--am-text-muted)] mt-3">Rohe AfA/Leasing-Kosten je Objekt — nicht die kostenstellen-zugeteilte Summe der Kostenaufteilung.</p>
                </x-asset-manager-panel>
            </div>

            {{-- ===================== VERLAUF (nur Gerät, Phase 6) ===================== --}}
            @if($device)
                <div x-show="tab === 'verlauf'" x-cloak class="space-y-5">
                    {{-- Events --}}
                    <x-asset-manager-panel title="Verlauf">
                        @forelse($events as $ev)
                            <div class="py-2 border-b border-[color:var(--am-border)] last:border-0">
                                <x-asset-manager-badge :color="$ev->eventColor()" dot size="xs">{{ $ev->eventLabel() }}</x-asset-manager-badge>
                                @if($ev->old_value !== null || $ev->new_value !== null)
                                    <div class="text-[11px] text-[var(--am-text-secondary)] mt-1">{{ $ev->old_value ?: '—' }} → {{ $ev->new_value ?: '—' }}</div>
                                @endif
                                <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5">{{ $ev->created_at?->diffForHumans() }}@if($ev->actor) · {{ $ev->actor->name }}@endif</div>
                            </div>
                        @empty
                            <p class="text-sm text-[var(--am-text-secondary)]">Noch keine Änderungen erfasst.</p>
                        @endforelse
                    </x-asset-manager-panel>

                    {{-- Sync-Logs --}}
                    <x-asset-manager-panel title="Letzte Synchronisierungen">
                        @forelse($syncLogs as $log)
                            @php $syncColor = ['success' => 'emerald', 'error' => 'red', 'started' => 'amber'][$log->status] ?? 'gray'; @endphp
                            <div class="py-2 border-b border-[color:var(--am-border)] last:border-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm text-[var(--am-text)]">
                                        @if($log->status === 'success') Sync erfolgreich
                                        @elseif($log->status === 'error') Sync fehlgeschlagen
                                        @else Sync gestartet @endif
                                    </span>
                                    <x-asset-manager-badge :color="$syncColor" size="xs">{{ $log->status }}</x-asset-manager-badge>
                                </div>
                                @if($log->status === 'success')
                                    <div class="text-[11px] text-[var(--am-text-secondary)] mt-0.5">
                                        {{ $log->devices_synced ?? 0 }} synchronisiert
                                        @if(($log->devices_added ?? 0) > 0) · +{{ $log->devices_added }} neu @endif
                                        @if(($log->devices_removed ?? 0) > 0) · −{{ $log->devices_removed }} entfernt @endif
                                    </div>
                                @elseif($log->status === 'error' && $log->error_message)
                                    <div class="text-[11px] text-red-700 mt-0.5 break-words">{{ Str::limit($log->error_message, 140) }}</div>
                                @endif
                                <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5">{{ $log->started_at?->diffForHumans() }}@if($log->duration_ms) · {{ number_format($log->duration_ms / 1000, 1) }}s @endif</div>
                            </div>
                        @empty
                            <p class="text-sm text-[var(--am-text-secondary)]">Noch keine Synchronisierungen.</p>
                        @endforelse
                    </x-asset-manager-panel>

                    {{-- Rohdaten (Graph-API) --}}
                    @if($device->raw_data)
                        <x-asset-manager-panel title="Rohdaten (Graph-API)">
                            <div x-data="{ open: false }">
                                <button type="button" @click="open = !open" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">
                                    <span x-text="open ? 'Ausblenden' : 'Anzeigen'"></span> ({{ count((array) $device->raw_data) }} Felder)
                                </button>
                                <pre x-show="open" x-cloak class="mt-2 text-[10px] text-[var(--am-text-secondary)] font-mono whitespace-pre-wrap break-all max-h-72 overflow-y-auto">{{ json_encode($device->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </x-asset-manager-panel>
                    @endif
                </div>
            @endif

            {{-- ===================== PLATZHALTER ===================== --}}
            <div x-show="tab === 'documents'" x-cloak>@include('asset-manager::livewire.inventory.partials.tab-placeholder', ['title' => 'Dokumente', 'icon' => 'heroicon-o-document-text'])</div>
            <div x-show="tab === 'tickets'" x-cloak>@include('asset-manager::livewire.inventory.partials.tab-placeholder', ['title' => 'Tickets', 'icon' => 'heroicon-o-ticket'])</div>
            <div x-show="tab === 'invoices'" x-cloak>@include('asset-manager::livewire.inventory.partials.tab-placeholder', ['title' => 'Rechnungen', 'icon' => 'heroicon-o-banknotes'])</div>
        </div>
        </div>
    </div>

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
