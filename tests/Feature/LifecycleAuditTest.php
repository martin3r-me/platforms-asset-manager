<?php

/**
 * Host-Feature-Test (post-deploy, im Modul-Repo nicht lokal ausführbar — vgl. tests/README.md).
 *
 * Track B 2a — Audit manueller Lifecycle-Änderungen: Eine Status-Änderung über die Geräte-Detailseite
 * schreibt ein `lifecycle_changed`-Event mit Akteur (user_id) und altem→neuem Label. Intune liefert den
 * Lifecycle nicht; er wird manuell gepflegt → „wer/wann" ist sonst nirgends nachvollziehbar (L1a).
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Platform\AssetManager\Livewire\Devices\Show as DevicesShow;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetTenant;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Tests\TestCase;

class LifecycleAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Legt einen User an, hängt ihn mit $role ans Team und setzt es als aktuelles UI-Team. */
    private function userInTeam(Team $team, string $role): User
    {
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user->id, ['role' => $role]);

        return $user->fresh();
    }

    public function test_lifecycle_change_records_audit_event_with_actor(): void
    {
        $team   = Team::factory()->create();
        $owner  = $this->userInTeam($team, 'owner');
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $device = AssetDevice::factory()->create([
            'team_id'          => $team->id,
            'tenant_id'        => $tenant->id,
            'lifecycle_status' => 'in_use',
        ]);

        $this->actingAs($owner);

        Livewire::test(DevicesShow::class, ['device' => $device])
            ->call('editLifecycle')
            ->set('lStatus', 'retired')
            ->call('saveLifecycle')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('asset_device_events', [
            'asset_device_id' => $device->id,
            'event_type'      => 'lifecycle_changed',
            'user_id'         => $owner->id,
            'old_value'       => 'In Betrieb',
            'new_value'       => 'Ausgemustert',
        ]);
        $this->assertSame(1, AssetDeviceEvent::where('asset_device_id', $device->id)
            ->where('event_type', 'lifecycle_changed')->count());
    }

    public function test_no_event_when_lifecycle_status_unchanged(): void
    {
        $team   = Team::factory()->create();
        $owner  = $this->userInTeam($team, 'owner');
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $device = AssetDevice::factory()->create([
            'team_id'          => $team->id,
            'tenant_id'        => $tenant->id,
            'lifecycle_status' => 'in_use',
        ]);

        $this->actingAs($owner);

        // Nur ein anderes Lifecycle-Feld ändern (Standort), Status bleibt 'in_use' → kein lifecycle_changed.
        Livewire::test(DevicesShow::class, ['device' => $device])
            ->call('editLifecycle')
            ->set('lLocation', 'Lager Bonn')
            ->call('saveLifecycle')
            ->assertHasNoErrors();

        $this->assertSame(0, AssetDeviceEvent::where('asset_device_id', $device->id)
            ->where('event_type', 'lifecycle_changed')->count());
    }
}
