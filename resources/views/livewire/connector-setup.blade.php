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

            {{-- Gespeichert-Feedback --}}
            @if($saved)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                    <p class="text-sm text-emerald-700 dark:text-emerald-400">Einstellungen gespeichert.</p>
                </div>
            @endif

            {{-- Sync-Status und Aktionen (immer sichtbar wenn Config existiert) --}}
            @if($config)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Sync-Status</h2>
                    </div>
                    <div class="p-5 space-y-4">

                        @if($config->isConfigured())
                            {{-- Aktueller Sync-Status --}}
                            <div class="flex items-start gap-3">
                                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-1.5
                                    {{ $config->sync_status === 'success' ? 'bg-emerald-500' : '' }}
                                    {{ $config->sync_status === 'error'   ? 'bg-red-500'     : '' }}
                                    {{ $config->sync_status === 'running' ? 'bg-amber-500 animate-pulse' : '' }}
                                    {{ $config->sync_status === 'idle'    ? 'bg-gray-400'    : '' }}">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        @if($config->sync_status === 'success')     Letzter Sync erfolgreich
                                        @elseif($config->sync_status === 'error')   Letzter Sync fehlgeschlagen
                                        @elseif($config->sync_status === 'running') Sync läuft...
                                        @else                                       Noch kein Sync durchgeführt
                                        @endif
                                    </div>
                                    @if($config->last_sync_at)
                                        <div class="text-xs text-gray-400 mt-0.5">{{ $config->last_sync_at->diffForHumans() }}</div>
                                    @endif
                                    @if($config->sync_status === 'error' && $config->sync_error)
                                        <div class="text-xs text-red-500 mt-1.5 leading-relaxed">{{ $config->sync_error }}</div>
                                        @if(str_contains($config->sync_error, '403') || str_contains($config->sync_error, 'Berechtigung') || str_contains($config->sync_error, 'DeviceManagement'))
                                            <div class="mt-2 text-xs text-amber-600 dark:text-amber-400 bg-amber-500/10 rounded-lg px-3 py-2">
                                                Stelle sicher, dass in der Azure App-Registration die Permission
                                                <strong>DeviceManagementManagedDevices.Read.All</strong> (Application, nicht Delegated)
                                                hinzugefügt und <strong>Admin-Consent</strong> erteilt wurde.
                                            </div>
                                        @elseif(str_contains($config->sync_error, 'Token') || str_contains($config->sync_error, 'token'))
                                            <div class="mt-2 text-xs text-amber-600 dark:text-amber-400 bg-amber-500/10 rounded-lg px-3 py-2">
                                                Prüfe Client ID, Tenant ID und Secret. Das Secret könnte abgelaufen sein.
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            {{-- Aktions-Buttons --}}
                            @if($config->sync_status !== 'running')
                                <div class="flex flex-wrap items-center gap-3 pt-1">
                                    <button wire:click="syncNow"
                                        wire:loading.attr="disabled"
                                        wire:target="syncNow"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm disabled:opacity-60">
                                        <span wire:loading.remove wire:target="syncNow">
                                            @svg('heroicon-o-arrow-path', 'w-4 h-4')
                                            Jetzt synchronisieren
                                        </span>
                                        <span wire:loading wire:target="syncNow" class="flex items-center gap-2">
                                            @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                                            Starte Sync...
                                        </span>
                                    </button>

                                    <button wire:click="testConnection"
                                        wire:loading.attr="disabled"
                                        wire:target="testConnection"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                                        <span wire:loading.remove wire:target="testConnection">
                                            @svg('heroicon-o-signal', 'w-4 h-4')
                                            Verbindung testen
                                        </span>
                                        <span wire:loading wire:target="testConnection" class="flex items-center gap-2">
                                            @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                                            Teste...
                                        </span>
                                    </button>

                                    <button wire:click="refreshToken"
                                        wire:loading.attr="disabled"
                                        wire:target="refreshToken"
                                        title="Cache leeren — nötig nach dem Hinzufügen neuer Azure Permissions"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                                        <span wire:loading.remove wire:target="refreshToken">
                                            @svg('heroicon-o-key', 'w-4 h-4')
                                            Token erneuern
                                        </span>
                                        <span wire:loading wire:target="refreshToken" class="flex items-center gap-2">
                                            @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                                            Erneuere...
                                        </span>
                                    </button>

                                    <button wire:click="importTenantUsers"
                                        wire:loading.attr="disabled"
                                        wire:target="importTenantUsers"
                                        title="Importiert alle Tenant-User als Employees (für komplettes Mitarbeiter-Verzeichnis)"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                                        <span wire:loading.remove wire:target="importTenantUsers">
                                            @svg('heroicon-o-user-plus', 'w-4 h-4')
                                            Alle Tenant-User importieren
                                        </span>
                                        <span wire:loading wire:target="importTenantUsers" class="flex items-center gap-2">
                                            @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                                            Importiere...
                                        </span>
                                    </button>

                                    <a href="{{ route('asset-manager.devices.index') }}" wire:navigate
                                       class="text-sm text-violet-600 dark:text-violet-400 hover:underline">
                                        Geräte ansehen →
                                    </a>
                                </div>
                            @endif

                        @else
                            {{-- Konfiguration unvollständig --}}
                            <div class="flex items-start gap-3">
                                @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5')
                                <div>
                                    <p class="text-sm font-medium text-amber-700 dark:text-amber-400">Konfiguration unvollständig</p>
                                    <p class="text-xs text-amber-600/80 dark:text-amber-400/70 mt-0.5">
                                        Für den Sync werden <strong>Client ID</strong>, <strong>Tenant ID</strong> und <strong>Secret</strong> benötigt.
                                    </p>
                                    <ul class="mt-1.5 space-y-0.5 text-xs text-amber-600/80 dark:text-amber-400/70">
                                        @if(empty($config->client_id))     <li>→ Client ID fehlt</li> @endif
                                        @if(empty($config->tenant_id))     <li>→ Tenant ID fehlt</li> @endif
                                        @if(empty($config->client_secret)) <li>→ Secret fehlt</li> @endif
                                    </ul>
                                </div>
                            </div>
                        @endif

                    </div>
                </div>
            @endif

            {{-- Test-Ergebnis --}}
            @if($testResult)
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl {{ $testSuccess ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-red-500/10 border border-red-500/20' }}">
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

            {{-- Anleitung --}}
            <div class="rounded-xl bg-blue-500/5 border border-blue-500/20 p-4 space-y-2">
                <div class="flex items-center gap-2 text-sm font-medium text-blue-700 dark:text-blue-400">
                    @svg('heroicon-o-information-circle', 'w-4 h-4')
                    <span>Voraussetzungen in Azure</span>
                </div>
                <ol class="text-xs text-blue-700/80 dark:text-blue-400/80 space-y-1 list-decimal list-inside ml-1">
                    <li>App-Registration in Azure AD anlegen</li>
                    <li>
                        Application-Permissions hinzufügen (nicht Delegated!):
                        <ul class="list-disc list-inside ml-4 mt-1 space-y-0.5">
                            <li><strong>DeviceManagementManagedDevices.Read.All</strong> — Intune-Geräte</li>
                            <li><strong>Organization.Read.All</strong> — Lizenz-SKUs</li>
                            <li><strong>User.Read.All</strong> — Lizenz-Zuweisungen pro User</li>
                        </ul>
                    </li>
                    <li><strong>Admin-Consent</strong> für ALLE Permissions erteilen</li>
                    <li>Client-Secret erstellen und Key ID + Secret Value notieren</li>
                    <li>Nach Permission-Änderungen: <strong>"Token erneuern"</strong> klicken (der gecachte Token enthält sonst die alten Scopes)</li>
                </ol>
            </div>

            {{-- Formular --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Anmeldedaten</h2>
                    <p class="text-xs text-gray-400">Verschlüsselt gespeichert</p>
                </div>

                <form wire:submit="save" class="p-5 space-y-4">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                Client ID
                                <span class="text-red-400 ml-0.5">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="clientId"
                                placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all"
                            />
                            @error('clientId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                Tenant ID
                                <span class="text-red-400 ml-0.5">*</span>
                            </label>
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
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Secret
                            <span class="text-red-400 ml-0.5">*</span>
                            @if($config && $config->isConfigured())
                                <span class="font-normal text-gray-400 ml-1">(leer lassen = unverändert)</span>
                            @endif
                        </label>
                        <div class="relative" x-data="{ show: false }">
                            <input
                                :type="show ? 'text' : 'password'"
                                wire:model="clientSecret"
                                placeholder="{{ ($config && $config->isConfigured()) ? '••••••••••••• (unverändert)' : 'Secret Value aus Azure' }}"
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
                    <div class="flex items-center gap-3 pt-2 border-t border-black/5 dark:border-white/5">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="save">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                Speichern
                            </span>
                            <span wire:loading wire:target="save" class="flex items-center gap-2">
                                @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                                Speichern...
                            </span>
                        </button>

                        @if($config && $config->isConfigured())
                            <button
                                type="button"
                                wire:click="testConnection"
                                wire:loading.attr="disabled"
                                wire:target="testConnection"
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

                </form>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
