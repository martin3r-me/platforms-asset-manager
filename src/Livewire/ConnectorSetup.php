<?php

namespace Platform\AssetManager\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Services\IntuneGraphService;

class ConnectorSetup extends Component
{
    public string $clientId     = '';
    public string $tenantId     = '';
    public string $objectId     = '';
    public string $keyId        = '';
    public string $clientSecret = '';
    public bool   $enabled      = true;

    public ?string $testResult  = null;
    public bool    $testSuccess = false;
    public bool    $saved       = false;

    public function mount(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $team   = Auth::user()->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        if ($config) {
            $this->clientId = $config->client_id ?? '';
            $this->tenantId = $config->tenant_id ?? '';
            $this->objectId = $config->object_id ?? '';
            $this->keyId    = $config->key_id    ?? '';
            $this->enabled  = $config->enabled;
            // Secret wird aus Sicherheitsgründen nicht vorausgefüllt
        }
    }

    public function save(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $this->validate([
            'clientId'     => 'required|string|max:255',
            'tenantId'     => 'required|string|max:255',
            'objectId'     => 'nullable|string|max:255',
            'keyId'        => 'nullable|string|max:255',
            'clientSecret' => [
                'nullable', 'string', 'max:1000',
                // Bei Neueintrag ist Secret Pflichtfeld
                function ($attr, $value, $fail) {
                    $team   = Auth::user()->currentTeam;
                    $exists = AssetConnectorConfig::where('team_id', $team->id)
                        ->whereNotNull('client_secret')
                        ->exists();
                    if (!$exists && empty($value)) {
                        $fail('Das Secret ist beim ersten Einrichten erforderlich.');
                    }
                },
            ],
            'enabled' => 'boolean',
        ]);

        $team = Auth::user()->currentTeam;

        $data = ['enabled' => $this->enabled];

        if ($this->clientId     !== '') $data['client_id']     = $this->clientId;
        if ($this->tenantId     !== '') $data['tenant_id']     = $this->tenantId;
        if ($this->objectId     !== '') $data['object_id']     = $this->objectId;
        if ($this->keyId        !== '') $data['key_id']        = $this->keyId;
        if ($this->clientSecret !== '') $data['client_secret'] = $this->clientSecret;

        AssetConnectorConfig::updateOrCreate(['team_id' => $team->id], $data);

        app(IntuneGraphService::class)->clearTokenCache($team->id);

        $this->clientSecret = '';
        $this->saved        = true;
        $this->testResult   = null;
    }

    public function testConnection(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $team   = Auth::user()->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        if (!$config || !$config->isConfigured()) {
            $this->testResult  = 'Bitte zuerst alle Pflichtfelder speichern (Client ID, Tenant ID, Secret).';
            $this->testSuccess = false;
            return;
        }

        $error = app(IntuneGraphService::class)->testConnection($config);

        $this->testResult  = $error ?? 'Verbindung erfolgreich. Intune-API ist erreichbar.';
        $this->testSuccess = $error === null;
    }

    public function syncNow(): void
    {
        Gate::authorize('sync', AssetDevice::class);

        $team = Auth::user()->currentTeam;

        \Platform\AssetManager\Jobs\SyncIntuneDevicesJob::dispatch($team->id);

        $this->testResult  = 'Sync-Job wurde gestartet. Die Geräte werden im Hintergrund synchronisiert.';
        $this->testSuccess = true;
    }

    public function render()
    {
        $team   = Auth::user()->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        return view('asset-manager::livewire.connector-setup', [
            'config' => $config,
        ])->layout('platform::layouts.app');
    }
}
