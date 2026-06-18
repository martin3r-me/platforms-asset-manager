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

        $cacheKey = 'asset_manager_token_conn_' . $config->id;

        return Cache::remember($cacheKey, 3480, function () use ($config) {
            return $this->fetchToken($config);
        });
    }

    protected function fetchToken(AssetConnectorConfig $config): ?string
    {
        try {
            $response = Http::asForm()->post(
                "{$this->loginBase}/{$config->azure_tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $config->effectiveClientId(),
                    'client_secret' => $config->effectiveClientSecret(),
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

    public function clearTokenCache(int $connectorId): void
    {
        Cache::forget('asset_manager_token_conn_' . $connectorId);
    }

    /**
     * Baut den Admin-Consent-Link für den Connector (manueller Consent — siehe docs/adr/0003).
     * Der Kunden-Admin öffnet den Link einmal und stimmt zu; aktiviert wird der Connector danach
     * über „Anbindung prüfen" (testConnection), NICHT über einen Callback.
     */
    public function adminConsentUrl(AssetConnectorConfig $config, ?string $state = null): string
    {
        $params = http_build_query([
            'client_id'    => $config->effectiveClientId(),
            'scope'        => 'https://graph.microsoft.com/.default',
            'redirect_uri' => config('asset-manager.azure.redirect_uri'),
            'state'        => $state ?? \Illuminate\Support\Str::random(32),
        ]);

        return "{$this->loginBase}/{$config->azure_tenant_id}/v2.0/adminconsent?{$params}";
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
            . ',complianceState,managementState,managedDeviceOwnerType,manufacturer,model,serialNumber'
            . ',enrolledDateTime,lastSyncDateTime'
            // Security-/Health-Felder — alle unter DeviceManagementManagedDevices.Read.All (keine neue Permission).
            . ',isEncrypted,deviceEnrollmentType,freeStorageSpaceInBytes,totalStorageSpaceInBytes,physicalMemoryInBytes';

        $retried = false;

        while ($url) {
            try {
                $response = Http::withHeaders([
                    'Authorization'    => 'Bearer ' . $token,
                    'ConsistencyLevel' => 'eventual',
                ])->get($url);

                if ($response->status() === 401 && !$retried) {
                    $retried = true;
                    $this->clearTokenCache($config->id);
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
     * Holt alle abonnierten SKUs (Lizenz-Typen) aus dem Tenant.
     * Benötigte Application Permission: Organization.Read.All
     */
    public function getSubscribedSkus(AssetConnectorConfig $config): ?array
    {
        $this->lastError = null;

        // Token komplett frisch holen (umgeht stale Tokens nach Permission-Änderungen)
        $this->clearTokenCache($config->id);
        $token = $this->getAccessToken($config);
        if (!$token) {
            $this->lastError = 'Token-Abruf fehlgeschlagen. Client ID, Tenant ID und Secret prüfen.';
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get("{$this->graphBase}/subscribedSkus?\$select=id,skuId,skuPartNumber,consumedUnits,prepaidUnits,servicePlans");

            if ($response->status() === 403) {
                $graphMsg = $response->json('error.message', '');
                $graphCode = $response->json('error.code', '');
                $this->lastError = "Keine Berechtigung (403) für /subscribedSkus. "
                    . "Benötigt: Organization.Read.All als Application Permission mit Admin-Consent. "
                    . "Graph: [{$graphCode}] {$graphMsg}";
                Log::error('AssetManager: Keine Lizenz-Berechtigung (403)', [
                    'team_id' => $config->team_id,
                    'code'    => $graphCode,
                    'msg'     => $graphMsg,
                ]);
                return null;
            }

            if (!$response->successful()) {
                $msg = $response->json('error.message', 'Unbekannter Fehler');
                $this->lastError = "Graph-API Fehler (HTTP {$response->status()}): {$msg}";
                return null;
            }

            return $response->json('value', []);

        } catch (\Throwable $e) {
            $this->lastError = 'Verbindungsfehler: ' . $e->getMessage();
            Log::error('AssetManager: Exception beim SKU-Abruf', [
                'team_id' => $config->team_id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Holt alle User mit ihren zugewiesenen Lizenzen.
     * Benötigte Application Permission: User.Read.All
     */
    public function getUsersWithLicenses(AssetConnectorConfig $config): ?array
    {
        $this->lastError = null;

        $token = $this->getAccessToken($config);
        if (!$token) {
            $this->lastError = 'Token-Abruf fehlgeschlagen. Client ID, Tenant ID und Secret prüfen.';
            return null;
        }

        $users = [];
        $url   = "{$this->graphBase}/users?\$select=id,displayName,userPrincipalName,assignedLicenses&\$top=999";

        $retried = false;

        while ($url) {
            try {
                $response = Http::withHeaders([
                    'Authorization'    => 'Bearer ' . $token,
                    'ConsistencyLevel' => 'eventual',
                ])->get($url);

                // 401 mitten in der Paginierung (Token während des Laufs abgelaufen): einmal Cache leeren,
                // Token frisch holen und dieselbe Seite erneut anfordern — analog getManagedDevices.
                if ($response->status() === 401 && !$retried) {
                    $retried = true;
                    $this->clearTokenCache($config->id);
                    $token = $this->fetchToken($config);
                    if (!$token) {
                        $this->lastError = 'Token abgelaufen und Erneuerung fehlgeschlagen. Credentials prüfen.';
                        return null;
                    }
                    continue;
                }

                if ($response->status() === 403) {
                    $graphMsg  = $response->json('error.message', '');
                    $graphCode = $response->json('error.code', '');
                    $this->lastError = "Keine Berechtigung (403) für /users. "
                        . "Benötigt: User.Read.All als Application Permission mit Admin-Consent. "
                        . "Graph: [{$graphCode}] {$graphMsg}";
                    Log::error('AssetManager: Keine User-Berechtigung (403)', [
                        'team_id' => $config->team_id,
                        'code'    => $graphCode,
                        'msg'     => $graphMsg,
                    ]);
                    return null;
                }

                if (!$response->successful()) {
                    $msg = $response->json('error.message', 'Unbekannter Fehler');
                    $this->lastError = "Graph-API Fehler (HTTP {$response->status()}): {$msg}";
                    return null;
                }

                $data  = $response->json();
                $users = array_merge($users, $data['value'] ?? []);
                $url   = $data['@odata.nextLink'] ?? null;

            } catch (\Throwable $e) {
                $this->lastError = 'Verbindungsfehler: ' . $e->getMessage();
                Log::error('AssetManager: Exception beim User-Abruf', [
                    'team_id' => $config->team_id,
                    'error'   => $e->getMessage(),
                ]);
                return null;
            }
        }

        return $users;
    }

    /**
     * Testet die Verbindung. Gibt null bei Erfolg zurück, sonst eine Fehlermeldung.
     */
    public function testConnection(AssetConnectorConfig $config): ?string
    {
        if (!$config->isConfigured()) {
            return 'Connector ist nicht vollständig konfiguriert (Client ID, Tenant ID und Secret werden benötigt).';
        }

        $this->clearTokenCache($config->id);

        try {
            $tokenResponse = Http::asForm()->post(
                "{$this->loginBase}/{$config->azure_tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $config->effectiveClientId(),
                    'client_secret' => $config->effectiveClientSecret(),
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
