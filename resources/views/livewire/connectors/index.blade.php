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
                <x-asset-manager-panel title="Tenants" body-class="p-0">
                    <x-slot name="actions">
                        <x-asset-manager-button variant="primary" size="sm" wire:click="editCreate">
                            @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                            Tenant
                        </x-asset-manager-button>
                    </x-slot>

                    <div class="divide-y divide-[color:var(--am-border)]">
                        @forelse($tenants as $t)
                            @php($conn = $t->connector)
                            @php($status = $conn?->connectionStatus())
                            <button wire:click="selectTenant({{ $t->id }})"
                                class="w-full text-left px-4 py-3 flex items-center gap-3 transition-colors
                                    {{ $selectedTenant && $selectedTenant->id === $t->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : 'hover:bg-[var(--am-bg)]' }}">
                                <span class="w-2 h-2 rounded-full flex-shrink-0
                                    {{ $status === 'active'       ? 'bg-emerald-500' : '' }}
                                    {{ $status === 'pending'      ? 'bg-amber-500'   : '' }}
                                    {{ $status === 'disconnected' ? 'bg-gray-400'    : '' }}
                                    {{ $status === 'incomplete'   ? 'bg-red-400'     : '' }}
                                    {{ $status === null           ? 'bg-gray-300' : '' }}">
                                </span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm font-medium text-[var(--am-text)] truncate">{{ $t->name }}</span>
                                    <span class="block text-xs text-[var(--am-text-secondary)]">
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
                                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-md bg-[var(--am-bg)] text-[var(--am-text-muted)]">Standard</span>
                                @endif
                            </button>
                        @empty
                            <div class="px-4 py-6 text-center text-sm text-[var(--am-text-secondary)]">
                                Noch keine Tenants.<br>Lege oben einen an.
                            </div>
                        @endforelse
                    </div>
                </x-asset-manager-panel>
            </div>

            {{-- ============ RECHTS: Detail ============ --}}
            <div class="lg:col-span-8 xl:col-span-9 space-y-6">

                {{-- Tenant anlegen/umbenennen (Inline-Editor) --}}
                @if($editingTenant)
                    <x-asset-manager-panel title="{{ $renameMode ? 'Tenant umbenennen' : 'Neuen Tenant anlegen' }}">
                        <form wire:submit="saveTenant" class="flex flex-wrap items-end gap-3">
                            <div class="flex-1 min-w-[200px]">
                                <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Name</label>
                                <x-asset-manager-input size="md" type="text" wire:model="tenantName" placeholder="z. B. Kunde GmbH" />
                                @error('tenantName') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <x-asset-manager-button variant="primary" size="md" type="submit">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                Speichern
                            </x-asset-manager-button>
                            <x-asset-manager-button variant="ghost" size="md" type="button" wire:click="cancelTenantEdit">
                                Abbrechen
                            </x-asset-manager-button>
                        </form>
                    </x-asset-manager-panel>
                @endif

                {{-- Flash --}}
                @if($flash)
                    <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                        @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-600 flex-shrink-0')
                        <p class="text-sm text-emerald-700">{{ $flash }}</p>
                    </div>
                @endif

                @if(! $selectedTenant && ! $editingTenant)
                    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-10 text-center">
                        @svg('heroicon-o-building-office-2', 'w-10 h-10 mx-auto text-[var(--am-text-muted)]')
                        <p class="mt-3 text-sm text-[var(--am-text-secondary)]">Wähle links einen Tenant oder lege einen neuen an.</p>
                    </div>
                @endif

                @if($selectedTenant)
                    {{-- Tenant-Kopf --}}
                    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                        <div class="px-5 py-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h1 class="text-lg font-semibold text-[var(--am-text)] truncate">{{ $selectedTenant->name }}</h1>
                                    @if($selectedTenant->is_default)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-md bg-[var(--am-bg)] text-[var(--am-text-muted)]">Standard</span>
                                    @endif
                                </div>
                                <p class="text-xs text-[var(--am-text-secondary)] mt-0.5">Kundenkontext · Inventar bezieht sich auf diesen Tenant</p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <x-asset-manager-button variant="ghost" size="sm" wire:click="editRename">
                                    @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                                    Umbenennen
                                </x-asset-manager-button>
                                @unless($selectedTenant->is_default)
                                    <x-asset-manager-button variant="ghost" size="sm" wire:click="setDefaultTenant({{ $selectedTenant->id }})">
                                        @svg('heroicon-o-star', 'w-3.5 h-3.5')
                                        Als Standard
                                    </x-asset-manager-button>
                                @endunless
                                <x-asset-manager-button variant="danger" size="sm" wire:click="$set('confirmingTenantDelete', true)">
                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                    Löschen
                                </x-asset-manager-button>
                            </div>
                        </div>

                        {{-- Lösch-Bestätigung --}}
                        @if($confirmingTenantDelete)
                            <div class="px-5 py-4 border-t border-red-200 bg-red-50">
                                <div class="flex items-start gap-3">
                                    @svg('heroicon-o-exclamation-triangle', 'w-5 h-5 text-red-600 flex-shrink-0 mt-0.5')
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-red-700">Tenant „{{ $selectedTenant->name }}" wirklich löschen?</p>
                                        <p class="text-xs text-red-700 mt-1">
                                            <strong>Alle</strong> Geräte, Lizenzen, Mitarbeiter, Assets und der Connector dieses Tenants
                                            werden unwiderruflich entfernt (Cascade). Kostenstellen/Kostenarten bleiben (team-weit).
                                        </p>
                                        <div class="flex items-center gap-2 mt-3">
                                            <x-asset-manager-button variant="danger" size="sm" wire:click="deleteTenant">
                                                @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                                Endgültig löschen
                                            </x-asset-manager-button>
                                            <x-asset-manager-button variant="ghost" size="sm" wire:click="$set('confirmingTenantDelete', false)">
                                                Abbrechen
                                            </x-asset-manager-button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Zentrale-App-Hinweis --}}
                    @if(! $centralAppConfigured)
                        <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200">
                            @svg('heroicon-o-information-circle', 'w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5')
                            <p class="text-sm text-amber-700">
                                Die zentrale Azure-App ist noch nicht hinterlegt (<code>ASSET_MANAGER_AZURE_CLIENT_ID</code>).
                                Neue Connectoren können erst nach Eintrag der App-Zugangsdaten Tokens holen — oder du hinterlegst
                                pro Connector eigene App-Credentials (Erweitert).
                            </p>
                        </div>
                    @endif

                    {{-- Connector-Sektion --}}
                    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-[color:var(--am-border)] flex items-center justify-between">
                            <h2 class="text-sm font-medium text-[var(--am-text)]">Microsoft-Anbindung</h2>
                            @if($connector)
                                @php($status = $connector->connectionStatus())
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-1 rounded-lg
                                    {{ $status === 'active'       ? 'bg-emerald-50 text-emerald-700' : '' }}
                                    {{ $status === 'pending'      ? 'bg-amber-50 text-amber-700'       : '' }}
                                    {{ $status === 'disconnected' ? 'bg-[var(--am-bg)] text-[var(--am-text-secondary)]'          : '' }}
                                    {{ $status === 'incomplete'   ? 'bg-red-50 text-red-700'             : '' }}">
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
                                <div class="flex items-start gap-3 px-4 py-3 rounded-xl {{ $testSuccess ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200' }}">
                                    @if($testSuccess)
                                        @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5')
                                    @else
                                        @svg('heroicon-o-exclamation-circle', 'w-4 h-4 text-red-600 flex-shrink-0 mt-0.5')
                                    @endif
                                    <p class="text-sm {{ $testSuccess ? 'text-emerald-700' : 'text-red-700' }}">{{ $testResult }}</p>
                                </div>
                            @endif

                            @if($connector && $connector->last_sync_at)
                                <p class="text-xs text-[var(--am-text-secondary)]">Letzter Sync: {{ $connector->last_sync_at->diffForHumans() }}
                                    @if($connector->sync_error) · <span class="text-red-700">{{ $connector->sync_error }}</span> @endif
                                </p>
                            @endif

                            {{-- Consent-Link (bei vorhandenem Verzeichnis) --}}
                            @if($consentUrl)
                                <div class="rounded-lg bg-sky-50 border border-sky-200 p-4 space-y-2" x-data="{ copied: false, url: @js($consentUrl) }">
                                    <div class="flex items-center gap-2 text-sm font-medium text-sky-700">
                                        @svg('heroicon-o-link', 'w-4 h-4')
                                        <span>Admin-Consent-Link</span>
                                    </div>
                                    <p class="text-xs text-sky-700">
                                        Diesen Link an einen <strong>Admin des Kunden-Tenants</strong> schicken. Nach der Zustimmung
                                        unten <strong>„Anbindung prüfen"</strong> klicken.
                                    </p>
                                    <div class="flex items-center gap-2">
                                        <input type="text" readonly :value="url"
                                            class="flex-1 px-3 py-2 text-xs rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] text-[var(--am-text-secondary)] font-mono truncate" />
                                        <x-asset-manager-button variant="ghost" size="sm" type="button" class="flex-shrink-0"
                                            x-on:click="navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-show="!copied">Kopieren</span>
                                            <span x-show="copied" x-cloak class="text-emerald-700">Kopiert ✓</span>
                                        </x-asset-manager-button>
                                    </div>
                                </div>
                            @endif

                            {{-- Aktions-Buttons (bei vorhandenem Connector) --}}
                            @if($connector)
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <x-asset-manager-button variant="primary" size="md" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                                        <span wire:loading.remove wire:target="checkConnection" class="flex items-center gap-2">@svg('heroicon-o-signal', 'w-4 h-4') Anbindung prüfen</span>
                                        <span wire:loading wire:target="checkConnection" class="flex items-center gap-2">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Prüfe...</span>
                                    </x-asset-manager-button>

                                    @if($connector->connectionStatus() === 'active')
                                        <x-asset-manager-button variant="ghost" size="md" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow">
                                            <span wire:loading.remove wire:target="syncNow" class="flex items-center gap-2">@svg('heroicon-o-arrow-path', 'w-4 h-4') Jetzt synchronisieren</span>
                                            <span wire:loading wire:target="syncNow" class="flex items-center gap-2">@svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Starte...</span>
                                        </x-asset-manager-button>

                                        <x-asset-manager-button variant="ghost" size="md" wire:click="importUsers" wire:loading.attr="disabled" wire:target="importUsers">
                                            @svg('heroicon-o-user-plus', 'w-4 h-4') Tenant-User importieren
                                        </x-asset-manager-button>
                                    @endif

                                    <x-asset-manager-button variant="ghost" size="md" wire:click="refreshToken"
                                        title="Token-Cache leeren — nötig nach Permission-Änderungen in Azure">
                                        @svg('heroicon-o-key', 'w-4 h-4') Token erneuern
                                    </x-asset-manager-button>

                                    @if($connector->enabled)
                                        <x-asset-manager-button variant="ghost" size="md" wire:click="disconnect">
                                            @svg('heroicon-o-no-symbol', 'w-4 h-4') Trennen
                                        </x-asset-manager-button>
                                    @else
                                        <button wire:click="reconnect"
                                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-emerald-700 bg-emerald-50 rounded-lg hover:bg-emerald-100 transition-colors">
                                            @svg('heroicon-o-bolt', 'w-4 h-4') Wieder verbinden
                                        </button>
                                    @endif
                                </div>
                            @endif

                            {{-- Formular: Verzeichnis (+ optional eigene App) --}}
                            <form wire:submit="{{ $connector ? 'saveConnector' : 'addConnector' }}" class="space-y-4 pt-2 border-t border-[color:var(--am-border)]"
                                  x-data="{ advanced: {{ $connector && $connector->client_id ? 'true' : 'false' }} }">
                                <div>
                                    <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">
                                        Kunden-Verzeichnis <span class="text-red-700">*</span>
                                        <span class="font-normal text-[var(--am-text-muted)] ml-1">(Domain wie <code>kunde.de</code> oder Tenant-GUID)</span>
                                    </label>
                                    <x-asset-manager-input size="md" type="text" wire:model="directory" placeholder="kunde.de  ·  xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                    @error('directory') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                                </div>

                                <button type="button" @click="advanced = !advanced"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-[var(--am-text-secondary)] hover:text-[var(--am-text)] transition-colors">
                                    @svg('heroicon-o-chevron-right', 'w-3.5 h-3.5', ['x-bind:class' => "advanced ? 'rotate-90' : ''", 'class' => 'transition-transform'])
                                    Erweitert: eigene App-Credentials (optional, sonst zentrale App)
                                </button>

                                <div x-show="advanced" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Client ID <span class="font-normal text-[var(--am-text-muted)]">(optional)</span></label>
                                        <x-asset-manager-input size="md" type="text" wire:model="clientId" placeholder="leer = zentrale App" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">
                                            Client Secret <span class="font-normal text-[var(--am-text-muted)]">(optional)</span>
                                            @if($connector && $connector->client_secret)<span class="text-[var(--am-text-muted)] ml-1">(leer = unverändert)</span>@endif
                                        </label>
                                        <x-asset-manager-input size="md" type="password" wire:model="clientSecret" placeholder="{{ $connector && $connector->client_secret ? '••••••••• (unverändert)' : 'leer = zentrale App' }}" />
                                    </div>
                                </div>

                                <div class="flex items-center gap-3">
                                    <x-asset-manager-button variant="primary" size="md" type="submit">
                                        @svg('heroicon-o-check', 'w-4 h-4')
                                        {{ $connector ? 'Speichern' : 'Connector hinzufügen' }}
                                    </x-asset-manager-button>
                                </div>
                            </form>

                        </div>
                    </div>
                @endif

            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
