<?php

namespace Platform\AssetManager\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetConnectorConfig;
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

    protected array $rules = [
        'clientId'     => 'nullable|string|max:255',
        'tenantId'     => 'nullable|string|max:255',
        'objectId'     => 'nullable|string|max:255',
        'keyId'        => 'nullable|string|max:255',
        'clientSecret' => 'nullable|string|max:1000',
        'enabled'      => 'boolean',
    ];

    public function mount(): void
    {
        $team   = Auth::user()->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        if ($config) {
            $this->clientId = $config->client_id     ?? '';
            $this->tenantId = $config->tenant_id     ?? '';
            $this->objectId = $config->object_id     ?? '';
            $this->keyId    = $config->key_id        ?? '';
            // Secret wird aus Sicherheitsgründen nicht vorausgefüllt
            $this->enabled  = $config->enabled;
        }
    }

    public function save(): void
    {
        $this->validate();

        $team = Auth::user()->currentTeam;

        $data = [
            'enabled' => $this->enabled,
        ];

        if ($this->clientId !== '')     $data['client_id']     = $this->clientId;
        if ($this->tenantId !== '')     $data['tenant_id']     = $this->tenantId;
        if ($this->objectId !== '')     $data['object_id']     = $this->objectId;
        if ($this->keyId    !== '')     $data['key_id']        = $this->keyId;
        if ($this->clientSecret !== '') $data['client_secret'] = $this->clientSecret;

        $config = AssetConnectorConfig::updateOrCreate(
            ['team_id' => $team->id],
            $data
        );

        // Token-Cache leeren damit neue Credentials sofort greifen
        app(IntuneGraphService::class)->clearTokenCache($team->id);

        // Secret nach dem Speichern leeren
        $this->clientSecret = '';
        $this->saved        = true;
        $this->testResult   = null;
    }

    public function testConnection(): void
    {
        $team   = Auth::user()->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        if (!$config) {
            $this->testResult  = 'Bitte zuerst speichern.';
            $this->testSuccess = false;
            return;
        }

        $error = app(IntuneGraphService::class)->testConnection($config);

        if ($error === null) {
            $this->testResult  = 'Verbindung erfolgreich. Intune-API ist erreichbar.';
            $this->testSuccess = true;
        } else {
            $this->testResult  = $error;
            $this->testSuccess = false;
        }
    }

    public function syncNow(): void
    {
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
