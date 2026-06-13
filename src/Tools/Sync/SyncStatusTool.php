<?php

namespace Platform\AssetManager\Tools\Sync;

use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Services\AssetDeviceService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Status des Intune/Microsoft-Connectors + jeweils letzter Geräte- und Lizenz-Sync-Lauf.
 */
class SyncStatusTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.sync.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/sync - Connector-Status (konfiguriert/aktiviert/Status/letzter Lauf/'
            . 'Fehler) sowie der jeweils letzte Geräte- und Lizenz-Sync-Lauf mit Kennzahlen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            /** @var AssetDeviceService $svc */
            $svc       = app(AssetDeviceService::class);
            $status    = $svc->getConnectorStatus($teamId);
            $deviceLog = $svc->getLastSyncLog($teamId);
            $licLog    = AssetLicenseSyncLog::where('team_id', $teamId)->orderByDesc('started_at')->first();

            return ToolResult::success([
                'connector' => [
                    'exists'       => $status['exists'],
                    'configured'   => $status['configured'],
                    'enabled'      => $status['enabled'],
                    'sync_status'  => $status['sync_status'],
                    'last_sync_at' => $status['last_sync_at']?->toIso8601String(),
                    'sync_error'   => $status['sync_error'],
                ],
                'last_device_sync' => $deviceLog ? [
                    'status'          => $deviceLog->status,
                    'devices_synced'  => $deviceLog->devices_synced,
                    'devices_added'   => $deviceLog->devices_added,
                    'devices_updated' => $deviceLog->devices_updated,
                    'devices_removed' => $deviceLog->devices_removed,
                    'error_message'   => $deviceLog->error_message,
                    'completed_at'    => $deviceLog->completed_at?->toIso8601String(),
                ] : null,
                'last_license_sync' => $licLog ? [
                    'status'              => $licLog->status,
                    'skus_synced'         => $licLog->skus_synced,
                    'assignments_synced'  => $licLog->assignments_synced,
                    'assignments_added'   => $licLog->assignments_added,
                    'assignments_removed' => $licLog->assignments_removed,
                    'error_message'       => $licLog->error_message,
                    'completed_at'        => $licLog->completed_at?->toIso8601String(),
                ] : null,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Sync-Status: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'sync', 'connector']];
    }
}
