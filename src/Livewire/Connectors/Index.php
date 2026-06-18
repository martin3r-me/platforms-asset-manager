<?php

namespace Platform\AssetManager\Livewire\Connectors;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\IntuneGraphService;

/**
 * Konnektoren-Verwaltung (Multi-Tenant, M2 — siehe docs/adr/0003):
 * links die Tenant-Liste (Kundenkontexte) mit Connector-Status, rechts das Detail des gewählten
 * Tenants inkl. seiner optionalen Microsoft-Anbindung (0..1). Eine Komponente besitzt alle Slots
 * von <x-ui-page> (Master-Detail wie MasterData/Index).
 *
 * Consent ist MANUELL: der Admin-Consent-Link wird angezeigt/verschickt; aktiviert wird der Connector
 * über „Anbindung prüfen" (Token-Test). Kein öffentlicher Callback.
 */
class Index extends Component
{
    public ?int $selectedTenantId = null;

    // Tenant anlegen/umbenennen (ein Inline-Editor, renameMode unterscheidet die beiden Fälle)
    public bool   $editingTenant = false;
    public bool   $renameMode    = false;
    public string $tenantName    = '';
    public bool   $confirmingTenantDelete = false;

    // Connector-Formular
    public string $directory    = '';   // Kunden-Verzeichnis: Domain oder Tenant-GUID → azure_tenant_id
    public string $clientId     = '';   // optional: eigene App-Credentials (Override/Legacy)
    public string $clientSecret = '';

    public ?string $flash       = null;
    public ?string $testResult  = null;
    public bool    $testSuccess = false;

    /** Request-lokaler Cache der Tenant-Liste (nicht über Roundtrips synchronisiert). */
    protected $tenantsCache = null;

    public function mount(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $tenants = $this->tenants();
        $this->selectedTenantId = $tenants->firstWhere('is_default', true)?->id
            ?? $tenants->first()?->id;

        $this->loadConnectorForm();
    }

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    // ---- Auswahl ----------------------------------------------------------

    public function selectTenant(int $id): void
    {
        $this->selectedTenantId = $id;
        $this->resetFeedback();
        $this->editingTenant = false;
        $this->confirmingTenantDelete = false;
        $this->loadConnectorForm();
    }

    protected function loadConnectorForm(): void
    {
        $connector = $this->selectedConnector();
        $this->directory    = $connector?->azure_tenant_id ?? '';
        $this->clientId     = $connector?->client_id ?? '';
        $this->clientSecret = '';
    }

    protected function resetFeedback(): void
    {
        $this->flash       = null;
        $this->testResult  = null;
        $this->testSuccess = false;
    }

    // ---- Tenant-CRUD ------------------------------------------------------

