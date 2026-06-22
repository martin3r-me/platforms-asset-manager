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
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Blendet die Kosten-Schicht ein bzw. aus: Auswertungen (Kostenaufteilung, Kostenpositionen, Kosten je Mitarbeiter, Geräte nach Modell), Stammdaten und den Kosten-Import. Bei deaktiviertem Controlling bleibt das Geräte-/Asset-Inventar voll funktionsfähig; vorhandene Kostendaten bleiben erhalten.
                        </p>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium {{ $controllingEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $controllingEnabled ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                            {{ $controllingEnabled ? 'Aktiviert' : 'Deaktiviert' }}
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        @if($canManage)
                            <button type="button" wire:click="toggleControlling"
                                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-all shadow-sm
                                {{ $controllingEnabled
                                    ? 'text-violet-600 dark:text-violet-400 bg-violet-500/10 hover:bg-violet-500/20'
                                    : 'text-white bg-gradient-to-br from-violet-500 to-indigo-600 hover:from-violet-600 hover:to-indigo-700' }}">
                                @svg($controllingEnabled ? 'heroicon-o-eye-slash' : 'heroicon-o-eye', 'w-4 h-4')
                                {{ $controllingEnabled ? 'Deaktivieren' : 'Aktivieren' }}
                            </button>
                        @else
                            <span class="text-xs text-gray-400">Nur Owner/Admin</span>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
