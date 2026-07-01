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
                            Blendet die Kosten-Schicht ein bzw. aus: Auswertungen (Kostenaufteilung, Kostenpositionen, Kosten je Mitarbeiter, Geräte nach Modell), Stammdaten und den Kosten-Import. Bei deaktiviertem Controlling bleibt das Geräte-/Asset-Inventar voll funktionsfähig; vorhandene Kostendaten bleiben erhalten.
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

            {{-- Gefahrenzone — kompletter Team-Reset --}}
            <x-asset-manager-panel title="Gefahrenzone">
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <p class="text-xs text-[var(--am-text-secondary)]">
                            Setzt das Modul für dieses Team komplett auf null: löscht <strong>alle</strong> Einträge
                            (Inventar &amp; Geräte, Mitarbeiter, Zuordnungen, Ausgaben, Kostenzeilen, Lizenzen, Verlauf
                            und selbst gepflegte Stammdaten). <strong>Erhalten bleiben</strong> die Intune-Anbindung
                            und die Controlling-Einstellung — die Azure-Verbindung muss also nicht neu eingerichtet
                            werden, und der nächste Sync holt die Geräte automatisch zurück.
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
