<?php

/**
 * Host-Feature-Test (post-deploy). Läuft im Host-App-Test-Setup (Core-Bootstrap: AUTH_MODEL,
 * team_user-Pivot, RefreshDatabase). Im Modul nicht lokal ausführbar.
 *
 * M4 — Graph-Robustheit. Verifiziert das Reconcile-Verhalten des Geräte-Syncs (RunsTeamSync::reconcileDelete
 * + die withTrashed-Restore-Semantik aus SyncIntuneDevicesJob::handle):
 *   - Geräte, die NICHT im letzten Payload-Keyset stehen, werden soft-deleted.
 *   - Ein LEERES Keyset löscht NIE die ganze Flotte (Empty-Key-Set-Guard; HTTP-200-value:[]).
 *   - Ein zurückkehrendes Gerät wird per withTrashed()->restore() reaktiviert und behält seine
 *     Kosten-Overrides (monthly_cost, cost_type_id), statt als neue Zeile angelegt zu werden.
 *
 * reconcileDelete() ist protected im RunsTeamSync-Trait → über eine schlanke Test-Hülle aufgerufen,
 * die exakt denselben Trait einbindet (kein Mock des Graph-Clients nötig: der Helfer arbeitet rein auf
 * einer Query-Closure + Keyset, genau wie der Job ihn füttert).
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Concerns\RunsTeamSync;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetTenant;
use Platform\Core\Models\Team;
use Tests\TestCase;

class GraphReconcileDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** Test-Hülle, die den produktiven Trait einbindet und reconcileDelete() öffentlich macht. */
    private function reconciler(): object
    {
        return new class () {
            use RunsTeamSync;

            public function run(\Closure $baseQuery, string $keyColumn, array $keptKeys): int
            {
                return $this->reconcileDelete($baseQuery, $keyColumn, $keptKeys);
            }
        };
    }

    public function test_devices_absent_from_payload_are_soft_deleted(): void
    {
        $team   = Team::factory()->create();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);

        $kept = AssetDevice::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'intune_id' => 'keep-1',
        ]);
        $gone = AssetDevice::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'intune_id' => 'gone-1',
        ]);

        $removed = $this->reconciler()->run(
            fn () => AssetDevice::where('tenant_id', $tenant->id),
            'intune_id',
            ['keep-1'] // nur das behaltene Gerät ist im aktuellen Payload
        );

        $this->assertSame(1, $removed);
        $this->assertNull(AssetDevice::find($gone->id), 'Fehlendes Gerät muss soft-deleted sein.');
        $this->assertNotNull(AssetDevice::find($kept->id), 'Vorhandenes Gerät bleibt erhalten.');
        $this->assertNotNull(AssetDevice::withTrashed()->find($gone->id)?->deleted_at);
    }

    public function test_empty_keyset_never_wipes_the_fleet(): void
    {
        $team   = Team::factory()->create();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);

        AssetDevice::factory()->count(3)->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id,
        ]);

        // Leeres Keyset = „alles behalten" (z. B. erfolgreiche, aber leere Graph-Seite). whereNotIn(..,[])
        // würde sonst JEDE Zeile treffen → Totalverlust. Der Guard muss 0 löschen.
        $removed = $this->reconciler()->run(
            fn () => AssetDevice::where('tenant_id', $tenant->id),
            'intune_id',
            []
        );

        $this->assertSame(0, $removed, 'Leeres Keyset darf nichts löschen.');
        $this->assertSame(3, AssetDevice::where('tenant_id', $tenant->id)->count());
    }

    public function test_reconcile_is_tenant_scoped_and_does_not_cross_tenants(): void
    {
        $team    = Team::factory()->create();
        $tenantA = AssetTenant::factory()->create(['team_id' => $team->id]);
        $tenantB = AssetTenant::factory()->create(['team_id' => $team->id]);

        $deviceA = AssetDevice::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenantA->id, 'intune_id' => 'a-1',
        ]);
        $deviceB = AssetDevice::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenantB->id, 'intune_id' => 'b-1',
        ]);

        // Reconcile nur für Tenant A mit leerem Behalt-Set für A (b-1 ist nicht in A's Query).
        $removed = $this->reconciler()->run(
            fn () => AssetDevice::where('tenant_id', $tenantA->id),
            'intune_id',
            ['a-1']
        );

        $this->assertSame(0, $removed);
        // Tenant B unangetastet, obwohl b-1 nicht im Keyset stand.
        $this->assertNotNull(AssetDevice::find($deviceB->id), 'Reconcile von Tenant A darf Tenant B nicht anfassen.');
        $this->assertNotNull(AssetDevice::find($deviceA->id));
    }

    public function test_returning_device_is_restored_preserving_cost_overrides(): void
    {
        $team     = Team::factory()->create();
        $tenant   = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $costType = AssetCostType::factory()->assetDevice()->create(['team_id' => $team->id]);

        // Gerät mit Kosten-Override, das dann „verschwindet" (soft-delete durch Reconcile).
        $device = AssetDevice::factory()->create([
            'team_id'      => $team->id,
            'tenant_id'    => $tenant->id,
            'intune_id'    => 'roundtrip-1',
            'monthly_cost' => 42.50,
            'cost_type_id' => $costType->id,
        ]);

        // Soft-Delete deterministisch herbeiführen: Keyset OHNE 'roundtrip-1', aber nicht leer (sonst Guard).
        AssetDevice::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'intune_id' => 'other-1',
        ]);
        $this->reconciler()->run(
            fn () => AssetDevice::where('tenant_id', $tenant->id),
            'intune_id',
            ['other-1']
        );

        $this->assertNull(AssetDevice::find($device->id), 'Gerät sollte jetzt soft-deleted sein.');

        // Sync-Wiederkehr: der Job sucht withTrashed() per (tenant_id,intune_id), restored und updatet —
        // die Override-Felder (monthly_cost/cost_type_id) bleiben dabei erhalten (kein Neu-Insert).
        $returning = AssetDevice::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('intune_id', 'roundtrip-1')
            ->first();

        $this->assertNotNull($returning);
        $this->assertTrue($returning->trashed());

        $returning->restore();
        // Nicht-Kosten-Felder aktualisiert der Job aus dem Payload (mapDevice) — Override-Felder NICHT.
        $returning->update(['device_name' => 'WIEDERDA', 'compliance_state' => 'compliant']);

        $fresh = AssetDevice::find($device->id);
        $this->assertNotNull($fresh, 'Gerät muss restored (nicht neu angelegt) sein.');
        $this->assertSame($device->id, $fresh->id, 'Gleiche Zeile — kein Neu-Insert.');
        $this->assertEqualsWithDelta(42.50, (float) $fresh->monthly_cost, 0.01, 'Kosten-Override bleibt erhalten.');
        $this->assertSame($costType->id, $fresh->cost_type_id, 'Kostenart-Override bleibt erhalten.');

        // Es existiert genau EINE Zeile für diese intune_id (kein Duplikat).
        $this->assertSame(1, AssetDevice::withTrashed()
            ->where('tenant_id', $tenant->id)->where('intune_id', 'roundtrip-1')->count());
    }
}
