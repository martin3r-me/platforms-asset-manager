<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Konnektoren', 'icon' => 'wrench-screwdriver'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            {{-- ============ LINKS: Tenant-Liste ============ --}}
            <div class="lg:col-span-4 xl:col-span-3">
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Tenants</h2>
                        <button wire:click="editCreate"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-violet-700 dark:text-violet-300 bg-violet-500/10 rounded-lg hover:bg-violet-500/20 transition-all">
                            @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                            Tenant
                        </button>
                    </div>

                    <div class="divide-y divide-black/5 dark:divide-white/5">
                        @forelse($tenants as $t)
                            @php($conn = $t->connector)
                            @php($status = $conn?->connectionStatus())
                            <button wire:click="selectTenant({{ $t->id }})"
                                class="w-full text-left px-4 py-3 flex items-center gap-3 transition-all
                                    {{ $selectedTenant && $selectedTenant->id === $t->id ? 'bg-violet-500/10' : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.04]' }}">
                                <span class="w-2 h-2 rounded-full flex-shrink-0
                                    {{ $status === 'active'       ? 'bg-emerald-500' : '' }}
                                    {{ $status === 'pending'      ? 'bg-amber-500'   : '' }}
                                    {{ $status === 'disconnected' ? 'bg-gray-400'    : '' }}
                                    {{ $status === 'incomplete'   ? 'bg-red-400'     : '' }}
                                    {{ $status === null           ? 'bg-gray-300 dark:bg-gray-600' : '' }}">
                                </span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $t->name }}</span>
                                    <span class="block text-xs text-gray-400">
                                        @if($conn)
                                            @switch($status)
                                                @case('active')       Anbindung aktiv @break
                                                @case('pending')      Consent ausstehend @break
                                                @case('disconnected') getrennt @break
                                                @default              unvollständig
                                            @endswitch
                                        @else
                                            kein Connector
                                        @endif
                                    </span>
                                </span>
                                @if($t->is_default)
                                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-black/5 dark:bg-white/10 text-gray-500 dark:text-gray-400">Standard</span>
                                @endif
                            </button>
                        @empty
                            <div class="px-4 py-6 text-center text-sm text-gray-400">
                                Noch keine Tenants.<br>Lege oben einen an.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ============ RECHTS: Detail ============ --}}
            <div class="lg:col-span-8 xl:col-span-9 space-y-6">

                {{-- Tenant anlegen/umbenennen (Inline-Editor) --}}
                @if($editingTenant)
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                        <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-3">
                            {{ $renameMode ? 'Tenant umbenennen' : 'Neuen Tenant anlegen' }}
                        </h2>
                        <form wire:submit="saveTenant" class="flex flex-wrap items-end gap-3">
                            <div class="flex-1 min-w-[200px]">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Name</label>
                                <input type="text" wire:model="tenantName" placeholder="z. B. Kunde GmbH"
                                    class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all" />
                                @error('tenantName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit"
                                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                Speichern
                            </button>
                            <button type="button" wire:click="cancelTenantEdit"
                                class="px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                                Abbrechen
                            </button>
                        </form>
                    </div>
                @endif

                {{-- Flash --}}
                @if($flash)
                    <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                        @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                        <p class="text-sm text-emerald-700 dark:text-emerald-400">{{ $flash }}</p>
                    </div>
                @endif

                @if(! $selectedTenant && ! $editingTenant)
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-10 text-center">
                        @svg('heroicon-o-building-office-2', 'w-10 h-10 mx-auto text-gray-300 dark:text-gray-600')
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Wähle links einen Tenant oder lege einen neuen an.</p>
                    </div>
                @endif

                @if($selectedTenant)
                    {{-- Tenant-Kopf --}}
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                        <div class="px-5 py-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $selectedTenant->name }}</h1>
                                    @if($selectedTenant->is_default)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-black/5 dark:bg-white/10 text-gray-500 dark:text-gray-400">Standard</span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-400 mt-0.5">Kundenkontext · Inventar bezieht sich auf diesen Tenant</p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button wire:click="editRename"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                    @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                                    Umbenennen
                                </button>
                                @unless($selectedTenant->is_default)
                                    <button wire:click="setDefaultTenant({{ $selectedTenant->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                        @svg('heroicon-o-star', 'w-3.5 h-3.5')
                                        Als Standard
                                    </button>
                                @endunless
                                <button wire:click="$set('confirmingTenantDelete', true)"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-red-600 dark:text-red-400 bg-red-500/10 rounded-lg hover:bg-red-500/20 transition-all">
                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                    Löschen
                                </button>
                            </div>
                        </div>

                        {{-- Lösch-Bestätigung --}}
                        @if($confirmingTenantDelete)
                            <div class="px-5 py-4 border-t border-red-500/20 bg-red-500/5">
                                <div class="flex items-start gap-3">
                                    @svg('heroicon-o-exclamation-triangle', 'w-5 h-5 text-red-500 flex-shrink-0 mt-0.5')
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-red-700 dark:text-red-400">Tenant „{{ $selectedTenant->name }}" wirklich löschen?</p>
                                        <p class="text-xs text-red-600/80 dark:text-red-400/70 mt-1">
                                            <strong>Alle</strong> Geräte, Lizenzen, Mitarbeiter, Assets und der Connector dieses Tenants
                                            werden unwiderruflich entfernt (Cascade). Kostenstellen/Kostenarten bleiben (team-weit).
                                        </p>
                                        <div class="flex items-center gap-2 mt-3">
                                            <button wire:click="deleteTenant"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-all">
                                                @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                                Endgültig löschen
                                            </button>
                                            <button wire:click="$set('confirmingTenantDelete', false)"
                                                class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                                Abbrechen
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Zentrale-App-Hinweis --}}
                    @if(! $centralAppConfigured)
                        <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/20">
                            @svg('heroicon-o-information-circle', 'w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5')
                            <p class="text-sm text-amber-700 dark:text-amber-400">
                                Die zentrale Azure-App ist noch nicht hinterlegt (<code>ASSET_MANAGER_AZURE_CLIENT_ID</code>).
                                Neue Connectoren können erst nach Eintrag der App-Zugangsdaten Tokens holen — oder du hinterlegst
                                pro Connector eigene App-Credentials (Erweitert).
                            </p>
                        </div>
                    @endif

                    {{-- Connector-Sektion --}}
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-black/5 dark:border-white/5 flex items-center justify-between">
                            <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Microsoft-Anbindung</h2>
                            @if($connector)
                                @php($status = $connector->connectionStatus())
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-1 rounded-lg
                                    {{ $status === 'active'       ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : '' }}
                                    {{ $status === 'pending'      ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'       : '' }}
                                    {{ $status === 'disconnected' ? 'bg-gray-500/10 text-gray-600 dark:text-gray-400'          : '' }}
                                    {{ $status === 'incomplete'   ? 'bg-red-500/10 text-red-700 dark:text-red-400'             : '' }}">
                                    @switch($status)
                                        @case('active')       @svg('heroicon-o-check-circle', 'w-3.5 h-3.5') Aktiv @break
                                        @case('pending')      @svg('heroicon-o-clock', 'w-3.5 h-3.5') Consent ausstehend @break
                                        @case('disconnected') @svg('heroicon-o-no-symbol', 'w-3.5 h-3.5') Getrennt @break
                                        @default              @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5') Unvollständig
                                    @endswitch
                                </span>
                            @endif
                        </div>

                        <div class="p-5 space-y-5">

                            {{-- Test-Ergebnis --}}
                            @if($testResult)
                                <div class="flex items-start gap-3 px-4 py-3 rounded-xl {{ $testSuccess ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-red-500/10 border border-red-500/20' }}">
                                    @if($testSuccess)
                                        @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5')
                                    @else
                                        @svg('heroicon-o-exclamation-circle', 'w-4 h-4 text-red-500 flex-shrink-0 mt-0.5')
                                    @endif
                                    <p class="text-sm {{ $testSuccess ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">{{ $testResult }}</p>
                                </div>
                            @endif

                            @if($connector && $connector->last_sync_at)
                                <p class="text-xs text-gray-400">Letzter Sync: {{ $connector->last_sync_at->diffForHumans() }}
                                    @if($connector->sync_error) · <span class="text-red-500">{{ $connector->sync_error }}</span> @endif
                                </p>
                            @endif

                            {{-- Consent-Link (bei vorhandenem Verzeichnis) --}}
                            @if($consentUrl)
                                <div class="rounded-lg bg-blue-500/5 border border-blue-500/20 p-4 space-y-2" x-data="{ copied: false, url: @js($consentUrl) }">
                                    <div class="flex items-center gap-2 text-sm font-medium text-blue-700 dark:text-blue-400">
                                        @svg('heroicon-o-link', 'w-4 h-4')
                                        <span>Admin-Consent-Link</span>
                                    </div>
                                    <p class="text-xs text-blue-700/80 dark:text-blue-400/80">
                                        Diesen Link an einen <strong>Admin des Kunden-Tenants</strong> schicken. Nach der Zustimmung
                                        unten <strong>„Anbindung prüfen"</strong> klicken.
                                    </p>
                                    <div class="flex items-center gap-2">
                                        <input type="text" readonly :value="url"
                                            class="flex-1 px-3 py-2 text-xs rounded-lg bg-white/60 dark:bg-white/5 border border-black/10 dark:border-white/10 text-gray-600 dark:text-gray-300 font-mono truncate" />
                                        <button type="button"
                                            @click="navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 1500)"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all flex-shrink-0">
                                            <span x-show="!copied">Kopieren</span>
                                            <span x-show="copied" x-cloak class="text-emerald-600 dark:text-emerald-400">Kopiert ✓</span>
                                        </button>
                                    </div>
                                </div>
                            @endif

                            {{-- Aktions-Buttons (bei vorhandenem Connector) --}}
                            @if($connector)
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <button wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm disabled:opacity-60">
                                        <span wire:loading.remove wire:target="checkConnection" class="flex items-center gap-2">@svg('heroicon-o-signal', 'w-4 h-4') Anbindung prüfen</span>
                                        <span wire:loading wire:target="checkConnection" class="flex items-center gap-2">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Prüfe...</span>
                                    </button>

                                    @if($connector->connectionStatus() === 'active')
                                        <button wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow"
                                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                            <span wire:loading.remove wire:target="syncNow" class="flex items-center gap-2">@svg('heroicon-o-arrow-path', 'w-4 h-4') Jetzt synchronisieren</span>
                                            <span wire:loading wire:target="syncNow" class="flex items-center gap-2">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Starte...</span>
                                        </button>

                                        <button wire:click="importUsers" wire:loading.attr="disabled" wire:target="importUsers"
                                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                            @svg('heroicon-o-user-plus', 'w-4 h-4') Tenant-User importieren
                                        </button>
                                    @endif

                                    <button wire:click="refreshToken"
                                        title="Token-Cache leeren — nötig nach Permission-Änderungen in Azure"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                        @svg('heroicon-o-key', 'w-4 h-4') Token erneuern
                                    </button>

                                    @if($connector->enabled)
                                        <button wire:click="disconnect"
                                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.08] transition-all">
                                            @svg('heroicon-o-no-symbol', 'w-4 h-4') Trennen
                                        </button>
                                    @else
                                        <button wire:click="reconnect"
                                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 rounded-lg hover:bg-emerald-500/20 transition-all">
                                            @svg('heroicon-o-bolt', 'w-4 h-4') Wieder verbinden
                                        </button>
                                    @endif
                                </div>
                            @endif

                            {{-- Formular: Verzeichnis (+ optional eigene App) --}}
                            <form wire:submit="{{ $connector ? 'saveConnector' : 'addConnector' }}" class="space-y-4 pt-2 border-t border-black/5 dark:border-white/5"
                                  x-data="{ advanced: {{ $connector && $connector->client_id ? 'true' : 'false' }} }">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        Kunden-Verzeichnis <span class="text-red-400">*</span>
                                        <span class="font-normal text-gray-400 ml-1">(Domain wie <code>kunde.de</code> oder Tenant-GUID)</span>
                                    </label>
                                    <input type="text" wire:model="directory" placeholder="kunde.de  ·  xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                        class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all" />
                                    @error('directory') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                <button type="button" @click="advanced = !advanced"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-all">
                                    @svg('heroicon-o-chevron-right', 'w-3.5 h-3.5', ['x-bind:class' => "advanced ? 'rotate-90' : ''", 'class' => 'transition-transform'])
                                    Erweitert: eigene App-Credentials (optional, sonst zentrale App)
                                </button>

                                <div x-show="advanced" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Client ID <span class="font-normal text-gray-400">(optional)</span></label>
                                        <input type="text" wire:model="clientId" placeholder="leer = zentrale App"
                                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                            Client Secret <span class="font-normal text-gray-400">(optional)</span>
                                            @if($connector && $connector->client_secret)<span class="text-gray-400 ml-1">(leer = unverändert)</span>@endif
                                        </label>
                                        <input type="password" wire:model="clientSecret" placeholder="{{ $connector && $connector->client_secret ? '••••••••• (unverändert)' : 'leer = zentrale App' }}"
                                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/50 transition-all" />
                                    </div>
                                </div>

                                <div class="flex items-center gap-3">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                                        @svg('heroicon-o-check', 'w-4 h-4')
                                        {{ $connector ? 'Speichern' : 'Connector hinzufügen' }}
                                    </button>
                                </div>
                            </form>

                        </div>
                    </div>
                @endif

            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
