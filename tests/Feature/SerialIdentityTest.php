<?php

/**
 * Host-Feature-Test (post-deploy, im Modul-Repo nicht lokal ausführbar — vgl. tests/README.md).
 *
 * ADR 0006 — Geräte-Identität = Seriennummer; `intune_id` ist flüchtig. Verifiziert, dass ein
 * plattgemachtes / neu eingebundenes Gerät (neue Intune-id, gleiche Serial) per Seriennummer auf
 * DERSELBEN Zeile aktualisiert wird (id rotiert, Override/Lifecycle bleiben), statt ein Duplikat
 * anzulegen — und dass serial-lose Geräte korrekt auf das `intune_id`-Matching zurückfallen.
 *
 * Der echte SyncIntuneDevicesJob wird synchron ausgeführt; nur der Graph-Client (IntuneGraphService)
 * ist gefaked — die Matching-/Reconcile-Logik läuft unverändert produktiv.
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetDeviceSource;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\Core\Models\Team;
use Tests\TestCase;

class SerialIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_serial_trims_uppercases_and_rejects_junk(): void
    {
        $this->assertSame('ABC123', AssetDevice::normalizeSerial('  abc123 '));
        $this->assertSame('ABC123', AssetDevice::normalizeSerial('ABC123'));

        $this->assertNull(AssetDevice::normalizeSerial(null));
        $this->assertNull(AssetDevice::normalizeSerial(''));
        $this->assertNull(AssetDevice::normalizeSerial('   '));
        $this->assertNull(AssetDevice::normalizeSerial('To Be Filled By O.E.M.'));
        $this->assertNull(AssetDevice::normalizeSerial('0000000'));
        $this->assertNull(AssetDevice::normalizeSerial('n/a'));
        $this->assertNull(AssetDevice::normalizeSerial('Default string'));
    }

    /** Konfigurierter, aktiver Connector (isConfigured() = azure_tenant_id + client_id + client_secret). */
    private function configuredConnector(Team $team, AssetTenant $tenant): AssetConnectorConfig
    {
        return AssetConnectorConfig::create([
            'team_id'              => $team->id,
            'tenant_id'            => $tenant->id,
            'enabled'              => true,
            'azure_tenant_id'      => '11111111-1111-1111-1111-111111111111',
            'client_id'            => 'client-abc',
            'client_secret'        => 'secret-xyz',
            'consent_confirmed_at' => now(),
        ]);
    }

    /** Graph-Client durch einen Mock ersetzen, der die übergebene Geräteliste liefert. */
    private function fakeGraphDevices(array $devices): void
    {
        $this->mock(IntuneGraphService::class, function ($mock) use ($devices) {
            $mock->shouldReceive('getManagedDevices')->andReturn($devices);
        });
    }

    public function test_reenrollment_same_serial_updates_in_place_and_rotates_intune_id(): void
    {
        $team      = Team::factory()->create();
        $tenant    = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $connector = $this->configuredConnector($team, $tenant);

        $existing = AssetDevice::factory()->create([
            'team_id'          => $team->id,
            'tenant_id'        => $tenant->id,
            'intune_id'        => 'old-enrollment',
            'serial_number'    => 'SN-PIN-1',
            'monthly_cost'     => 19.99,
            'lifecycle_status' => 'in_use',
        ]);

        // Dasselbe physische Gerät kommt mit NEUER Intune-id, gleicher Serial zurück.
        $this->fakeGraphDevices([[
            'id'                => 'new-enrollment',
            'serialNumber'      => 'SN-PIN-1',
            'deviceName'        => 'NB-REENROLL',
            'userPrincipalName' => 'neu@example.test',
            'manufacturer'      => 'LENOVO',
            'model'             => 'X1',
            'complianceState'   => 'compliant',
        ]]);

        SyncIntuneDevicesJob::dispatchSync($connector->id);

        $rows = AssetDevice::withTrashed()
            ->where('tenant_id', $tenant->id)->where('serial_number', 'SN-PIN-1')->get();

        $this->assertCount(1, $rows, 'Re-Enrollment darf kein Duplikat anlegen.');
        $fresh = $rows->first();
        $this->assertSame($existing->id, $fresh->id, 'Gleiche Zeile (kein Neu-Insert).');
        $this->assertSame('new-enrollment', $fresh->intune_id, 'intune_id wurde rotiert.');
        $this->assertFalse($fresh->trashed(), 'Im Payload + serial-gematcht → nicht soft-deleted.');
        $this->assertEqualsWithDelta(19.99, (float) $fresh->monthly_cost, 0.01, 'Kosten-Override bleibt erhalten.');

        $this->assertSame(1, AssetDeviceEvent::where('asset_device_id', $existing->id)
            ->where('event_type', 'reenrolled')->count(), 'Ein reenrolled-Event protokolliert.');
    }

    public function test_serialless_device_falls_back_to_intune_id_and_creates_new_row(): void
    {
        $team      = Team::factory()->create();
        $tenant    = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $connector = $this->configuredConnector($team, $tenant);

        // Bestehendes Gerät mit Schrott-Serial (zählt nicht als Identität).
        $existing = AssetDevice::factory()->create([
            'team_id'          => $team->id,
            'tenant_id'        => $tenant->id,
            'intune_id'        => 'old-junk',
            'serial_number'    => '0000000',
            'lifecycle_status' => 'in_use',
        ]);

        // Neue Intune-id, wieder Schrott-Serial → kein Serial-Merge, Fallback intune_id → neue Zeile.
        $this->fakeGraphDevices([[
            'id'              => 'new-junk',
            'serialNumber'    => '0000000',
            'deviceName'      => 'NB-NOSERIAL',
            'complianceState' => 'compliant',
        ]]);

        SyncIntuneDevicesJob::dispatchSync($connector->id);

        // Aktive Geräte: nur das neue; das alte wurde von reconcile soft-deleted (in_use, nicht im Payload).
        $active = AssetDevice::where('tenant_id', $tenant->id)->get();
        $this->assertCount(1, $active, 'Serial-los → kein Merge, neue Zeile.');
        $this->assertSame('new-junk', $active->first()->intune_id);
        $this->assertNotSame($existing->id, $active->first()->id, 'Andere Zeile als das alte Gerät.');
        $this->assertSame(2, AssetDevice::withTrashed()->where('tenant_id', $tenant->id)->count());
        $this->assertNotNull(AssetDevice::withTrashed()->find($existing->id)?->deleted_at, 'Altes Gerät soft-deleted.');
    }

    /**
     * ADR 0009 — Provider-Seam: Der Sync pflegt je Gerät genau eine 'intune'-Quell-Zeile; deren
     * external_id rotiert bei Re-Enrollment mit der intune_id mit (gleiche Zeile, kein Duplikat).
     */
    public function test_sync_maintains_single_intune_device_source_and_rotates_external_id(): void
    {
        $team      = Team::factory()->create();
        $tenant    = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $connector = $this->configuredConnector($team, $tenant);

        $existing = AssetDevice::factory()->create([
            'team_id'          => $team->id,
            'tenant_id'        => $tenant->id,
            'intune_id'        => 'enr-1',
            'serial_number'    => 'SN-SRC-1',
            'lifecycle_status' => 'in_use',
        ]);

        $this->fakeGraphDevices([[
            'id'              => 'enr-2',          // Re-Enrollment: neue Intune-id, gleiche Serial
            'serialNumber'    => 'SN-SRC-1',
            'deviceName'      => 'NB-SRC',
            'complianceState' => 'compliant',
        ]]);

        SyncIntuneDevicesJob::dispatchSync($connector->id);

        $sources = AssetDeviceSource::where('asset_device_id', $existing->id)
            ->where('provider', 'intune')->get();

        $this->assertCount(1, $sources, 'Genau eine intune-Quell-Zeile je Gerät.');
        $this->assertSame('enr-2', $sources->first()->external_id, 'external_id ist mit der intune_id mitrotiert.');
        $this->assertSame('SN-SRC-1', $sources->first()->serial_number);
    }
}
