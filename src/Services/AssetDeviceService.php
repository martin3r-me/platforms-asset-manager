<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDeviceSyncLog;

class AssetDeviceService
{
    /**
     * Gibt den vollständigen Connector-Status für ein Team zurück.
     */
    public function getConnectorStatus(int $teamId): array
    {
        $config = AssetConnectorConfig::where('team_id', $teamId)->first();

        return [
            'exists'       => (bool) $config,
            'configured'   => $config?->isConfigured() ?? false,
            'enabled'      => $config?->enabled ?? false,
            'sync_status'  => $config?->sync_status ?? 'idle',
            'last_sync_at' => $config?->last_sync_at,
            'sync_error'   => $config?->sync_error,
        ];
    }

    /**
     * Gibt true zurück, wenn der Connector vollständig konfiguriert und aktiviert ist.
     */
    public function isReady(int $teamId): bool
    {
        $status = $this->getConnectorStatus($teamId);
        return $status['exists'] && $status['configured'] && $status['enabled'];
    }

    /**
     * Dispatcht den Sync-Job für ein Team.
     */
    public function dispatchSync(int $teamId): void
    {
        SyncIntuneDevicesJob::dispatch($teamId);
    }

    /**
     * Letzter Sync-Log-Eintrag für ein Team.
     */
    public function getLastSyncLog(int $teamId): ?AssetDeviceSyncLog
    {
        return AssetDeviceSyncLog::where('team_id', $teamId)
            ->orderBy('started_at', 'desc')
            ->first();
    }
}
