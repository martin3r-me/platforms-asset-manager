<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Connector', 'icon' => 'wrench-screwdriver'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="max-w-2xl mx-auto space-y-6">

            {{-- Header --}}
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Microsoft Intune Connector</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Verbinde den Asset Manager mit deiner Azure App-Registration, um Intune-Gerätedaten zu synchronisieren.
                </p>
            </div>

            {{-- Status-Banner wenn konfiguriert --}}
            @if($config && $config->isConfigured())
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl
                    {{ $config->sync_status === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20' : '' }}
                    {{ $config->sync_status === 'error' ? 'bg-red-500/10 border border-red-500/20' : '' }}
                    {{ in_array($config->sync_status, ['idle', 'running']) ? 'bg-gray-500/10 border border-gray-500/20' : '' }}">
                    <div class="w-2 h-2 rounded-full flex-shrink-0
                        {{ $config->sync_status === 'success' ? 'bg-emerald-500' : '' }}
                        {{ $config->sync_status === 'error' ? 'bg-red-500' : '' }}
                        {{ $config->sync_status === 'running' ? 'bg-amber-500 animate-pulse' : '' }}
                        {{ $config->sync_status === 'idle' ? 'bg-gray-400' : '' }}">
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-sm font-medium
                            {{ $config->sync_status === 'success' ? 'text-emerald-700 dark:text-emerald-400' : '' }}
                            {{ $config->sync_status === 'error' ? 'text-red-700 dark:text-red-400' : '' }}
                            {{ in_array($config->sync_status, ['idle', 'running']) ? 'text-gray-600 dark:text-gray-400' : '' }}">
                            @if($config->sync_status === 'success')
                                Letzter Sync erfolgreich
                                @if($config->last_sync_at) — {{ $config->last_sync_at->diffForHumans() }} @endif
                            @elseif($config->sync_status === 'error')
                                Sync-Fehler: {{ Str::limit($config->sync_error, 100) }}
                            @elseif($config->sync_status === 'running')
                                Sync läuft gerade...
                            @else
                                Connector konfiguriert — noch kein Sync durchgeführt
                            @endif
                        </span>
                    </div>
                    @if($config->sync_status !== 'running')
                        <button wire:click="syncNow" class="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                            Jetzt synchronisieren
                        </button>
                    @endif
                </div>
            @endif

            {{-- Anleitung --}}
            <div class="rounded-xl bg-blue-500/5 border border-blue-500/20 p-4 space-y-2">
                <div class="flex items-center gap-2 text-sm font-medium text-blue-700 dark:text-blue-400">
                    @svg('heroicon-o-information-circle', 'w-4 h-4')
                    <span>Voraussetzungen in Azure</span>
                </div>
                <ol class="text-xs text-blue-700/80 dark:text-blue-400/80 space-y-1 list-decimal list-inside ml-1">
                    <li>App-Registration in Azure AD anlegen</li>
                    <li>API-Permission hinzufügen: <strong>DeviceManagementManagedDevices.Read.All</strong> (Application)</li>
                    <li>Admin-Consent für die Permission erteilen</li>
                    <li>Client-Secret erstellen und Key ID + Secret Value notieren</li>
                </ol>
            </div>

            {{-- Formular --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Anmeldedaten</h2>
                    <p class="text-xs text-gray-400">Für den Import der Daten wird ein API-Benutzer mit Leseberechtigungen benötigt.</p>
                </div>

                <form wire:submit="save" class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Client ID</label>
                        <input
                            type="text"
                            wire:model="clientId"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all"
                        />
                        @error('clientId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Tenant ID</label>
                        <input
                            type="text"
                            wire:model="tenantId"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all"
                        />
                        @error('tenantId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Objekt ID
                            <span class="font-normal text-gray-400">(optional)</span>
                        </label>
                        <input
                            type="text"
                            wire:model="objectId"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Geheimer Schlüssel ID
                            <span class="font-normal text-gray-400">(Key ID)</span>
                        </label>
                        <input
                            type="text"
                            wire:model="keyId"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Secret</label>
                        <div class="relative" x-data="{ show: false }">
                            <input
                                :type="show ? 'text' : 'password'"
                                wire:model="clientSecret"
                                placeholder="{{ $config && $config->isConfigured() ? '••••••••••••• (unverändert)' : 'Secret Value aus Azure' }}"
                                class="w-full px-3 py-2 pr-10 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all"
                            />
                            <button
                                type="button"
                                @click="show = !show"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                            >
                                @svg('heroicon-o-eye', 'w-4 h-4', ['x-show' => '!show'])
                                @svg('heroicon-o-eye-slash', 'w-4 h-4', ['x-show' => 'show', 'style' => 'display:none'])
                            </button>
                        </div>
                        @error('clientSecret') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Aktionen --}}
                    <div class="flex items-center gap-3 pt-2">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm"
                        >
                            @svg('heroicon-o-check', 'w-4 h-4')
                            Speichern
                        </button>

                        @if($config && $config->isConfigured())
                            <button
                                type="button"
                                wire:click="testConnection"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] dark:hover:bg-white/[0.09] transition-all"
                            >
                                <span wire:loading.remove wire:target="testConnection">
                                    @svg('heroicon-o-signal', 'w-4 h-4')
                                    Verbindung testen
                                </span>
                                <span wire:loading wire:target="testConnection" class="flex items-center gap-2">
                                    @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                                    Teste...
                                </span>
                            </button>
                        @endif
                    </div>

                    {{-- Test-Ergebnis --}}
                    @if($testResult)
                        <div class="flex items-start gap-3 px-4 py-3 rounded-lg {{ $testSuccess ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-red-500/10 border border-red-500/20' }}">
                            @if($testSuccess)
                                @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5')
                            @else
                                @svg('heroicon-o-exclamation-circle', 'w-4 h-4 text-red-500 flex-shrink-0 mt-0.5')
                            @endif
                            <p class="text-sm {{ $testSuccess ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">
                                {{ $testResult }}
                            </p>
                        </div>
                    @endif

                    @if($saved)
                        <p class="text-xs text-emerald-600 dark:text-emerald-400">Einstellungen gespeichert.</p>
                    @endif
                </form>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
