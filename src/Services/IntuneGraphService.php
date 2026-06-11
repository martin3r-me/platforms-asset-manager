<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntuneGraphService
{
    protected string $graphBase = 'https://graph.microsoft.com/v1.0';
    protected string $loginBase = 'https://login.microsoftonline.com';

    /**
     * Holt ein App-only Access Token via client_credentials grant.
     * Token wird für 58 Minuten gecacht (Ablaufzeit 3600s - 120s Puffer).
     */
    public function getAccessToken(AssetConnectorConfig $config): ?string
    {
        if (!$config->isConfigured()) {
            Log::warning('AssetManager: Connector nicht konfiguriert', ['team_id' => $config->team_id]);
            return null;
        }

        $cacheKey = 'asset_manager_token_' . $config->team_id;

        return Cache::remember($cacheKey, 3480, function () use ($config) {
            return $this->fetchToken($config);
        });
    }

    protected function fetchToken(AssetConnectorConfig $config): ?string
    {
        try {
            $response = Http::asForm()->post(
                "{$this->loginBase}/{$config->tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $config->client_id,
                    'client_secret' => $config->client_secret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]
            );

            if (!$response->successful()) {
                Log::error('AssetManager: Token-Abruf fehlgeschlagen', [
                    'team_id' => $config->team_id,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return null;
            }

            return $response->json('access_token');
        } catch (\Throwable $e) {
            Log::error('AssetManager: Exception beim Token-Abruf', [
                'team_id' => $config->team_id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Löscht den gecachten Token (z.B. nach Credential-Änderung).
     */
    public function clearTokenCache(int $teamId): void
    {
        Cache::forget('asset_manager_token_' . $teamId);
    }

    /**
     * Holt alle verwalteten Geräte aus Intune.
     * Paginiert automatisch via @odata.nextLink.
     *
     * Benötigte Application Permission: DeviceManagementManagedDevices.Read.All
     */
    public function getManagedDevices(AssetConnectorConfig $config): ?array
    {
        $token = $this->getAccessToken($config);
        if (!$token) {
            return null;
        }

        $devices = [];
        $url = "{$this->graphBase}/deviceManagement/managedDevices"
            . '?$select=id,deviceName,userDisplayName,userPrincipalName,operatingSystem,osVersion'
            . ',complianceState,managementState,deviceType,manufacturer,model,serialNumber'
            . ',enrolledDateTime,lastSyncDateTime';

        while ($url) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'ConsistencyLevel' => 'eventual',
                ])->get($url);

                if ($response->status() === 401) {
                    // Token ungültig — Cache leeren und einmal neu versuchen
                    $this->clearTokenCache($config->team_id);
                    $token = $this->getAccessToken($config);
                    if (!$token) return null;
                    continue;
                }

                if ($response->status() === 403) {
                    Log::error('AssetManager: Keine Intune-Berechtigung (403)', [
                        'team_id' => $config->team_id,
                        'hint'    => 'App-Registration braucht DeviceManagementManagedDevices.Read.All (Application Permission)',
                    ]);
                    return null;
                }

                if (!$response->successful()) {
                    Log::error('AssetManager: Graph-API Fehler', [
                        'team_id' => $config->team_id,
                        'status'  => $response->status(),
                        'body'    => $response->body(),
                    ]);
                    return null;
                }

                $data = $response->json();
                $devices = array_merge($devices, $data['value'] ?? []);
                $url = $data['@odata.nextLink'] ?? null;

            } catch (\Throwable $e) {
                Log::error('AssetManager: Exception beim Geräte-Abruf', [
                    'team_id' => $config->team_id,
                    'error'   => $e->getMessage(),
                ]);
                return null;
            }
        }

        return $devices;
    }

    /**
     * Testet die Verbindung mit den gespeicherten Credentials.
     * Gibt null zurück bei Erfolg, sonst eine Fehlermeldung.
     */
    public function testConnection(AssetConnectorConfig $config): ?string
    {
        if (!$config->isConfigured()) {
            return 'Connector ist nicht vollständig konfiguriert (Client ID, Tenant ID und Secret werden benötigt).';
        }

        $this->clearTokenCache($config->team_id);
        $token = $this->fetchToken($config);

        if (!$token) {
            return 'Token-Abruf fehlgeschlagen. Bitte Client ID, Tenant ID und Secret prüfen.';
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get("{$this->graphBase}/deviceManagement/managedDevices?\$top=1&\$select=id");

            if ($response->status() === 403) {
                return 'Verbindung erfolgreich, aber fehlende Berechtigung: DeviceManagementManagedDevices.Read.All muss als Application Permission mit Admin-Consent erteilt sein.';
            }

            if (!$response->successful()) {
                return "Graph-API Fehler ({$response->status()}): " . $response->json('error.message', 'Unbekannter Fehler');
            }

            return null;
        } catch (\Throwable $e) {
            return 'Verbindungsfehler: ' . $e->getMessage();
        }
    }
}
