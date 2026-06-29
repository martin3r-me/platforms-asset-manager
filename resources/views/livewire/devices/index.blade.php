<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte', 'icon' => 'computer-desktop'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
                @if($config && $config->isConfigured() && $config->last_sync_at)
                    <span class="text-xs text-[var(--am-text-secondary)]">
                        @svg('heroicon-o-clock', 'w-3 h-3 inline -mt-0.5')
                        Letzter Sync {{ $config->last_sync_at->diffForHumans() }}
                    </span>
                @endif
                @if($config && $config->isConfigured() && $stats['total'] > 0)
                    <x-asset-manager-button variant="ghost" size="sm" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                        @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                        Export CSV
                    </x-asset-manager-button>
                @endif
                <x-asset-manager-button variant="ghost" size="sm" href="{{ route('asset-manager.setup') }}" wire:navigate>
                    @svg('heroicon-o-wrench-screwdriver', 'w-3.5 h-3.5')
                    Connector
                </x-asset-manager-button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                <x-asset-manager-filter-section title="Schnellfilter">
                    <div class="space-y-0.5">
                        @foreach([
                            ['all',          'Alle Geräte'],
                            ['no_user',      'Ohne Nutzer'],
                            ['inactive',     'Inaktiv (>30 T.)'],
                            ['noncompliant', 'Nicht konform'],
                            ['issues',       'Fehler / Konflikt'],
                            ['expiring',     'Läuft ab (90 T.)'],
                            ['unencrypted',  'Nicht verschlüsselt'],
                        ] as [$key, $label])
                            <button wire:click="setPreset('{{ $key }}')"
                                class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-[11px] rounded-md transition-colors {{ $preset === $key ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] font-medium' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                                <span>{{ $label }}</span>
                                <span class="tabular-nums px-1.5 py-0.5 rounded-full text-[10px] {{ $preset === $key ? 'bg-[var(--am-on-primary)]/20 text-[var(--am-on-primary)]' : 'bg-[var(--am-bg)] text-[var(--am-text-secondary)]' }}">{{ $presetCounts[$key] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search" placeholder="Name, Nutzer, Seriennr..." class="w-full" />
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Compliance">
                    <x-asset-manager-select size="sm" wire:model.live="filterCompliance" class="w-full">
                        <option value="">Alle</option>
                        <option value="compliant">Konform</option>
                        <option value="noncompliant">Nicht konform</option>
                        <option value="inGracePeriod">Karenzzeit</option>
                        <option value="unknown">Unbekannt</option>
                        <option value="error">Fehler</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                @if($osList->count() > 0)
                    <x-asset-manager-filter-section title="Betriebssystem">
                        <x-asset-manager-select size="sm" wire:model.live="filterOs" class="w-full">
                            <option value="">Alle</option>
                            @foreach($osList as $os) <option value="{{ $os }}">{{ $os }}</option> @endforeach
                        </x-asset-manager-select>
                    </x-asset-manager-filter-section>
                @endif

                <x-asset-manager-filter-section title="Lifecycle">
                    <x-asset-manager-select size="sm" wire:model.live="filterLifecycle" class="w-full">
                        <option value="">Alle</option>
                        <option value="in_use">In Betrieb</option>
                        <option value="spare">Reserve / Lager</option>
                        <option value="repair">In Reparatur</option>
                        <option value="defect">Defekt / Kaputt</option>
                        <option value="retired">Ausgemustert</option>
                        <option value="lost">Verloren / Gestohlen</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Anzeige">
                    <div class="text-[11px]">
                        <div class="flex items-center justify-between py-1.5">
                            <span class="text-[var(--am-text-secondary)]">Pro Seite</span>
                            <x-asset-manager-select size="sm" wire:model.live="perPage">
                                <option value="15">15</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </x-asset-manager-select>
                        </div>
                    </div>
                    <button wire:click="resetColumnOrder" class="w-full text-left mt-2 px-1 py-2 border-t border-[color:var(--am-border)] text-[11px] text-[var(--am-text-secondary)] hover:text-[var(--am-text)]">
                        @svg('heroicon-o-arrow-uturn-left', 'w-3 h-3 inline -mt-0.5 mr-1')
                        Spalten zurücksetzen
                    </button>
                </x-asset-manager-filter-section>

                @if($search || $filterCompliance || $filterOs || $filterLifecycle || $preset !== 'all')
                    <x-asset-manager-button variant="ghost" size="sm" class="w-full"
                            wire:click="$set('search', ''); $set('filterCompliance', ''); $set('filterOs', ''); $set('filterLifecycle', ''); setPreset('all')">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-asset-manager-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Master-Detail --}}
    <x-slot name="activity">
        <x-ui-page-sidebar :title="$selectedDevice ? 'Gerät' : ($selectedEmployee ? 'Mitarbeiter' : 'Details')" icon="heroicon-o-information-circle" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">

                @if($selectedDevice)
                    {{-- Detail: einzelnes Gerät --}}
                    <div class="flex items-center justify-between pb-2 border-b border-[color:var(--am-border)]">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Auswahl</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--am-text-secondary)] hover:text-red-600">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                        <div class="flex items-start gap-2 mb-2">
                            @svg('heroicon-o-computer-desktop', 'w-5 h-5 text-[var(--am-accent)] flex-shrink-0')
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-[var(--am-text)] truncate">{{ $selectedDevice->device_name ?? '—' }}</div>
                                <div class="text-[11px] text-[var(--am-text-secondary)] truncate">{{ trim($selectedDevice->manufacturer . ' ' . $selectedDevice->model) }}</div>
                            </div>
                        </div>

                        <x-asset-manager-badge :color="$selectedDevice->complianceBadgeColor()" dot size="xs">
                            {{ $selectedDevice->complianceLabel() }}
                        </x-asset-manager-badge>
                    </section>

                    <x-asset-manager-panel title="Eigenschaften" body-class="p-0">
                        <x-asset-manager-detail-list>
                            @foreach([
                                ['OS',           $selectedDevice->operating_system],
                                ['Version',      $selectedDevice->os_version],
                                ['Nutzer',       $selectedDevice->user_display_name],
                                ['UPN',          $selectedDevice->user_principal_name],
                                ['Management',   $selectedDevice->management_state],
                                ['Seriennr.',    $selectedDevice->serial_number],
                                ['Enrollt am',   $selectedDevice->enrolled_at?->format('d.m.Y')],
                                ['Letztes Check-In', $selectedDevice->last_check_in_at?->diffForHumans()],
                            ] as [$label, $value])
                                <x-asset-manager-detail-row :label="$label">{{ $value ?: '—' }}</x-asset-manager-detail-row>
                            @endforeach
                        </x-asset-manager-detail-list>
                    </x-asset-manager-panel>

                    <div class="space-y-2">
                        <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.devices.show', $selectedDevice) }}" wire:navigate class="w-full">
                            @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5')
                            Vollständige Detail-Seite
                        </x-asset-manager-button>
                        @if($selectedDevice->user_principal_name)
                            <x-asset-manager-button variant="secondary" size="md" class="w-full" wire:click="selectEmployeeByUpn('{{ $selectedDevice->user_principal_name }}')">
                                @svg('heroicon-o-user', 'w-3.5 h-3.5')
                                Mitarbeiter-Profil anzeigen
                            </x-asset-manager-button>
                        @endif
                    </div>

                @elseif($selectedEmployee)
                    {{-- Detail: Mitarbeiter mit allen Geräten/Lizenzen --}}
                    <div class="flex items-center justify-between pb-2 border-b border-[color:var(--am-border)]">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Mitarbeiter</span>
                        <button wire:click="clearSelection" class="text-[10px] text-[var(--am-text-secondary)] hover:text-red-600">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)] flex items-center justify-center text-xs font-semibold flex-shrink-0">
                                {{ $selectedEmployee->initials() }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-[var(--am-text)] truncate">{{ $selectedEmployee->name }}</div>
                                <div class="text-[10px] text-[var(--am-text-secondary)] truncate">{{ $selectedEmployee->user_principal_name }}</div>
                            </div>
                        </div>
                        @if($selectedEmployee->department || $selectedEmployee->job_title)
                            <div class="mt-2 text-[10px] text-[var(--am-text-secondary)]">
                                {{ $selectedEmployee->job_title }}@if($selectedEmployee->department && $selectedEmployee->job_title) · @endif{{ $selectedEmployee->department }}
                            </div>
                        @endif
                    </section>

                    <x-asset-manager-panel title="Geräte ({{ $employeeDevices->count() }})" body-class="p-0">
                        @if($employeeDevices->isEmpty())
                            <div class="px-3 py-3 text-[11px] text-[var(--am-text-secondary)]">Keine Geräte zugewiesen.</div>
                        @else
                            <div class="divide-y divide-[color:var(--am-border)]">
                                @foreach($employeeDevices as $d)
                                    <button wire:click="selectDevice({{ $d->id }})" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-[var(--am-bg)] text-left">
                                        @svg('heroicon-o-computer-desktop', 'w-3.5 h-3.5 text-[var(--am-text-muted)] flex-shrink-0')
                                        <div class="min-w-0 flex-1">
                                            <div class="text-[11px] font-medium text-[var(--am-text)] truncate">{{ $d->device_name ?? '—' }}</div>
                                            <div class="text-[10px] text-[var(--am-text-secondary)] truncate">{{ $d->operating_system }} {{ $d->os_version }}</div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </x-asset-manager-panel>

                    <x-asset-manager-panel title="Lizenzen ({{ $employeeLicenses->count() }})" body-class="p-0">
                        @if($employeeLicenses->isEmpty())
                            <div class="px-3 py-3 text-[11px] text-[var(--am-text-secondary)]">Keine Lizenzen zugewiesen.</div>
                        @else
                            <ul class="divide-y divide-[color:var(--am-border)]">
                                @foreach($employeeLicenses as $lic)
                                    <li class="px-3 py-2 text-[11px] text-[var(--am-text)] truncate">
                                        @svg('heroicon-o-key', 'w-3 h-3 text-[var(--am-text-muted)] inline -mt-0.5 mr-1')
                                        {{ $lic->sku_part_number ?? $lic->sku_id }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-asset-manager-panel>

                    <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.employees.show', $selectedEmployee) }}" wire:navigate class="w-full">
                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5')
                        Vollständiges Profil
                    </x-asset-manager-button>

                @else
                    {{-- Keine Auswahl: Hinweis --}}
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[var(--am-text-muted)] mb-3')
                        <p class="text-[11px] text-[var(--am-text-secondary)] mb-1">Wähle ein Gerät oder einen Nutzer aus der Tabelle.</p>
                        <p class="text-[10px] text-[var(--am-text-muted)]">Sync-Informationen findest du im Connector.</p>
                    </div>

                    @if($canSync && $config && $config->isConfigured() && $config->sync_status !== 'running')
                        <x-asset-manager-button variant="primary" size="md" class="w-full" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">
                            <span wire:loading.remove wire:target="syncNow" class="inline-flex items-center gap-1.5">@svg('heroicon-o-arrow-path', 'w-3 h-3') Jetzt synchronisieren</span>
                            <span wire:loading wire:target="syncNow" class="inline-flex items-center gap-1.5">@svg('heroicon-o-arrow-path', 'w-3 h-3 animate-spin') Startet...</span>
                        </x-asset-manager-button>
                    @endif

                    @if($syncResult)
                        <div class="p-2 rounded-lg bg-[var(--am-accent-surface)] border border-[color:var(--am-border)] text-[11px] text-[var(--am-accent)]">
                            {{ $syncResult }}
                        </div>
                    @endif

                    <a href="{{ route('asset-manager.setup') }}" wire:navigate class="block text-center text-[10px] text-[var(--am-text-secondary)] hover:text-[var(--am-accent)] mt-2">
                        Connector & vollständige Sync-Historie →
                    </a>
                @endif

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-12 h-12 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-center mb-4">
                        @svg('heroicon-o-wrench-screwdriver', 'w-6 h-6 text-amber-600')
                    </div>
                    <h3 class="text-sm font-medium text-[var(--am-text)] mb-1">Connector nicht konfiguriert</h3>
                    <p class="text-xs text-[var(--am-text-secondary)] mb-4 max-w-xs">Trage die Azure App-Registration Credentials ein, um Intune-Gerätedaten zu synchronisieren.</p>
                    @if($canSync)
                        <x-asset-manager-button variant="primary" size="md" href="{{ route('asset-manager.setup') }}" wire:navigate>
                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                            Connector einrichten
                        </x-asset-manager-button>
                    @endif
                </div>
            @else

            {{-- Stat-Karten --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <x-asset-manager-stat-card label="Geräte gesamt" :value="$stats['total']" accent="navy" />
                <x-asset-manager-stat-card label="Konform" :value="$stats['compliant']" accent="emerald" value-class="text-emerald-600" />
                <x-asset-manager-stat-card label="Nicht konform" :value="$stats['noncompliant']" accent="red" value-class="text-red-600" />
                <x-asset-manager-stat-card label="Unbekannt" :value="$stats['unknown']" accent="navy" value-class="text-[var(--am-text-muted)]" />
            </div>

            {{-- Aktive Filter --}}
            @if($search || $filterCompliance || $filterOs || $filterLifecycle || $preset !== 'all')
                @php
                    $presetLabels = ['no_user' => 'Ohne Nutzer', 'inactive' => 'Inaktiv (>30 T.)', 'noncompliant' => 'Nicht konform', 'issues' => 'Fehler / Konflikt', 'expiring' => 'Läuft ab (90 T.)', 'unencrypted' => 'Nicht verschlüsselt'];
                    $lifecycleLabels = \Platform\AssetManager\Models\AssetDevice::LIFECYCLE_LABELS;
                @endphp
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-[var(--am-text-secondary)]">Aktive Filter:</span>
                    @if($preset !== 'all')<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)]">{{ $presetLabels[$preset] ?? $preset }} <button wire:click="setPreset('all')">×</button></span>@endif
                    @if($search)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)]">Suche: "{{ Str::limit($search, 30) }}" <button wire:click="$set('search', '')">×</button></span>@endif
                    @if($filterCompliance)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)]">{{ $filterCompliance }} <button wire:click="$set('filterCompliance', '')">×</button></span>@endif
                    @if($filterOs)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)]">{{ $filterOs }} <button wire:click="$set('filterOs', '')">×</button></span>@endif
                    @if($filterLifecycle)<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)]">{{ $lifecycleLabels[$filterLifecycle] ?? $filterLifecycle }} <button wire:click="$set('filterLifecycle', '')">×</button></span>@endif
                </div>
            @endif

            {{-- Bulk-Result --}}
            @if($bulkResult)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-600 flex-shrink-0')
                    <p class="text-sm text-emerald-700">{{ $bulkResult }}</p>
                    <button wire:click="$set('bulkResult', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">×</button>
                </div>
            @endif

            {{-- Bulk-Aktionsleiste (nur owner/admin, nur bei Auswahl) --}}
            @if($canManage && count($selected) > 0)
                <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm">
                    <span class="text-sm font-medium">{{ count($selected) }} ausgewählt</span>
                    <div class="h-4 w-px bg-[var(--am-on-primary)]/30"></div>

                    {{-- Kostenstelle --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkCostCenter" class="px-2 py-1 text-xs rounded-md bg-[var(--am-on-primary)]/15 border border-[var(--am-on-primary)]/20 text-[var(--am-on-primary)] [&>option]:text-[var(--am-text)]">
                            <option value="">Kostenstelle…</option>
                            @foreach($costCenters as $cc)
                                <option value="{{ $cc->id }}">{{ $cc->code }}{{ $cc->name ? ' · ' . $cc->name : '' }}</option>
                            @endforeach
                        </select>
                        <button wire:click="bulkSetCostCenter" class="px-2.5 py-1 text-xs font-medium rounded-md bg-[var(--am-on-primary)]/20 hover:bg-[var(--am-on-primary)]/30 transition-colors">Setzen</button>
                    </div>

                    {{-- Lifecycle --}}
                    <div class="flex items-center gap-1.5">
                        <select wire:model="bulkLifecycle" class="px-2 py-1 text-xs rounded-md bg-[var(--am-on-primary)]/15 border border-[var(--am-on-primary)]/20 text-[var(--am-on-primary)] [&>option]:text-[var(--am-text)]">
                            <option value="">Lifecycle…</option>
                            <option value="in_use">In Betrieb</option>
                            <option value="spare">Reserve / Lager</option>
                            <option value="repair">In Reparatur</option>
                            <option value="defect">Defekt / Kaputt</option>
                            <option value="retired">Ausgemustert</option>
                            <option value="lost">Verloren / Gestohlen</option>
                        </select>
                        <button wire:click="bulkSetLifecycle" class="px-2.5 py-1 text-xs font-medium rounded-md bg-[var(--am-on-primary)]/20 hover:bg-[var(--am-on-primary)]/30 transition-colors">Setzen</button>
                    </div>

                    <button wire:click="clearBulkSelection" class="ml-auto text-xs text-[var(--am-on-primary)]/80 hover:text-[var(--am-on-primary)]">Auswahl aufheben</button>
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

            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($devices->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-computer-desktop', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                        <p class="text-sm text-[var(--am-text-secondary)]">
                            @if($search || $filterCompliance || $filterOs || $filterLifecycle || $preset !== 'all') Keine Geräte für diese Filter.
                            @else Noch keine Geräte synchronisiert. @endif
                        </p>
                    </div>
                @else
                    @if($canManage && $selectPage && count($selected) < $devices->total())
                        <div class="px-5 py-2 bg-[var(--am-accent-surface)] border-b border-[color:var(--am-border)] text-center text-xs text-[var(--am-accent)]">
                            {{ count($selected) }} auf dieser Seite ausgewählt.
                            <button wire:click="selectAllFiltered" class="font-medium underline hover:opacity-80">Alle {{ $devices->total() }} gefilterten auswählen</button>
                        </div>
                    @endif
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr wire:sortable="reorderColumns" wire:sortable.options="{ axis: 'x' }" class="border-b border-[color:var(--am-border)]">
                                @if($canManage)
                                    <th class="w-10 px-5 py-3 bg-[var(--am-bg)]">
                                        <input type="checkbox" wire:model.live="selectPage" class="rounded border-[color:var(--am-border)] text-[var(--am-accent)] focus:ring-[var(--am-accent)]/30" />
                                    </th>
                                @endif
                                @foreach($columns as $colKey)
                                    @php $def = $columnDefs[$colKey] ?? null; @endphp
                                    @if($def)
                                        <th wire:sortable.item="{{ $colKey }}" wire:key="col-{{ $colKey }}"
                                            class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] bg-[var(--am-bg)]">
                                            <div class="flex items-center gap-2">
                                                <button wire:sortable.handle type="button" title="Spalte verschieben" class="text-[var(--am-text-muted)] hover:text-[var(--am-text-secondary)] cursor-grab active:cursor-grabbing">
                                                    @svg('heroicon-o-bars-3', 'w-3.5 h-3.5')
                                                </button>
                                                @if($def['sortField'])
                                                    <button wire:click="sortBy('{{ $def['sortField'] }}')" class="flex items-center gap-1 hover:text-[var(--am-text-secondary)]">
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
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($devices as $device)
                                @php
                                    $isSelected = $detailType === 'device' && $detailId === $device->id;
                                    $isChecked  = $canManage && in_array((string) $device->id, $selected, true);
                                @endphp
                                <tr wire:key="row-{{ $device->id }}"
                                    class="cursor-pointer transition-colors {{ $isSelected ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : ($isChecked ? 'bg-[var(--am-bg)]' : 'hover:bg-[var(--am-bg)]') }}">
                                    @if($canManage)
                                        <td class="px-5 py-3">
                                            <input type="checkbox" value="{{ $device->id }}" wire:model.live="selected" class="rounded border-[color:var(--am-border)] text-[var(--am-accent)] focus:ring-[var(--am-accent)]/30" />
                                        </td>
                                    @endif
                                    @foreach($columns as $colKey)
                                        @switch($colKey)
                                            @case('device')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3">
                                                    <div class="font-medium text-[var(--am-text)]">{{ $device->device_name ?? '—' }}</div>
                                                    @if($device->model)
                                                        <div class="text-xs text-[var(--am-text-secondary)]">{{ $device->manufacturer }} {{ $device->model }}</div>
                                                    @endif
                                                    @php $missingHandover = $device->user_principal_name && ! isset($openHandoverDeviceIds[$device->id]); @endphp
                                                    @if($device->lifecycle_status || $device->isExpiringSoon() || $missingHandover)
                                                        <div class="flex flex-wrap items-center gap-1 mt-1">
                                                            @if($device->lifecycle_status)
                                                                <x-asset-manager-badge :color="$device->lifecycleBadgeColor()" size="xs">{{ $device->lifecycleLabel() }}</x-asset-manager-badge>
                                                            @endif
                                                            @if($device->isExpiringSoon())
                                                                <x-asset-manager-badge color="amber" size="xs" icon="heroicon-o-exclamation-triangle">läuft ab</x-asset-manager-badge>
                                                            @endif
                                                            @if($missingHandover)
                                                                <x-asset-manager-badge color="amber" size="xs" icon="heroicon-o-clipboard-document-check" title="Gerät mit Nutzer, aber ohne offene Ausgabe">ohne Ausgabe</x-asset-manager-badge>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </td>
                                            @break
                                            @case('user')
                                                <td class="px-5 py-3">
                                                    @if($device->user_principal_name)
                                                        <button wire:click.stop="selectEmployeeByUpn('{{ $device->user_principal_name }}')" class="text-left hover:text-[var(--am-accent)]">
                                                            <div class="text-[var(--am-text-secondary)]">{{ $device->user_display_name ?? '—' }}</div>
                                                            <div class="text-xs text-[var(--am-text-muted)] truncate max-w-[180px]">{{ $device->user_principal_name }}</div>
                                                        </button>
                                                    @else
                                                        <span class="text-[var(--am-text-muted)]">—</span>
                                                    @endif
                                                </td>
                                            @break
                                            @case('os')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3">
                                                    <div class="text-[var(--am-text-secondary)]">{{ $device->operating_system ?? '—' }}</div>
                                                    @if($device->os_version)<div class="text-xs text-[var(--am-text-muted)]">{{ $device->os_version }}</div>@endif
                                                </td>
                                            @break
                                            @case('status')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3">
                                                    <x-asset-manager-badge :color="$device->complianceBadgeColor()" dot size="sm">
                                                        {{ $device->complianceLabel() }}
                                                    </x-asset-manager-badge>
                                                </td>
                                            @break
                                            @case('lastCheckIn')
                                                <td wire:click="selectDevice({{ $device->id }})" class="px-5 py-3 text-sm text-[var(--am-text-muted)]">
                                                    {{ $device->last_check_in_at ? $device->last_check_in_at->diffForHumans() : '—' }}
                                                </td>
                                            @break
                                        @endswitch
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                    @if($devices->hasPages())
                        <div class="px-5 py-3 border-t border-[color:var(--am-border)]">{{ $devices->links() }}</div>
                    @endif
                @endif
            </div>

            @endif {{-- Connector OK --}}

        </div>

        {{-- BOTTOM PANEL: Verteilungen --}}
        @if($config && $config->isConfigured() && $stats['total'] > 0)
            <div class="shrink-0 border-t border-[color:var(--am-border)] bg-[var(--am-bg)]" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="w-full p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--am-bg)] text-[11px] uppercase tracking-wider text-[var(--am-text-secondary)]">
                    <span class="font-semibold">Verteilung</span>
                    <span class="text-[10px]">OS · Compliance</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up', 'w-3 h-3', ['x-show' => 'open', 'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--am-border)] p-4 max-h-64 overflow-y-auto bg-[var(--am-surface)]">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] mb-3">Betriebssysteme</div>
                            <div class="space-y-2">
                                @foreach($osBreakdown as $row)
                                    @php $pct = $stats['total'] > 0 ? round($row->count / $stats['total'] * 100) : 0; @endphp
                                    <div>
                                        <div class="flex items-baseline justify-between text-[11px] mb-0.5">
                                            <span class="text-[var(--am-text-secondary)] truncate">{{ $row->os }}</span>
                                            <span class="text-[var(--am-text-muted)] tabular-nums ml-2 flex-shrink-0">{{ $row->count }} ({{ $pct }}%)</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                            <div class="h-full bg-[var(--am-accent)]" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] mb-3">Compliance-Status</div>
                            <div class="space-y-2">
                                @foreach($complianceBreakdown as $row)
                                    @php
                                        $pct = $stats['total'] > 0 ? round($row->count / $stats['total'] * 100) : 0;
                                        $color = match($row->compliance_state) {
                                            'compliant'=>'emerald','noncompliant'=>'red','inGracePeriod'=>'amber',
                                            'error'=>'red','conflict'=>'orange',default=>'gray',
                                        };
                                        $label = match($row->compliance_state) {
                                            'compliant'=>'Konform','noncompliant'=>'Nicht konform','inGracePeriod'=>'Karenzzeit',
                                            'error'=>'Fehler','conflict'=>'Konflikt',default=>'Unbekannt',
                                        };
                                    @endphp
                                    <div>
                                        <div class="flex items-baseline justify-between text-[11px] mb-0.5">
                                            <span class="inline-flex items-center gap-1.5 text-[var(--am-text-secondary)]">
                                                <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                                                {{ $label }}
                                            </span>
                                            <span class="text-[var(--am-text-muted)] tabular-nums">{{ $row->count }} ({{ $pct }}%)</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                            <div class="h-full bg-{{ $color }}-500" style="width: {{ $pct }}%"></div>
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
