<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Services\IntuneGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncIntuneDevicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public readonly int $teamId
    ) {}

    public function handle(IntuneGraphService $service): void
    {
        $config = AssetConnectorConfig::where('team_id', $this->teamId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured()) {
            return;
        }

        $startedAt = now();
        $log = AssetDeviceSyncLog::create([
            'team_id'    => $this->teamId,
            'status'     => 'started',
            'started_at' => $startedAt,
        ]);

        $config->update(['sync_status' => 'running']);

        try {
            $devices = $service->getManagedDevices($config);

            if ($devices === null) {
                $this->markFailed($config, $log, $startedAt, 'Geräte konnten nicht abgerufen werden. Token oder Berechtigungen prüfen.');
                return;
            }

            $added   = 0;
            $updated = 0;
            $intuneIds = [];

            foreach ($devices as $device) {
                $intuneIds[] = $device['id'];

                $existing = AssetDevice::where('team_id', $this->teamId)
                    ->where('intune_id', $device['id'])
                    ->first();

                $data = $this->mapDevice($device);

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    AssetDevice::create(array_merge($data, [
                        'team_id'   => $this->teamId,
                        'tenant_id' => $config->tenant_id,
                        'intune_id' => $device['id'],
                        'source'    => 'intune',
                    ]));
                    $added++;
                }
            }

            // Geräte entfernen, die nicht mehr in Intune vorhanden sind
            $removed = AssetDevice::where('team_id', $this->teamId)
                ->whereNotIn('intune_id', $intuneIds)
                ->count();

            AssetDevice::where('team_id', $this->teamId)
                ->whereNotIn('intune_id', $intuneIds)
                ->delete();

            $durationMs = (int) ($startedAt->diffInMilliseconds(now()));

            $log->update([
                'status'          => 'success',
                'devices_synced'  => count($devices),
                'devices_added'   => $added,
                'devices_updated' => $updated,
                'devices_removed' => $removed,
                'duration_ms'     => $durationMs,
                'completed_at'    => now(),
            ]);

            $config->update([
                'sync_status'  => 'success',
                'sync_error'   => null,
                'last_sync_at' => now(),
            ]);

            Log::info('AssetManager: Sync erfolgreich', [
                'team_id'  => $this->teamId,
                'synced'   => count($devices),
                'added'    => $added,
                'updated'  => $updated,
                'removed'  => $removed,
                'duration' => $durationMs . 'ms',
            ]);

        } catch (\Throwable $e) {
            $this->markFailed($config, $log, $startedAt, $e->getMessage());

            Log::error('AssetManager: Sync-Exception', [
                'team_id' => $this->teamId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function mapDevice(array $d): array
    {
        return [
            'device_name'          => $d['deviceName'] ?? null,
            'user_display_name'    => $d['userDisplayName'] ?? null,
            'user_principal_name'  => $d['userPrincipalName'] ?? null,
            'operating_system'     => $d['operatingSystem'] ?? null,
            'os_version'           => $d['osVersion'] ?? null,
            'compliance_state'     => $d['complianceState'] ?? 'unknown',
            'management_state'     => $d['managementState'] ?? null,
            'device_type'          => $d['deviceType'] ?? null,
            'manufacturer'         => $d['manufacturer'] ?? null,
            'model'                => $d['model'] ?? null,
            'serial_number'        => $d['serialNumber'] ?? null,
            'enrolled_at'          => isset($d['enrolledDateTime']) ? \Carbon\Carbon::parse($d['enrolledDateTime']) : null,
            'last_check_in_at'     => isset($d['lastSyncDateTime']) ? \Carbon\Carbon::parse($d['lastSyncDateTime']) : null,
            'raw_data'             => $d,
        ];
    }

    protected function markFailed(AssetConnectorConfig $config, AssetDeviceSyncLog $log, \Carbon\Carbon $startedAt, string $message): void
    {
        $durationMs = (int) ($startedAt->diffInMilliseconds(now()));

        $log->update([
            'status'        => 'error',
            'error_message' => $message,
            'duration_ms'   => $durationMs,
            'completed_at'  => now(),
        ]);

        $config->update([
            'sync_status' => 'error',
            'sync_error'  => $message,
        ]);
    }
}
