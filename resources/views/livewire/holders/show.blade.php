<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Asset-Träger', 'href' => route('asset-manager.holders.index'), 'icon' => 'users'],
            ['label' => $holder->name],
        ]" />
    </x-slot>

    {{-- LINKS: Eigenschaften (editierbar) --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Profil" icon="heroicon-o-user" width="w-72" :defaultOpen="true">
            {{-- Profil bearbeiten nur Owner/Admin (E1/ADR 0004) — Backend: save() Gate. Member sehen
                 die Stammdaten read-only im Haupt-Content. --}}
            @can('asset-manager.manage')
            <form wire:submit="save" class="p-4 space-y-4 bg-[var(--am-bg)]">

                <x-asset-manager-filter-section title="Identität">
                    <div class="space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Anzeigename</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="displayName" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">E-Mail</label>
                            <x-asset-manager-input size="sm" type="email" wire:model="email" />
                        </div>
                        <div class="text-[10px] text-[var(--am-text-muted)]">
                            <strong>UPN:</strong> <span class="font-mono">{{ $holder->user_principal_name }}</span>
                        </div>
                    </div>
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Organisation">
                    <div class="space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Position</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="jobTitle" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Abteilung</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="department" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Kostenstelle</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="costCenter" />
                        </div>
                        @if($holder->source === 'graph')
                            <p class="text-[9px] text-[var(--am-text-muted)] leading-tight pt-0.5">
                                Position &amp; Abteilung stammen aus Entra und werden beim nächsten Sync überschrieben.
                            </p>
                        @endif
                    </div>
                </x-asset-manager-filter-section>

                {{-- Träger-Typ (ADR 0017) und, bei Funktionskonten, das zugehörige System (ADR 0019) --}}
                <x-asset-manager-filter-section title="Art des Trägers">
                    <div class="space-y-1.5">
                        <x-asset-manager-select size="sm" wire:model.live="holderType">
                            <option value="person">Person</option>
                            <option value="function">Funktionskonto</option>
                            <option value="admin">Admin-Account</option>
                            <option value="service">Service-/Technik-Account</option>
                            <option value="external">Extern</option>
                        </x-asset-manager-select>
                        @error('holderType')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror

                        <p class="text-[10px] text-[var(--am-text-muted)] m-0">
                            Von Hand gesetzt bleibt der Typ beim nächsten Sync erhalten. Automatisch erkennen
                            lassen sich Muster über die Regeln in den
                            <a href="{{ route('asset-manager.settings') }}" wire:navigate class="underline">Moduleinstellungen</a>.
                        </p>

                        @if($holderType === 'function')
                            <div class="pt-1.5 space-y-1.5">
                                <label class="block text-[10px] text-[var(--am-text-muted)]">Zugehöriges System</label>
                                <x-asset-manager-input size="sm" type="text" wire:model="functionSystem"
                                    placeholder="z. B. Archivsystem, Scanstation" />
                                @error('functionSystem')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror

                                @if(trim($functionSystem) === '' || $holder->cost_center_id === null)
                                    <p class="text-[10px] text-amber-700 m-0">
                                        @svg('heroicon-o-exclamation-triangle', 'w-3 h-3 inline')
                                        Ohne System <strong>und</strong> Kostenstelle wird dieses Konto bei der
                                        Lizenz-Verteilung wie eine Person behandelt — es bekommt keine
                                        Monatslizenz bevorzugt zugeteilt.
                                    </p>
                                @else
                                    <p class="text-[10px] text-[var(--am-text-muted)] m-0">
                                        Erhält bei der Lizenz-Verteilung vorrangig die teureren
                                        Monatspreis-Zeilen (flexibel kündbar).
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-asset-manager-filter-section>

                <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                    <div class="px-3 py-2">
                        <label class="flex items-center gap-2 text-[11px] text-[var(--am-text-secondary)]">
                            <input type="checkbox" wire:model="isActive" class="rounded border-[color:var(--am-border)]" />
                            Aktiv
                        </label>
                    </div>
                </div>

                <x-asset-manager-button type="submit" variant="primary" size="md" class="w-full">
                    @svg('heroicon-o-check', 'w-3.5 h-3.5')
                    Speichern
                </x-asset-manager-button>
                @if($saved)
                    <div class="text-[10px] text-emerald-700 text-center">Gespeichert.</div>
                @endif
            </form>
            @endcan

            {{-- DSGVO-Einzel-Anonymisierung (E2 / ADR 0005) — nur Owner/Admin, mit Bestätigung. --}}
            @can('asset-manager.manage')
                <div class="px-4 pb-4 -mt-1">
                    <div class="pt-3 border-t border-[color:var(--am-border)]">
                        <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1.5">DSGVO</div>
                        <x-asset-manager-button type="button" variant="danger" size="md"
                                wire:click="anonymize"
                                wire:confirm="Diese Person anonymisieren? Anzeigename, E-Mail und UPN werden pseudonymisiert und die verknüpften Geräte/Lizenzen maskiert. Das lässt sich nicht rückgängig machen."
                                class="w-full">
                            @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                            Anonymisieren
                        </x-asset-manager-button>
                        @if($anonymized)
                            <div class="mt-1 text-[10px] text-emerald-700 text-center">Anonymisiert.</div>
                        @endif
                    </div>
                </div>
            @endcan
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Kosten-Übersicht --}}
    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-5">

            {{-- Header --}}
            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
                <div class="flex items-start gap-4">
                    <div class="w-14 h-14 rounded-full bg-[var(--am-accent-surface)] flex items-center justify-center text-[var(--am-accent)] font-semibold text-lg flex-shrink-0">
                        {{ $holder->initials() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <h1 class="text-lg font-semibold text-[var(--am-text)]">{{ $holder->name }}</h1>
                        <p class="text-sm text-[var(--am-text-secondary)]">{{ $holder->user_principal_name }}</p>
                        @if($holder->department || $holder->job_title)
                            <p class="text-xs text-[var(--am-text-secondary)] mt-1">
                                {{ $holder->job_title }}@if($holder->department && $holder->job_title) · @endif{{ $holder->department }}
                            </p>
                        @endif
                    </div>
                    @if(!$holder->is_active)
                        <x-asset-manager-badge color="gray" size="sm" class="flex-shrink-0">Inaktiv</x-asset-manager-badge>
                    @endif
                </div>
            </div>

            {{-- Counts --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <x-asset-manager-stat-card label="Intune-Geräte" :value="$devices->count()" icon="heroicon-o-computer-desktop" accent="sky" />
                <x-asset-manager-stat-card label="Sonstige Assets" :value="$items->count()" icon="heroicon-o-cube" accent="indigo" />
                <x-asset-manager-stat-card label="Lizenzen" :value="$licenses->count()" icon="heroicon-o-key" accent="violet" />
                <x-asset-manager-stat-card label="pro Monat" :value="number_format($totalCost, 2, ',', '.') . ' €'" icon="heroicon-o-banknotes" accent="navy" value-class="text-[var(--am-accent)]" />
            </div>

            {{-- Kostenaufschlüsselung. Stand bis S8 in einer rechten Sidebar, die per Default
                 zugeklappt war — die Kachel „pro Monat" oben nannte damit eine Summe, deren
                 Herkunft erst nach einem Klick sichtbar wurde. --}}
            @if($totalCost > 0)
                <x-asset-manager-panel title="Kosten pro Monat">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Hardware (AfA)</div>
                            <div class="mt-1 text-lg font-semibold text-[var(--am-text)] tabular-nums">{{ number_format($hardwareCost, 2, ',', '.') }} €</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Geräte (Intune)</div>
                            <div class="mt-1 text-lg font-semibold text-[var(--am-text)] tabular-nums">{{ number_format($deviceCost, 2, ',', '.') }} €</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Lizenzen</div>
                            <div class="mt-1 text-lg font-semibold text-[var(--am-text)] tabular-nums">{{ number_format($licenseCost, 2, ',', '.') }} €</div>
                        </div>
                    </div>
                </x-asset-manager-panel>
            @endif

            {{-- Geräte aus Intune --}}
            @if($devices->count() > 0)
                <x-asset-manager-panel title="Intune-Geräte" body-class="p-0">
                    <x-slot name="actions">
                        <span class="text-xs text-[var(--am-text-secondary)]">{{ $devices->count() }}</span>
                    </x-slot>
                    <div class="divide-y divide-[color:var(--am-border)]">
                        @foreach($devices as $d)
                            <a href="{{ route('asset-manager.devices.show', $d) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-[var(--am-bg)]">
                                @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-[var(--am-text-muted)] flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--am-text)] truncate">{{ $d->device_name }}</div>
                                    <div class="text-xs text-[var(--am-text-secondary)]">{{ $d->manufacturer }} {{ $d->model }} · {{ $d->operating_system }}</div>
                                </div>
                                @php $dCost = (float) ($deviceRows[$d->id]['amount'] ?? 0); @endphp
                                @if($dCost > 0)
                                    <span class="text-xs text-[var(--am-text-secondary)] tabular-nums mr-1">{{ number_format($dCost, 2, ',', '.') }} €</span>
                                @endif
                                <x-asset-manager-badge :color="$d->complianceBadgeColor()" size="xs">{{ $d->complianceLabel() }}</x-asset-manager-badge>
                            </a>
                        @endforeach
                    </div>
                </x-asset-manager-panel>
            @endif

            {{-- Geräteausgaben (Übergabeprotokolle) — read-only; Anlegen von der Geräteseite/globalen Liste --}}
            @if($handovers->count() > 0)
                <x-asset-manager-panel title="Geräteausgaben" body-class="p-0">
                    <x-slot name="actions">
                        <span class="text-xs text-[var(--am-text-secondary)]">{{ $handovers->count() }}</span>
                    </x-slot>
                    <div class="divide-y divide-[color:var(--am-border)]">
                        @foreach($handovers as $ho)
                            <div class="flex items-center gap-3 px-5 py-3">
                                @svg('heroicon-o-clipboard-document-check', 'w-4 h-4 text-[var(--am-text-muted)] flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--am-text)] truncate">
                                        {{ $ho->lines->map(fn($l) => $l->deviceName())->take(3)->implode(', ') ?: '—' }}
                                    </div>
                                    <div class="text-xs text-[var(--am-text-secondary)]">
                                        {{ $ho->issued_at?->format('d.m.Y') ?? '—' }} · {{ $ho->lines->count() }} Gerät(e) · {{ $ho->isSigned() ? 'unterschrieben' : 'nicht unterschrieben' }}
                                    </div>
                                </div>
                                @php $stColor = [
                                    'open'               => 'emerald',
                                    'partially_returned' => 'amber',
                                    'returned'           => 'gray',
                                ][$ho->status] ?? 'gray'; @endphp
                                <x-asset-manager-badge :color="$stColor" size="xs">{{ $ho->statusLabel() }}</x-asset-manager-badge>
                                <a href="{{ route('asset-manager.handovers.pdf', $ho->id) }}" target="_blank" class="text-[var(--am-text-muted)] hover:text-[var(--am-accent)]" title="Protokoll-PDF">@svg('heroicon-o-document-arrow-down', 'w-4 h-4')</a>
                            </div>
                        @endforeach
                    </div>
                </x-asset-manager-panel>
            @endif

            {{-- Sonstige Assets --}}
            @if($items->count() > 0)
                <x-asset-manager-panel title="Assets (Hardware)" body-class="p-0">
                    <x-slot name="actions">
                        <span class="text-xs text-[var(--am-text-secondary)]">{{ $items->count() }}</span>
                    </x-slot>
                    <div class="divide-y divide-[color:var(--am-border)]">
                        @foreach($items as $it)
                            <a href="{{ route('asset-manager.assets.show', $it) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-[var(--am-bg)]">
                                @if($it->category?->icon) @svg($it->category->icon, 'w-4 h-4 text-[var(--am-text-muted)] flex-shrink-0') @else @svg('heroicon-o-cube', 'w-4 h-4 text-[var(--am-text-muted)] flex-shrink-0') @endif
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--am-text)] truncate">{{ $it->name }}</div>
                                    <div class="text-xs text-[var(--am-text-secondary)]">{{ $it->category?->name }} · {{ trim($it->manufacturer . ' ' . $it->model) }}</div>
                                </div>
                                @if($it->monthlyCost() > 0)
                                    <span class="text-xs text-[var(--am-text-secondary)] tabular-nums">{{ number_format($it->monthlyCost(), 2, ',', '.') }} €</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </x-asset-manager-panel>
            @endif

            {{-- Lizenzen --}}
            @if($licenses->count() > 0)
                <x-asset-manager-panel title="Lizenzen" body-class="p-0">
                    <x-slot name="actions">
                        <span class="text-xs text-[var(--am-text-secondary)]">{{ $licenses->count() }}</span>
                    </x-slot>
                    <div class="divide-y divide-[color:var(--am-border)]">
                        @foreach($licenses as $lic)
                            @php $sku = $skuMap[$lic->sku_id] ?? null; @endphp
                            <div class="flex items-center gap-3 px-5 py-3">
                                @svg('heroicon-o-key', 'w-4 h-4 text-[var(--am-text-muted)] flex-shrink-0')
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--am-text)] truncate">
                                        @if($sku)
                                            <a href="{{ route('asset-manager.licenses.show', $sku) }}" wire:navigate class="hover:text-[var(--am-accent)]">
                                                {{ $sku->display_name ?? $sku->sku_part_number }}
                                            </a>
                                        @else
                                            {{ $lic->sku_part_number }}
                                        @endif
                                    </div>
                                </div>
                                @php
                                    // Der Preis DIESES Seats: aus seiner Vertragszeile, sonst der
                                    // handgepflegte Stückpreis der Lizenz (ADR 0019). Zwei Personen mit
                                    // derselben Lizenz können unterschiedliche Tarife tragen.
                                    $seatPrice = $lic->unit_price ?? $sku?->unit_price;
                                @endphp
                                @if($seatPrice !== null)
                                    <span class="text-xs text-[var(--am-text-secondary)] tabular-nums whitespace-nowrap">
                                        {{ number_format((float) $seatPrice, 2, ',', '.') }} €
                                        @if($lic->unit_price !== null && $lic->contractLine?->isYearly())
                                            <span class="text-[10px] text-[var(--am-text-muted)]">Jahr</span>
                                        @elseif($lic->unit_price !== null)
                                            <span class="text-[10px] text-[var(--am-text-muted)]">Monat</span>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-asset-manager-panel>
            @endif

            {{-- Mobilfunk (ADR 0014): Rufnummer/SIM/Vertragsnr/Datenvolumen am Asset-Träger; Monatspreis aus der
                 Mobilfunk-Kostenposition (read-only, Controlling). Bearbeiten via Modal (Button oben rechts). --}}
            @php $hasMobilfunk = $holder->mobile_phone || $holder->business_phone || $holder->sim_number || $holder->contract_number || $holder->data_volume; @endphp
            @if($hasMobilfunk || $canManage)
                <x-asset-manager-panel title="Mobilfunk">
                    <x-slot name="actions">
                        <div class="inline-flex items-center gap-3">
                            @if($controllingEnabled && $mobileCost > 0)
                                <span class="text-[11px] text-[var(--am-text-secondary)] tabular-nums">{{ number_format($mobileCost, 2, ',', '.') }} € / Monat</span>
                            @endif
                            @if($canManage)
                                <button type="button" wire:click="openMobilfunk" class="text-[11px] text-[var(--am-accent)] hover:underline inline-flex items-center gap-1">@svg('heroicon-o-pencil-square', 'w-3 h-3') Bearbeiten</button>
                            @endif
                        </div>
                    </x-slot>
                    <x-asset-manager-detail-list :cols="2">
                        <x-asset-manager-detail-row label="Mobilnummer" bordered><span class="tabular-nums">{{ $holder->mobile_phone ?: '—' }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="Geschäftlich" bordered><span class="tabular-nums">{{ $holder->business_phone ?: '—' }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="SIM-Nummer" bordered><span class="tabular-nums">{{ $holder->sim_number ?: '—' }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="Vertragsnummer" bordered><span class="tabular-nums">{{ $holder->contract_number ?: '—' }}</span></x-asset-manager-detail-row>
                        <x-asset-manager-detail-row label="Datenvolumen" bordered>{{ $holder->data_volume ?: '—' }}</x-asset-manager-detail-row>
                    </x-asset-manager-detail-list>
                </x-asset-manager-panel>
            @endif

            @if($devices->isEmpty() && $items->isEmpty() && $licenses->isEmpty())
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    @svg('heroicon-o-inbox', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
                    <p class="text-sm text-[var(--am-text-secondary)]">Diesem Asset-Träger sind noch keine Assets oder Lizenzen zugewiesen.</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Mobilfunk bearbeiten (Modal, innerhalb x-ui-page) — nur Owner/Admin. --}}
    @can('asset-manager.manage')
        @include('asset-manager::livewire.holders.partials.modal-mobilfunk')
    @endcan
</x-ui-page>
