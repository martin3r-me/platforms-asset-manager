<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Concerns\RunsTeamSync;
use Platform\AssetManager\Services\EmployeeService;
use Platform\AssetManager\Services\IntuneGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncIntuneDevicesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RunsTeamSync;

    public int $timeout = 300;
    public int $tries   = 1;

    /** Serialisiert parallele Geraete-Syncs desselben Teams (3.0-Entscheidung; analog SyncLicensesJob) —
     *  schliesst das check-then-act-Rennen (first()->create()) gegen den (team_id,intune_id)-Unique-Index. */
    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function __construct(
        public readonly int $teamId
    ) {}

    public function handle(IntuneGraphService $service, EmployeeService $employeeService): void
    {
        $config = AssetConnectorConfig::where('team_id', $this->teamId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured()) {
            Log::warning('AssetManager: Sync übersprungen — Connector nicht konfiguriert', ['team_id' => $this->teamId]);
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
                $error = $service->lastError ?? 'Unbekannter Fehler beim Geräte-Abruf.';
                $this->markFailed($config, $log, $startedAt, $error);
                return;
            }

            $added     = 0;
            $updated   = 0;
            $intuneIds = [];

            foreach ($devices as $device) {
                $intuneIds[] = $device['id'];

                // withTrashed: ein zuvor verschwundenes (soft-deleted) Gerät wird restored statt neu
                // angelegt — so bleiben seine Kosten-Overrides (monthly_cost, cost_type_id …) erhalten.
                $existing = AssetDevice::withTrashed()
                    ->where('team_id', $this->teamId)
                    ->where('intune_id', $device['id'])
                    ->first();

                $data = $this->mapDevice($device);

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    // VOR dem update(): $existing trägt noch die alten Werte → Verlaufs-Diff möglich.
                    $this->recordChangeEvents($existing, $data);
                    $existing->update($data);
                    $updated++;
                } else {
                    $created = AssetDevice::create(array_merge($data, [
                        'team_id'   => $this->teamId,
                        'tenant_id' => $config->azure_tenant_id,
                        'intune_id' => $device['id'],
                        'source'    => 'intune',
                    ]));
                    $this->recordEvent($created, 'created', 'Gerät erstmals aus Intune erfasst');
                    $added++;
                }

                // Geräte-Modell-Katalog pflegen (Preise ergänzt der User im UI). Nur bei bekanntem Modell/Hersteller.
                if (!empty($data['manufacturer']) || !empty($data['model'])) {
                    AssetDeviceModel::firstOrCreate([
                        'team_id'      => $this->teamId,
                        'manufacturer' => $data['manufacturer'],
                        'model'        => $data['model'],
                    ]);
                }

                // Employee aus UPN ableiten (Intune liefert userPrincipalName)
                if (!empty($device['userPrincipalName'])) {
                    $employeeService->findOrCreateByUpn(
                        $this->teamId,
                        $device['userPrincipalName'],
                        $device['userDisplayName'] ?? null,
                        'derived'
                    );
                }
            }

            $durationMs = (int) ($startedAt->diffInMilliseconds(now()));
            $removed    = 0;

            // Reconcile-Delete + Status-Schreiben atomar: kein halb-bereinigter Stand und kein ewig
            // hängendes 'running', falls zwischen Delete und Status-Update etwas schiefgeht.
            DB::transaction(function () use ($intuneIds, $devices, $added, $updated, $durationMs, $log, $config, &$removed) {
                // Schutz: Eine leere — aber erfolgreiche (HTTP 200, value=[]) — Graph-Antwort darf NIE die
                // ganze Flotte löschen. $devices === null ist oben bereits als Fehler abgefangen; ein leeres
                // Array ist syntaktisch valide, würde aber whereNotIn('intune_id', []) zu „alle Zeilen"
                // machen → Totalverlust beim ersten Tenant-Glitch. Dann nichts entfernen (removed=0).
                $removed = $this->reconcileDelete(
                    fn () => AssetDevice::where('team_id', $this->teamId),
                    'intune_id',
                    $intuneIds
                );

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
            });

            // Safety-Net: nochmal alle UPNs einsammeln (fängt Bestandsdaten vor Phase 3 ab)
            $employeeService->backfillForTeam($this->teamId);

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
            'device_name'         => $d['deviceName'] ?? null,
            'user_display_name'   => $d['userDisplayName'] ?? null,
            'user_principal_name' => $d['userPrincipalName'] ?? null,
            'operating_system'    => $d['operatingSystem'] ?? null,
            'os_version'          => $d['osVersion'] ?? null,
            'compliance_state'    => $d['complianceState'] ?? 'unknown',
            'management_state'    => $d['managementState'] ?? null,
            'device_type'         => $d['managedDeviceOwnerType'] ?? null,
            'manufacturer'        => $d['manufacturer'] ?? null,
            'model'               => $d['model'] ?? null,
            'serial_number'       => $d['serialNumber'] ?? null,
            'enrolled_at'         => isset($d['enrolledDateTime']) ? \Carbon\Carbon::parse($d['enrolledDateTime']) : null,
            'last_check_in_at'    => isset($d['lastSyncDateTime']) ? \Carbon\Carbon::parse($d['lastSyncDateTime']) : null,
            'is_encrypted'          => $d['isEncrypted'] ?? null,
            'enrollment_type'       => $d['deviceEnrollmentType'] ?? null,
            'free_storage_bytes'    => $d['freeStorageSpaceInBytes'] ?? null,
            'total_storage_bytes'   => $d['totalStorageSpaceInBytes'] ?? null,
            'physical_memory_bytes' => $d['physicalMemoryInBytes'] ?? null,
            'raw_data'            => $d,
        ];
    }

    /**
     * Schreibt Verlaufs-Events bei Wechsel von Besitzer/Compliance/OS — VOR dem update() aufrufen,
     * solange $device noch die alten DB-Werte trägt. Historie ist Beiwerk: Fehler dürfen den Sync
     * nie scheitern lassen (try/catch in recordEvent).
     */
    protected function recordChangeEvents(AssetDevice $device, array $data): void
    {
        $checks = [
            'user_principal_name' => ['owner_changed',      'Besitzer geändert'],
            'compliance_state'    => ['compliance_changed', 'Compliance geändert'],
            'os_version'          => ['os_changed',         'OS aktualisiert'],
        ];

        foreach ($checks as $field => [$type, $label]) {
            $old = (string) ($device->{$field} ?? '');
            $new = (string) ($data[$field] ?? '');
            if ($old !== $new) {
                $this->recordEvent($device, $type, $label, $old !== '' ? $old : null, $new !== '' ? $new : null);
            }
        }
    }

    protected function recordEvent(AssetDevice $device, string $type, string $description, ?string $old = null, ?string $new = null): void
    {
        try {
            AssetDeviceEvent::create([
                'team_id'         => $this->teamId,
                'asset_device_id' => $device->id,
                'event_type'      => $type,
                'description'     => $description,
                'old_value'       => $old,
                'new_value'       => $new,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Geräte-Event nicht geschrieben', [
                'team_id' => $this->teamId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function markFailed(AssetConnectorConfig $config, AssetDeviceSyncLog $log, \Carbon\Carbon $startedAt, string $message): void
    {
        $this->markSyncLogFailed($log, $startedAt, $message);

        $config->update([
            'sync_status' => 'error',
            'sync_error'  => $message,
        ]);
    }

    /**
     * Wird von Laravel bei terminalem Scheitern (Timeout/Kill/uncaught) aufgerufen — der try/catch in
     * handle() greift dort NICHT. Räumt hängengebliebene 'running'/'started'-Zustände des Teams auf,
     * damit Connector-Status und Sync-Log nicht ewig „läuft" zeigen. Darf selbst nie werfen.
     */
    public function failed(\Throwable $e): void
    {
        try {
            AssetConnectorConfig::where('team_id', $this->teamId)
                ->where('sync_status', 'running')
                ->update(['sync_status' => 'error', 'sync_error' => $e->getMessage()]);

            $this->failStuckSyncLogs(AssetDeviceSyncLog::class, $this->teamId, $e->getMessage());
        } catch (\Throwable $ignored) {
            // failed() muss schweigend bleiben
        }
    }
}
