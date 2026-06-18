<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetLicenseSyncLog;

class AssetDeviceService
{
    /**
     * Aggregierter Connector-Status für ein Team (über alle Tenants/Connectoren). Für eine schnelle
     * „kann das Team syncen?"-Einschätzung; die tenant-genaue Sicht liefert getConnectorsStatus().
     */
    public function getConnectorStatus(int $teamId): array
    {
        $configs = AssetConnectorConfig::where('team_id', $teamId)->get();
        $latest  = $configs->sortByDesc('last_sync_at')->first();

        return [
            'exists'          => $configs->isNotEmpty(),
            'configured'      => $configs->contains(fn ($c) => $c->isConfigured()),
            'enabled'         => $configs->contains(fn ($c) => $c->enabled),
            'connector_count' => $configs->count(),
            'sync_status'     => $latest?->sync_status ?? 'idle',
            'last_sync_at'    => $latest?->last_sync_at,
            'sync_error'      => $latest?->sync_error,
        ];
    }

    /**
     * Tenant-genaue Connector-Sicht: je Connector Status + jeweils letzter Geräte-/Lizenz-Sync-Lauf.
     */
    public function getConnectorsStatus(int $teamId): array
    {
        return AssetConnectorConfig::with('tenant')
            ->where('team_id', $teamId)
            ->get()
            ->map(function (AssetConnectorConfig $c) {
                $deviceLog  = AssetDeviceSyncLog::where('tenant_id', $c->tenant_id)->orderByDesc('started_at')->first();
                $licenseLog = AssetLicenseSyncLog::where('tenant_id', $c->tenant_id)->orderByDesc('started_at')->first();

                return [
                    'connector_id'      => $c->id,
                    'tenant_id'         => $c->tenant_id,
                    'tenant_name'       => $c->tenant?->name,
                    'connection_status' => $c->connectionStatus(),
                    'configured'        => $c->isConfigured(),
                    'enabled'           => $c->enabled,
                    'sync_status'       => $c->sync_status,
                    'last_sync_at'      => $c->last_sync_at,
                    'sync_error'        => $c->sync_error,
                    'last_device_sync'  => $deviceLog,
                    'last_license_sync' => $licenseLog,
                ];
            })
            ->all();
    }

    /**
     * Gibt true zurück, wenn das Team mindestens einen konfigurierten, aktivierten Connector hat.
     */
    public function isReady(int $teamId): bool
    {
        $status = $this->getConnectorStatus($teamId);
        return $status['exists'] && $status['configured'] && $status['enabled'];
    }

    /**
     * Dispatcht den Geräte-Sync für ein Team — fan-out je aktivem Connector (Multi-Tenant).
     */
    public function dispatchSync(int $teamId): void
    {
        SyncIntuneDevicesJob::dispatchForTeam($teamId);
    }

    /**
     * Letzter Geräte-Sync-Log-Eintrag eines Teams (über alle Tenants).
     */
    public function getLastSyncLog(int $teamId): ?AssetDeviceSyncLog
    {
        return AssetDeviceSyncLog::where('team_id', $teamId)
            ->orderBy('started_at', 'desc')
            ->first();
    }
}
