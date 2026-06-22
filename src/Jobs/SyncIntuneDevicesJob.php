<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetEmployee;
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

    /** Eindeutigkeits-Sperre nach 10 Min automatisch freigeben (> $timeout=300s, damit ein laufender
     *  Job nie vorzeitig dedupliziert wird). Verhindert, dass eine geleakte Sperre — etwa nach einem
     *  Worker-Neustart/Deploy mitten im Job — künftige Dispatches dauerhaft stillschweigend verschluckt. */
    public int $uniqueFor = 600;

    /** Team-/Tenant-Kontext des Connectors — in handle() gesetzt, von den Helfern genutzt (nicht serialisiert). */
    protected ?int $teamId   = null;
    protected ?int $tenantId = null;

    /** Serialisiert parallele Geraete-Syncs DESSELBEN Connectors (Multi-Tenant: ein Connector = ein Tenant) —
     *  schliesst das check-then-act-Rennen (first()->create()) gegen den (tenant_id,intune_id)-Unique-Index. */
    public function uniqueId(): string
    {
        return (string) $this->connectorId;
    }

    public function __construct(
        public readonly int $connectorId
    ) {}

    /**
     * Fan-out: dispatcht je aktivem, konfiguriertem Connector des Teams einen eigenen Job.
     * Team-Level-Auslöser (UI-Button „Jetzt synchronisieren", MCP-Tool) gehen hierüber.
     */
    public static function dispatchForTeam(int $teamId): int
    {
        $count = 0;
        foreach (AssetConnectorConfig::where('team_id', $teamId)->where('enabled', true)->get() as $config) {
            if (!$config->isConfigured()) continue;
            // Consent-Guard (M15): einen Connector ohne bestätigten Admin-Consent NICHT dispatchen — der
            // Lauf würde garantiert mit 403 scheitern und den sync_status auf 'error' kippen (statt
            // 'pending'). Die Console-Commands prüfen das bereits; UI/MCP gingen bisher ungeprüft hierüber.
            if (!$config->isConsentConfirmed()) continue;
            self::dispatch($config->id);
            $count++;
        }
        return $count;
    }

    public function handle(IntuneGraphService $service, EmployeeService $employeeService): void
    {
        $config = AssetConnectorConfig::where('id', $this->connectorId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured() || !$config->tenant_id) {
            Log::warning('AssetManager: Sync übersprungen — Connector nicht konfiguriert/ohne Tenant', ['connector_id' => $this->connectorId]);
            return;
        }

        $this->teamId   = $config->team_id;
        $this->tenantId = $config->tenant_id;

        $startedAt = now();
        $log = AssetDeviceSyncLog::create([
            'team_id'    => $this->teamId,
            'tenant_id'  => $this->tenantId,
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

            // N+1-Vermeidung: bestehende Geräte (inkl. soft-deleted), Modell-Schlüssel und Employee-UPNs
            // des Tenants EINMAL vorladen statt je Gerät zu queryen. ShouldBeUnique pro Connector schließt
            // nebenläufige Syncs desselben Tenants aus → der vorgeladene Stand bleibt während des Laufs gültig.
            $existingDevices = AssetDevice::withTrashed()
                ->where('tenant_id', $this->tenantId)
                ->get();
            $existingByIntuneId = $existingDevices->keyBy('intune_id');

            // Serial-first-Identität (ADR 0006): zweiter Lookup über die normalisierte Seriennummer, damit ein
            // plattgemachtes/neu eingebundenes Gerät (neue intune_id, gleiche Serial) AKTUALISIERT statt neu
            // angelegt wird. Bei (Alt-)Duplikaten gewinnt deterministisch die niedrigste id.
            $existingBySerial = [];
            foreach ($existingDevices->sortBy('id') as $d) {
                $key = AssetDevice::normalizeSerial($d->serial_number);
                if ($key !== null && !isset($existingBySerial[$key])) {
                    $existingBySerial[$key] = $d;
                }
            }

            $knownModelKeys = [];
            foreach (AssetDeviceModel::where('team_id', $this->teamId)->get(['manufacturer', 'model']) as $m) {
                $knownModelKeys[($m->manufacturer ?? '') . "\0" . ($m->model ?? '')] = true;
            }

            $knownUpns = AssetEmployee::where('tenant_id', $this->tenantId)
                ->whereNotNull('user_principal_name')
                ->pluck('user_principal_name')
                ->flip();

            foreach ($devices as $device) {
                $intuneIds[] = $device['id'];

                $data = $this->mapDevice($device);

                // Identität (ADR 0006): erst exakter Enrollment-Treffer (Fast-Path, intune_id ist je Tenant
                // eindeutig). Kein Treffer + brauchbare Serial → dasselbe PHYSISCHE Gerät unter neuer
                // intune_id (Wipe/Re-Enrollment/Nutzerwechsel). withTrashed: ein zuvor verschwundenes
                // (soft-deleted) Gerät wird restored statt neu angelegt — Kosten-Overrides bleiben erhalten.
                $existing        = $existingByIntuneId->get($device['id']);
                $matchedBySerial = false;
                if (!$existing) {
                    $serial = AssetDevice::normalizeSerial($device['serialNumber'] ?? null);
                    if ($serial !== null && isset($existingBySerial[$serial])) {
                        $existing        = $existingBySerial[$serial];
                        $matchedBySerial = true;
                    }
                }

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    // Serial-Treffer mit abweichender intune_id → id auf DERSELBEN Zeile rotieren (die alte
                    // Enrollment-id existiert danach nirgends mehr; (tenant_id,intune_id) bleibt eindeutig).
                    if ($matchedBySerial && $existing->intune_id !== $device['id']) {
                        $this->recordEvent($existing, 'reenrolled', 'Neu eingebunden – Intune-ID gewechselt', $existing->intune_id, $device['id']);
                        $data['intune_id'] = $device['id'];
                    }
                    // VOR dem update(): $existing trägt noch die alten Werte → Verlaufs-Diff möglich.
                    $this->recordChangeEvents($existing, $data);
                    $existing->update($data);
                    $updated++;
                    // Aktualisierten Datensatz unter der (ggf. neuen) intune_id im Lookup halten (Defensive
                    // gegen mehrere eingehende Geräte, die auf dieselbe Zeile zeigen würden).
                    $existingByIntuneId->put($existing->intune_id, $existing);
                } else {
                    $created = AssetDevice::create(array_merge($data, [
                        'team_id'         => $this->teamId,
                        'tenant_id'       => $this->tenantId,
                        'azure_tenant_id' => $config->azure_tenant_id,
                        'intune_id'       => $device['id'],
                        'source'          => 'intune',
                    ]));
                    $this->recordEvent($created, 'created', 'Gerät erstmals aus Intune erfasst');
                    $added++;
                    // Neuen Datensatz in beide Lookups aufnehmen — sonst träfe ein zweites Gerät mit
                    // derselben Serial im selben Lauf erneut den else-Zweig und legte ein Duplikat an.
                    $existingByIntuneId->put($created->intune_id, $created);
                    $newSerial = AssetDevice::normalizeSerial($created->serial_number);
                    if ($newSerial !== null && !isset($existingBySerial[$newSerial])) {
                        $existingBySerial[$newSerial] = $created;
                    }
                }

                // Geräte-Modell-Katalog pflegen — team-weit (Teil des Kostenmodells, NICHT tenant-skopiert).
                // Nur anlegen, wenn der (exakte) Schlüssel noch nicht bekannt ist → kein firstOrCreate-SELECT
                // je Gerät. Neu angelegte Schlüssel sofort merken (mehrere Geräte desselben Modells im Lauf).
                if (!empty($data['manufacturer']) || !empty($data['model'])) {
                    $modelKey = ($data['manufacturer'] ?? '') . "\0" . ($data['model'] ?? '');
                    if (!isset($knownModelKeys[$modelKey])) {
                        AssetDeviceModel::firstOrCreate([
                            'team_id'      => $this->teamId,
                            'manufacturer' => $data['manufacturer'],
                            'model'        => $data['model'],
                        ]);
                        $knownModelKeys[$modelKey] = true;
                    }
                }

                // Employee aus UPN ableiten (Intune liefert userPrincipalName). Nur für noch unbekannte UPNs
                // inline anlegen — bestehende werden vom backfillForTenant-Safety-Net (unten) nachgepflegt.
                if (!empty($device['userPrincipalName']) && !$knownUpns->has($device['userPrincipalName'])) {
                    $employeeService->findOrCreateByUpn(
                        $this->teamId,
                        $this->tenantId,
                        $device['userPrincipalName'],
                        $device['userDisplayName'] ?? null,
                        'derived'
                    );
                    $knownUpns->put($device['userPrincipalName'], true);
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
                // Scope strikt pro Tenant — kein Kreuz-Löschen zwischen Tenants desselben Teams.
                // Lifecycle-Pin (ADR 0007): terminal gesetzte Geräte (retired/lost/defect) NICHT soft-löschen,
                // auch wenn sie aus Intune verschwinden — sie bleiben getrackter Inventar-Datensatz. NULL und
                // nicht-terminale Status folgen weiter der Intune-Präsenz; NULL muss dafür explizit
                // eingeschlossen werden (ein nacktes whereNotIn schlösse NULL-Zeilen aus → reconcile liefe leer).
                $removed = $this->reconcileDelete(
                    fn () => AssetDevice::where('tenant_id', $this->tenantId)
                        ->where(fn ($q) => $q->whereNull('lifecycle_status')
                                             ->orWhereNotIn('lifecycle_status', AssetDevice::TERMINAL_LIFECYCLE)),
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

            // Safety-Net: nochmal alle UPNs einsammeln (fängt Bestandsdaten ab)
            $employeeService->backfillForTenant($this->teamId, $this->tenantId);

            Log::info('AssetManager: Sync erfolgreich', [
                'connector_id' => $this->connectorId,
                'tenant_id'    => $this->tenantId,
                'synced'       => count($devices),
                'added'        => $added,
                'updated'      => $updated,
                'removed'      => $removed,
                'duration'     => $durationMs . 'ms',
            ]);

        } catch (\Throwable $e) {
            $this->markFailed($config, $log, $startedAt, $e->getMessage());
            Log::error('AssetManager: Sync-Exception', [
                'connector_id' => $this->connectorId,
                'error'        => $e->getMessage(),
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
            'enrolled_at'         => $this->graphDateTime($d['enrolledDateTime'] ?? null),
            'last_check_in_at'    => $this->graphDateTime($d['lastSyncDateTime'] ?? null),
            'is_encrypted'          => $d['isEncrypted'] ?? null,
            'enrollment_type'       => $d['deviceEnrollmentType'] ?? null,
            'free_storage_bytes'    => $d['freeStorageSpaceInBytes'] ?? null,
            'total_storage_bytes'   => $d['totalStorageSpaceInBytes'] ?? null,
            'physical_memory_bytes' => $d['physicalMemoryInBytes'] ?? null,
            'raw_data'            => $d,
        ];
    }

    /**
     * Microsoft Graph liefert für „nie gesetzt" das Sentinel-Datum 0001-01-01T00:00:00Z
     * (z. B. lastSyncDateTime bei nie eingecheckten Geräten). Eine MySQL-TIMESTAMP-Spalte
     * (gültig 1970–2038) lehnt das mit Fehler 1292 ab und würde den GANZEN Sync sprengen.
     * Solche Sentinel-/Vor-Epoch-Werte sowie unparsbare Strings daher zu NULL normalisieren.
     */
    protected function graphDateTime(?string $value): ?\Carbon\Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            $dt = \Carbon\Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }

        return $dt->year < 1970 ? null : $dt;
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
                'tenant_id'       => $this->tenantId,
                'asset_device_id' => $device->id,
                'event_type'      => $type,
                'description'     => $description,
                'old_value'       => $old,
                'new_value'       => $new,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Geräte-Event nicht geschrieben', [
                'connector_id' => $this->connectorId,
                'error'        => $e->getMessage(),
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
     * handle() greift dort NICHT. Räumt hängengebliebene 'running'/'started'-Zustände des Connectors auf,
     * damit Connector-Status und Sync-Log nicht ewig „läuft" zeigen. Darf selbst nie werfen.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $config = AssetConnectorConfig::find($this->connectorId);
            if (!$config) return;

            if ($config->sync_status === 'running') {
                $config->update(['sync_status' => 'error', 'sync_error' => $e->getMessage()]);
            }

            if ($config->tenant_id) {
                $this->failStuckSyncLogs(AssetDeviceSyncLog::class, $config->tenant_id, $e->getMessage());
            }
        } catch (\Throwable $ignored) {
            // failed() muss schweigend bleiben
        }
    }
}
