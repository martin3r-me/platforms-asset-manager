<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Modul-Einstellungen" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einstellungen', 'icon' => 'cog-6-tooth'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-6 max-w-2xl">

            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Controlling-Schalter --}}
            <x-asset-manager-panel title="Controlling / Kostenaufteilung">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <p class="text-xs text-[var(--am-text-secondary)]">
                            Blendet die Kosten-Schicht ein bzw. aus: Auswertungen (Kostenaufteilung, Kostenpositionen, Kosten je Asset-Träger, Geräte nach Modell), Stammdaten und den Kosten-Import. Bei deaktiviertem Controlling bleibt das Geräte-/Asset-Inventar voll funktionsfähig; vorhandene Kostendaten bleiben erhalten.
                        </p>
                        {{-- Der Schalter gilt je Tenant (ADR 0016) — ohne diesen Hinweis wäre nicht
                             erkennbar, für welchen Kundenkontext gerade geschaltet wird. --}}
                        <p class="mt-2 text-xs text-[var(--am-text-muted)]">
                            Gilt für den Tenant <strong class="text-[var(--am-text-secondary)]">{{ $tenantName }}</strong>.
                            Andere Tenants dieses Teams sind davon unberührt.
                        </p>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium {{ $controllingEnabled ? 'text-emerald-700' : 'text-[var(--am-text-muted)]' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $controllingEnabled ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                            {{ $controllingEnabled ? 'Aktiviert' : 'Deaktiviert' }}
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        @if($canManage)
                            @if($controllingEnabled)
                                <x-asset-manager-button variant="ghost" size="md" type="button" wire:click="toggleControlling">
                                    @svg('heroicon-o-eye-slash', 'w-4 h-4')
                                    Deaktivieren
                                </x-asset-manager-button>
                            @else
                                <x-asset-manager-button variant="primary" size="md" type="button" wire:click="toggleControlling">
                                    @svg('heroicon-o-eye', 'w-4 h-4')
                                    Aktivieren
                                </x-asset-manager-button>
                            @endif
                        @else
                            <span class="text-xs text-[var(--am-text-muted)]">Nur Owner/Admin</span>
                        @endif
                    </div>
                </div>
            </x-asset-manager-panel>

            {{-- Traeger-Typ-Regeln (ADR 0017) --}}
            <x-asset-manager-panel title="Klassifizierung der Asset-Träger">
                <div class="space-y-3">
                    <p class="text-xs text-[var(--am-text-secondary)]">
                        Bestimmt, welche Träger beim Entra-Sync als <strong>Funktionskonto</strong>,
                        <strong>Admin-</strong>, <strong>Service-Account</strong> oder <strong>Extern</strong>
                        gelten — alle übrigen sind <strong>Personen</strong>. Nicht-Personen werden
                        <strong>gelabelt, nicht gefiltert</strong>: die Träger-Liste blendet sie standardmäßig
                        aus, Lizenz- und Kostenauswertungen zählen sie weiterhin mit.
                    </p>
                    <p class="text-xs text-[var(--am-text-muted)]">
                        Regeln gelten für den Tenant <strong class="text-[var(--am-text-secondary)]">{{ $tenantName }}</strong>.
                        Sie stehen hier und nicht im Code, weil „alles mit <code>adm-</code> ist ein Admin"
                        eine Konvention dieses Kunden ist und keine allgemeine Regel.
                        Erste Übereinstimmung gewinnt; eine manuell gesetzte Einordnung bleibt erhalten.
                    </p>

                    @if($canManage)
                        <x-asset-manager-textarea rows="8" wire:model="holderRulesJson"
                            class="font-mono text-xs"
                            placeholder="{{ $holderTypeExample }}" />

                        @if($holderRulesError)
                            <p class="text-xs text-red-700 m-0">{{ $holderRulesError }}</p>
                        @endif

                        <div class="flex items-center gap-2">
                            <x-asset-manager-button variant="primary" size="sm" type="button" wire:click="saveHolderRules">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                Regeln speichern
                            </x-asset-manager-button>
                            <span class="text-[10px] text-[var(--am-text-muted)]">
                                Feld: upn · email · display_name — Vergleich: prefix · suffix · contains · equals
                            </span>
                        </div>
                    @else
                        <pre class="p-3 text-xs font-mono rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] overflow-x-auto">{{ $holderRulesJson ?: '— keine Regeln — alle Träger gelten als Person —' }}</pre>
                    @endif
                </div>
            </x-asset-manager-panel>

            {{-- Sammel-Kostenstellen der Lizenz-Verteilung (ADR 0019) --}}
            <x-asset-manager-panel title="Lizenz-Verteilung: Sammel-Kostenstellen">
                <div class="space-y-4">
                    <p class="text-xs text-[var(--am-text-secondary)] m-0">
                        Bezahlte Lizenzmengen, die keinem Träger gehören, werden hierauf gebucht statt auf
                        willkürlich gewählte Personen. Sind die Kostenstellen nicht gesetzt, erscheinen die
                        Beträge in der Kostenaufteilung unter „ohne Kostenstelle" — sie verschwinden nicht.
                    </p>

                    @if($canManage)
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-[var(--am-text-secondary)] mb-1">
                                    Überhang Monatslizenzen
                                </label>
                                <x-asset-manager-select wire:model="licenseOverflowCostCenterId">
                                    <option value="">— nicht gesetzt —</option>
                                    @foreach($costCenters as $costCenter)
                                        <option value="{{ $costCenter->id }}">{{ $costCenter->code }} — {{ $costCenter->name }}</option>
                                    @endforeach
                                </x-asset-manager-select>
                                <p class="text-[11px] text-[var(--am-text-muted)] mt-1 m-0">
                                    Monats-Seats über dem Bedarf an Funktionskonten. Ein gefüllter Betrag heißt:
                                    auf Jahresbindung umstellbar und damit günstiger.
                                </p>
                            </div>

                            <div>
                                <label class="block text-xs text-[var(--am-text-secondary)] mb-1">
                                    Lizenzpool (unbesetzt)
                                </label>
                                <x-asset-manager-select wire:model="licensePoolCostCenterId">
                                    <option value="">— nicht gesetzt —</option>
                                    @foreach($costCenters as $costCenter)
                                        <option value="{{ $costCenter->id }}">{{ $costCenter->code }} — {{ $costCenter->name }}</option>
                                    @endforeach
                                </x-asset-manager-select>
                                <p class="text-[11px] text-[var(--am-text-muted)] mt-1 m-0">
                                    Bezahlt, aber niemandem zugewiesen. Mit Kündigungsdatum an der Vertragszeile
                                    wird daraus eine belastbare Kündigungsentscheidung.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <x-asset-manager-button variant="primary" size="sm" wire:click="saveLicenseCostCenters">
                                Speichern
                            </x-asset-manager-button>
                            @if($licenseCostCenterFlash)
                                <span class="text-xs text-emerald-700">{{ $licenseCostCenterFlash }}</span>
                            @endif
                        </div>
                    @else
                        <p class="text-xs text-[var(--am-text-muted)] m-0">
                            Ändern ist Owner/Admin vorbehalten.
                        </p>
                    @endif
                </div>
            </x-asset-manager-panel>

            {{-- Gefahrenzone — kompletter Team-Reset --}}
            <x-asset-manager-panel title="Gefahrenzone">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <p class="text-xs text-[var(--am-text-secondary)]">
                            Setzt das Modul für dieses Team komplett auf null: löscht <strong>alle</strong> Einträge
                            (Inventar &amp; Geräte, Asset-Träger, Zuordnungen, Ausgaben, Kostenzeilen, Lizenzen, Verlauf
                            und selbst gepflegte Stammdaten). <strong>Erhalten bleiben</strong> die Intune-Anbindung
                            und die Controlling-Einstellung — die Azure-Verbindung muss also nicht neu eingerichtet
                            werden, und der nächste Sync holt die Geräte automatisch zurück.
                            <br><strong class="text-red-700">Betrifft ALLE Tenants dieses Teams</strong>, nicht nur den
                            gerade gewählten — der Umschalter in der Seitenleiste schränkt diese Aktion nicht ein.
                        </p>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-red-600">
                            @svg('heroicon-o-exclamation-triangle', 'w-4 h-4')
                            Diese Aktion kann nicht rückgängig gemacht werden.
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        @if($canManage)
                            <x-asset-manager-button variant="danger" size="md" type="button" wire:click="openReset">
                                @svg('heroicon-o-trash', 'w-4 h-4')
                                Zurücksetzen
                            </x-asset-manager-button>
                        @else
                            <span class="text-xs text-[var(--am-text-muted)]">Nur Owner/Admin</span>
                        @endif
                    </div>
                </div>
            </x-asset-manager-panel>

        </div>
    </x-ui-page-container>

    @if($canManage)
        @include('asset-manager::livewire.settings.partials.modal-reset')
    @endif
</x-ui-page>