    public function editCreate(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);
        $this->renameMode    = false;
        $this->editingTenant = true;
        $this->tenantName    = '';
        $this->resetFeedback();
    }

    public function editRename(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);
        $tenant = $this->selectedTenant();
        if (! $tenant) return;
        $this->renameMode    = true;
        $this->editingTenant = true;
        $this->tenantName    = $tenant->name;
    }

    public function cancelTenantEdit(): void
    {
        $this->editingTenant = false;
        $this->tenantName    = '';
    }

    public function saveTenant(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);
        $this->validate(['tenantName' => 'required|string|max:255']);

        if ($this->renameMode && ($tenant = $this->selectedTenant())) {
            $tenant->update(['name' => $this->tenantName]);
            $this->flash = 'Tenant umbenannt.';
        } else {
            $hasAny = AssetTenant::where('team_id', $this->teamId())->exists();
            $created = AssetTenant::create([
                'team_id'    => $this->teamId(),
                'name'       => $this->tenantName,
                'is_default' => ! $hasAny, // erster Tenant des Teams = Default
            ]);
            $this->selectedTenantId = $created->id;
            $this->flash = 'Tenant angelegt.';
        }

        $this->editingTenant = false;
        $this->tenantName    = '';
        $this->refreshTenants();
        $this->loadConnectorForm();
    }

    public function setDefaultTenant(int $tenantId): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        DB::transaction(function () use ($tenantId) {
            AssetTenant::where('team_id', $this->teamId())->update(['is_default' => false]);
            AssetTenant::where('team_id', $this->teamId())->where('id', $tenantId)->update(['is_default' => true]);
        });

        $this->flash = 'Standard-Tenant gesetzt.';
        $this->refreshTenants();
    }

    public function deleteTenant(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $tenant = $this->selectedTenant();
        if (! $tenant) return;

        $wasDefault = $tenant->is_default;
        $tenant->delete(); // Cascade: Inventar + Connector dieses Tenants (FK cascadeOnDelete)

        $this->refreshTenants();
        $remaining = $this->tenants();

        // Default nachziehen, falls der gelöschte der Standard war
        if ($wasDefault && $remaining->isNotEmpty() && ! $remaining->contains('is_default', true)) {
            $remaining->first()->update(['is_default' => true]);
            $this->refreshTenants();
        }

        $this->selectedTenantId = $this->tenants()->first()?->id;
        $this->confirmingTenantDelete = false;
        $this->flash = 'Tenant und sein Inventar wurden gelöscht.';
        $this->loadConnectorForm();
    }

    // ---- Connector --------------------------------------------------------

    public function addConnector(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);
        $this->validate(['directory' => 'required|string|max:255']);

        $tenant = $this->selectedTenant();
        if (! $tenant) {
            $this->flash = 'Bitte zuerst einen Tenant wählen oder anlegen.';
            return;
        }
        if ($tenant->connector) {
            $this->flash = 'Dieser Tenant hat bereits einen Connector.';
            return;
        }

        $data = [
            'team_id'         => $this->teamId(),
            'tenant_id'       => $tenant->id,
            'azure_tenant_id' => trim($this->directory),
            'enabled'         => true,
        ];
        if ($this->clientId     !== '') $data['client_id']     = trim($this->clientId);
        if ($this->clientSecret !== '') $data['client_secret'] = $this->clientSecret;

        $connector = AssetConnectorConfig::create($data);
        app(IntuneGraphService::class)->clearTokenCache($connector->id);

        $this->clientSecret = '';
        $this->flash = 'Connector angelegt. Consent-Link verschicken, dann „Anbindung prüfen".';
        $this->refreshTenants();
    }

    public function saveConnector(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);
        $this->validate(['directory' => 'required|string|max:255']);

        $connector = $this->selectedConnector();
        if (! $connector) return;

        $directoryChanged = trim($this->directory) !== ($connector->azure_tenant_id ?? '');

        $connector->azure_tenant_id = trim($this->directory);
        if ($this->clientId     !== '') $connector->client_id     = trim($this->clientId);
        if ($this->clientSecret !== '') $connector->client_secret = $this->clientSecret;

        // Verzeichnis gewechselt → Consent galt fürs alte Verzeichnis, muss neu bestätigt werden.
        if ($directoryChanged) {
            $connector->consent_confirmed_at = null;
        }

        $connector->save();
        app(IntuneGraphService::class)->clearTokenCache($connector->id);

        $this->clientSecret = '';
        $this->flash = $directoryChanged
            ? 'Gespeichert. Verzeichnis geändert — bitte Consent erneut prüfen.'
            : 'Gespeichert.';
        $this->refreshTenants();
    }

    public function checkConnection(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $connector = $this->selectedConnector();
        if (! $connector || ! $connector->isConfigured()) {
            $this->testResult  = 'Connector ist nicht vollständig konfiguriert (Kunden-Verzeichnis + zentrale App oder eigenes Secret nötig).';
            $this->testSuccess = false;
            return;
        }

        $error = app(IntuneGraphService::class)->testConnection($connector);

        if ($error === null) {
            if (! $connector->isConsentConfirmed()) {
                $connector->update(['consent_confirmed_at' => now()]);
            }
            $this->testResult  = 'Verbindung erfolgreich — Anbindung ist aktiv.';
            $this->testSuccess = true;
        } else {
            $this->testResult  = $error;
            $this->testSuccess = false;
        }
        $this->refreshTenants();
    }

    public function syncNow(): void
    {
        Gate::authorize('sync', AssetDevice::class);

        $connector = $this->selectedConnector();
        if (! $connector) return;

        SyncIntuneDevicesJob::dispatch($connector->id);
        SyncLicensesJob::dispatch($connector->id);

        $this->flash = 'Sync gestartet — Geräte und Lizenzen werden im Hintergrund synchronisiert.';
    }

    public function importUsers(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $connector = $this->selectedConnector();
        if (! $connector) return;

        ImportTenantUsersJob::dispatch($connector->id);
        $this->flash = 'Import gestartet — alle Tenant-User werden im Hintergrund angelegt.';
    }

    public function refreshToken(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $connector = $this->selectedConnector();
        if (! $connector) return;

        app(IntuneGraphService::class)->clearTokenCache($connector->id);
        $this->flash = 'Token-Cache geleert. Beim nächsten Abruf wird ein frischer Token geholt.';
    }

    public function disconnect(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $connector = $this->selectedConnector();
        if (! $connector) return;

        $connector->update(['enabled' => false]);
        $this->flash = 'Connector getrennt. Bereits synchronisierte Daten bleiben erhalten.';
        $this->refreshTenants();
    }

    public function reconnect(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $connector = $this->selectedConnector();
        if (! $connector) return;

        $connector->update(['enabled' => true]);
        $this->flash = 'Connector wieder aktiviert.';
        $this->refreshTenants();
    }

    // ---- Daten ------------------------------------------------------------

    protected function refreshTenants(): void
    {
        $this->tenantsCache = null;
    }

    public function tenants()
    {
        if ($this->tenantsCache === null) {
            $this->tenantsCache = AssetTenant::where('team_id', $this->teamId())
                ->with('connector')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();
        }
        return $this->tenantsCache;
    }

    public function selectedTenant(): ?AssetTenant
    {
        if (! $this->selectedTenantId) return null;
        return $this->tenants()->firstWhere('id', $this->selectedTenantId);
    }

    public function selectedConnector(): ?AssetConnectorConfig
    {
        return $this->selectedTenant()?->connector;
    }

    public function render()
    {
        $tenant    = $this->selectedTenant();
        $connector = $tenant?->connector;

        $consentUrl = ($connector && $connector->azure_tenant_id && $connector->effectiveClientId())
            ? app(IntuneGraphService::class)->adminConsentUrl($connector)
            : null;

        return view('asset-manager::livewire.connectors.index', [
            'tenants'              => $this->tenants(),
            'selectedTenant'       => $tenant,
            'connector'            => $connector,
            'consentUrl'           => $consentUrl,
            'centralAppConfigured' => ! empty(config('asset-manager.azure.client_id')),
        ])->layout('platform::layouts.app');
    }
}
