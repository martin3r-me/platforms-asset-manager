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
                <div class="rounded-lg bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Controlling-Schalter --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-6">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent"></div>
                <div class="flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Controlling / Kostenaufteilung</h2>
                        <p class="mt-1 text-xs text-[color:var(--ui-secondary)]">
                            Blendet die Kosten-Schicht ein bzw. aus: Auswertungen (Kostenaufteilung, Kostenpositionen, Kosten je Mitarbeiter, Geräte nach Modell), Stammdaten und den Kosten-Import. Bei deaktiviertem Controlling bleibt das Geräte-/Asset-Inventar voll funktionsfähig; vorhandene Kostendaten bleiben erhalten.
                        </p>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium {{ $controllingEnabled ? 'text-emerald-700 dark:text-emerald-400' : 'text-[color:var(--ui-secondary)]' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $controllingEnabled ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                            {{ $controllingEnabled ? 'Aktiviert' : 'Deaktiviert' }}
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        @if($canManage)
                            @if($controllingEnabled)
                                <x-ui-button variant="secondary-ghost" size="md" rounded="lg" type="button" wire:click="toggleControlling">
                                    @svg('heroicon-o-eye-slash', 'w-4 h-4')
                                    Deaktivieren
                                </x-ui-button>
                            @else
                                <x-ui-button variant="primary" size="md" rounded="lg" type="button" wire:click="toggleControlling">
                                    @svg('heroicon-o-eye', 'w-4 h-4')
                                    Aktivieren
                                </x-ui-button>
                            @endif
                        @else
                            <span class="text-xs text-[color:var(--ui-secondary)]">Nur Owner/Admin</span>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
