<?php

/**
 * Host-Feature-Test (post-deploy). Läuft im Host-App-Test-Setup (Core-Bootstrap: AUTH_MODEL,
 * team_user-Pivot, RefreshDatabase). Im Modul nicht lokal ausführbar.
 *
 * Deckt die Reconciliation-Invariante des Kostenmodells ab:
 *   CostAggregationService::totalMonthly($teamId)['total']
 *     === CostAggregationService::costCenterByType($teamId)['grandTotal']
 *
 * Beide leiten ihre Beträge aus DERSELBEN Postenliste (normalizedLines) ab — der Test
 * verifiziert das über einen Mix aus cost_line- und hardware_afa-Quellen.
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\Core\Models\Team;
use Tests\TestCase;

class CostReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(): Team
    {
        // TODO(host): falls die Host-App eine TeamFactory mit Pflichtfeldern (z. B. user_id) erzwingt,
        // hier entsprechend ->for(...) ergänzen. Asset-Manager braucht nur team_id als Skopierungs-Achse.
        return Team::factory()->create();
    }

    public function test_total_monthly_reconciles_with_cost_center_by_type_grand_total(): void
    {
        $team   = $this->makeTeam();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $center = AssetCostCenter::factory()->create(['team_id' => $team->id]);

        // Eine cost_line-Kostenart + eine hardware_afa-Kostenart (zwei verschiedene aggregation_sources).
        $lineType = AssetCostType::factory()->create(['team_id' => $team->id]);                 // cost_line
        $afaType  = AssetCostType::factory()->hardwareAfa()->create(['team_id' => $team->id]);  // hardware_afa

        $employee = AssetEmployee::factory()->create([
            'team_id'        => $team->id,
            'tenant_id'      => $tenant->id,
            'cost_center_id' => $center->id,
        ]);

        // 1) Manuelle, aktive, aktuell gültige cost_line: amount 100, monthly → monthly_amount 100.
        AssetCostLine::factory()->create([
            'team_id'        => $team->id,
            'cost_type_id'   => $lineType->id,
            'cost_center_id' => $center->id,
            'amount'         => 100.00,
            'frequency'      => 'monthly',
            'active'         => true,
        ]);

        // 2) Hardware-AfA-Item, einem Mitarbeiter zugewiesen: 1200 / 24 Monate = 50,00/Monat.
        // category_id ist NOT NULL (FK auf asset_categories) — die Migration seedet Default-Kategorien,
        // daher existiert hier mindestens eine. Kein AssetItem-Factory verlangt → direkt create().
        $category = AssetCategory::firstOrCreate(['key' => 'laptop'], ['name' => 'Laptop']);
        AssetItem::create([
            'team_id'             => $team->id,
            'tenant_id'           => $tenant->id,
            'category_id'         => $category->id,
            'source'              => 'manual',
            'name'                => 'Test-Laptop',
            'assignee_id'         => $employee->id,
            'status'              => 'assigned',
            'purchase_price'      => 1200.00,
            'depreciation_months' => 24,
            'purchase_date'       => now(),
        ]);

        $service = new CostAggregationService();

        $total      = $service->totalMonthly($team->id);
        $pivot      = $service->costCenterByType($team->id);
        $grandTotal = $pivot['grandTotal'];

        // Exakte Schlüssel laut Service: totalMonthly() liefert hardware/licenses/costlines/total/capacity;
        // costCenterByType() liefert u. a. grandTotal. Die Reconciliation-Invariante:
        $this->assertEqualsWithDelta($total['total'], $grandTotal, 0.01,
            'totalMonthly()[total] muss costCenterByType()[grandTotal] entsprechen.');

        // Sanity: die erwarteten Buckets stimmen (100 cost_line + 50 hardware-afa = 150 gesamt).
        $this->assertEqualsWithDelta(100.00, $total['costlines'], 0.01);
        $this->assertEqualsWithDelta(50.00, $total['hardware'], 0.01);
        $this->assertEqualsWithDelta(150.00, $total['total'], 0.01);
    }

    public function test_once_cost_line_does_not_inflate_monthly_total(): void
    {
        $team = $this->makeTeam();
        $type = AssetCostType::factory()->create(['team_id' => $team->id]); // cost_line

        // once → FREQUENCY_FACTORS['once']=0.0 → monthly_amount=0 (Einmalkosten zählen nicht monatlich).
        AssetCostLine::factory()->once()->create([
            'team_id'      => $team->id,
            'cost_type_id' => $type->id,
            'amount'       => 999.00,
            'active'       => true,
        ]);

        $service = new CostAggregationService();
        $total   = $service->totalMonthly($team->id);

        $this->assertEqualsWithDelta(0.00, $total['total'], 0.01,
            'Eine once-Position darf das Monats-Total nicht erhöhen (monthly_amount=0).');
    }
}
