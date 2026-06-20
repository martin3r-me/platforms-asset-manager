<?php

/**
 * Host-Feature-Test (post-deploy). Läuft im Host-App-Test-Setup (Core-Bootstrap: AUTH_MODEL,
 * team_user-Pivot, RefreshDatabase). Im Modul nicht lokal ausführbar.
 *
 * Höchster Wert: regressionsschützt die beiden Kern-Leitplanken der Schreibpfade.
 *   M2 — Cross-Team-FK: eine cost_line, die auf eine FREMD-Team-Kostenart/-Kreditor zeigt, wird als
 *        Validierungsfehler abgelehnt (kein DB-Write), nicht still übernommen.
 *   M3 — Autorisierungs-Parität: ein einfaches Mitglied darf NICHT schreiben (asset-manager.manage
 *        verweigert), Owner/Admin schon.
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Platform\AssetManager\Livewire\CostLines\Index as CostLinesIndex;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Support\TeamRole;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Tests\TestCase;

class CostLineScopingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Legt einen User an, hängt ihn mit $role ans Team und setzt es als aktuelles UI-Team.
     * currentTeam fällt ohne Modul-Route-Segment auf current_team_id zurück (Core User-Attribut).
     */
    private function userInTeam(Team $team, string $role): User
    {
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user->id, ['role' => $role]);

        return $user->fresh();
    }

    public function test_owner_can_save_cost_line_with_own_team_cost_type(): void
    {
        $team  = Team::factory()->create();
        $owner = $this->userInTeam($team, 'owner');
        $type  = AssetCostType::factory()->create(['team_id' => $team->id]);

        $this->actingAs($owner);

        Livewire::test(CostLinesIndex::class)
            ->call('newLine')
            ->set('fCostType', $type->id)
            ->set('fLabel', 'Mobilfunk Vertrag')
            ->set('fAmount', '49.90')
            ->set('fFrequency', 'monthly')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('asset_cost_lines', [
            'team_id'      => $team->id,
            'cost_type_id' => $type->id,
            'label'        => 'Mobilfunk Vertrag',
            'source'       => 'manual',
        ]);
    }

    public function test_save_with_foreign_team_cost_type_is_rejected_and_not_written(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $owner = $this->userInTeam($teamA, 'owner');

        // Kostenart gehört Team B — darf in Team A NICHT als FK übernommen werden (M2).
        $foreignType = AssetCostType::factory()->create(['team_id' => $teamB->id]);

        $this->actingAs($owner);

        Livewire::test(CostLinesIndex::class)
            ->call('newLine')
            ->set('fCostType', $foreignType->id)
            ->set('fLabel', 'Cross-Team Versuch')
            ->set('fAmount', '49.90')
            ->set('fFrequency', 'monthly')
            ->call('save')
            // Rule::exists(...)->where('team_id', $teamA->id) greift → Validierungsfehler auf fCostType.
            ->assertHasErrors('fCostType');

        // Kein DB-Write — weder im eigenen noch im fremden Team.
        $this->assertDatabaseMissing('asset_cost_lines', ['label' => 'Cross-Team Versuch']);
        $this->assertSame(0, AssetCostLine::count());
    }

    public function test_save_with_foreign_team_vendor_is_rejected(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $owner = $this->userInTeam($teamA, 'owner');

        $type          = AssetCostType::factory()->create(['team_id' => $teamA->id]);
        $foreignVendor = AssetVendor::create(['team_id' => $teamB->id, 'name' => 'Fremd GmbH']);

        $this->actingAs($owner);

        Livewire::test(CostLinesIndex::class)
            ->call('newLine')
            ->set('fCostType', $type->id)
            ->set('fLabel', 'Mit Fremd-Kreditor')
            ->set('fAmount', '49.90')
            ->set('fVendor', $foreignVendor->id)
            ->set('fFrequency', 'monthly')
            ->call('save')
            ->assertHasErrors('fVendor');

        $this->assertDatabaseMissing('asset_cost_lines', ['label' => 'Mit Fremd-Kreditor']);
    }

    public function test_plain_member_is_denied_write(): void
    {
        $team   = Team::factory()->create();
        $member = $this->userInTeam($team, 'member');
        $type   = AssetCostType::factory()->create(['team_id' => $team->id]);

        $this->actingAs($member);

        // save() ruft Gate::authorize('asset-manager.manage') → für ein einfaches Mitglied verboten.
        Livewire::test(CostLinesIndex::class)
            ->call('newLine')
            ->set('fCostType', $type->id)
            ->set('fLabel', 'Member darf nicht')
            ->set('fAmount', '10.00')
            ->set('fFrequency', 'monthly')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, AssetCostLine::count());
    }

    public function test_team_role_gate_distinguishes_member_from_owner(): void
    {
        $team   = Team::factory()->create();
        $owner  = $this->userInTeam($team, 'owner');
        $admin  = $this->userInTeam($team, 'admin');
        $member = $this->userInTeam($team, 'member');

        $this->assertTrue(TeamRole::isOwnerOrAdmin($owner));
        $this->assertTrue(TeamRole::isOwnerOrAdmin($admin));
        $this->assertFalse(TeamRole::isOwnerOrAdmin($member));

        // Direkt über die Gate-Ability, die jeden Schreibpfad (UI + MCP) fronted.
        $this->assertTrue(Gate::forUser($owner)->allows('asset-manager.manage'));
        $this->assertFalse(Gate::forUser($member)->allows('asset-manager.manage'));
    }
}
