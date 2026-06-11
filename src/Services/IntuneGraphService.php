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
                $errorCode = $response->json('error');
                $errorDesc = $response->json('error_description');
                Log::error('AssetManager: Token-Abruf fehlgeschlagen', [
                    'team_id'    => $config->team_id,
                    'status'     => $response->status(),
                    'error'      => $errorCode,
                    'error_desc' => $errorDesc,
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

    public function clearTokenCache(int $teamId): void
    {
        Cache::forget('asset_manager_token_' . $teamId);
    }

    /**
     * Holt alle verwalteten Geräte aus Intune.
     * Gibt null zurück bei Fehler — Fehlerdetails in $this->lastError.
     *
     * Benötigte Application Permission: DeviceManagementManagedDevices.Read.All
     */
    public ?string $lastError = null;

    public function getManagedDevices(AssetConnectorConfig $config): ?array
    {
        $this->lastError = null;

        $token = $this->getAccessToken($config);
        if (!$token) {
            $this->lastError = 'Token-Abruf fehlgeschlagen. Client ID, Tenant ID und Secret prüfen.';
            return null;
        }

        $devices = [];
        $url = "{$this->graphBase}/deviceManagement/managedDevices"
            . '?$select=id,deviceName,userDisplayName,userPrincipalName,operatingSystem,osVersion'
            . ',complianceState,managementState,deviceType,manufacturer,model,serialNumber'
            . ',enrolledDateTime,lastSyncDateTime';

        $retried = false;

        while ($url) {
            try {
                $response = Http::withHeaders([
                    'Authorization'    => 'Bearer ' . $token,
                    'ConsistencyLevel' => 'eventual',
                ])->get($url);

                if ($response->status() === 401 && !$retried) {
                    $retried = true;
                    $this->clearTokenCache($config->team_id);
                    $token = $this->fetchToken($config);
                    if (!$token) {
                        $this->lastError = 'Token abgelaufen und Erneuerung fehlgeschlagen. Credentials prüfen.';
                        return null;
                    }
                    continue;
                }

                if ($response->status() === 403) {
                    $this->lastError = 'Keine Berechtigung (403): DeviceManagementManagedDevices.Read.All muss als Application Permission mit Admin-Consent erteilt sein.';
                    Log::error('AssetManager: Keine Intune-Berechtigung (403)', ['team_id' => $config->team_id]);
                    return null;
                }

                if (!$response->successful()) {
                    $msg = $response->json('error.message', 'Unbekannter Fehler');
                    $this->lastError = "Graph-API Fehler (HTTP {$response->status()}): {$msg}";
                    Log::error('AssetManager: Graph-API Fehler', [
                        'team_id' => $config->team_id,
                        'status'  => $response->status(),
                        'body'    => $response->body(),
                    ]);
                    return null;
                }

                $data    = $response->json();
                $devices = array_merge($devices, $data['value'] ?? []);
                $url     = $data['@odata.nextLink'] ?? null;

            } catch (\Throwable $e) {
                $this->lastError = 'Verbindungsfehler: ' . $e->getMessage();
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
     * Testet die Verbindung. Gibt null bei Erfolg zurück, sonst eine Fehlermeldung.
     */
    public function testConnection(AssetConnectorConfig $config): ?string
    {
        if (!$config->isConfigured()) {
            return 'Connector ist nicht vollständig konfiguriert (Client ID, Tenant ID und Secret werden benötigt).';
        }

        $this->clearTokenCache($config->team_id);

        try {
            $tokenResponse = Http::asForm()->post(
                "{$this->loginBase}/{$config->tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $config->client_id,
                    'client_secret' => $config->client_secret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]
            );

            if (!$tokenResponse->successful()) {
                $err  = $tokenResponse->json('error', 'unknown_error');
                $desc = $tokenResponse->json('error_description', '');
                // Beschreibung kürzen (oft sehr lang)
                $desc = preg_replace('/\s*Trace ID:.*$/s', '', $desc);
                $desc = trim($desc);
                return "Token-Fehler ({$err}): {$desc}";
            }

            $token = $tokenResponse->json('access_token');
        } catch (\Throwable $e) {
            return 'Token-Abruf fehlgeschlagen: ' . $e->getMessage();
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get("{$this->graphBase}/deviceManagement/managedDevices?\$top=1&\$select=id");

            if ($response->status() === 403) {
                return 'Token OK, aber fehlende Berechtigung (403): DeviceManagementManagedDevices.Read.All muss als Application Permission mit Admin-Consent erteilt sein.';
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
