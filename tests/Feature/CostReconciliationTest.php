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
 *
 * ## Warum zusätzlich die Baum-Fälle (S5, ADR 0016)
 *
 * Vor dem Umbau war die Kostenstelle zweistufig (Gesellschaft → Kostenstelle) und `grandTotal`
 * schlicht die Summe aller Beträge. Seit S5 ist sie ein Baum beliebiger Tiefe mit Rollup je Knoten.
 * **Genau diese Eigenschaft muss erhalten bleiben**: der Rollup ist eine zusätzliche Sicht, keine
 * zusätzliche Summe. Zählt `grandTotal` oder `colTotals` den Rollup mit, erscheint jeder Betrag so
 * oft, wie sein Knoten Vorfahren hat — und die Kostenauswertung ist still zu hoch.
 *
 * Das ist der belastbare Ersatz für „gegen die Zahlen VOR dem Umbau abgeglichen": die Invariante
 * gilt für JEDE Baumform, nicht nur für die eine, die ein bestimmter Kunde gerade hat.
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
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
        // tenant_id überall explizit: die Factories tragen nur den Platzhalter 1, der seit S1
        // (tenant_id NOT NULL) zufällig passen kann — aber nicht muss, sobald ein Test mehrere
        // Tenants anlegt.
        $center = AssetCostCenter::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);

        // Eine cost_line-Kostenart + eine hardware_afa-Kostenart (zwei verschiedene aggregation_sources).
        $lineType = AssetCostType::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);                 // cost_line
        $afaType  = AssetCostType::factory()->hardwareAfa()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);  // hardware_afa

        $holder = AssetHolder::factory()->create([
            'team_id'        => $team->id,
            'tenant_id'      => $tenant->id,
            'cost_center_id' => $center->id,
        ]);

        // 1) Manuelle, aktive, aktuell gültige cost_line: amount 100, monthly → monthly_amount 100.
        AssetCostLine::factory()->create([
            'team_id'        => $team->id,
            'tenant_id'      => $tenant->id,
            'cost_type_id'   => $lineType->id,
            'cost_center_id' => $center->id,
            'amount'         => 100.00,
            'frequency'      => 'monthly',
            'active'         => true,
        ]);

        // 2) Hardware-AfA-Item, einem Asset-Träger zugewiesen: 1200 / 24 Monate = 50,00/Monat.
        // category_id ist NOT NULL (FK auf asset_categories) — die Migration seedet Default-Kategorien,
        // daher existiert hier mindestens eine. Kein AssetItem-Factory verlangt → direkt create().
        $category = AssetCategory::firstOrCreate(['key' => 'laptop'], ['name' => 'Laptop']);
        AssetItem::create([
            'team_id'             => $team->id,
            'tenant_id'           => $tenant->id,
            'category_id'         => $category->id,
            'source'              => 'manual',
            'name'                => 'Test-Laptop',
            'assignee_id'         => $holder->id,
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

    /**
     * Baut einen dreistufigen Kostenstellen-Baum plus einen zweiten, leeren Wurzelknoten.
     *
     *   1000 Gesellschaft            (Wurzel)
     *   └── 1100 Verwaltung          (Kind)
     *       └── 1110 Buchhaltung     (Enkel)
     *   2000 Zweite Gesellschaft     (Wurzel, ohne Beträge)
     *
     * @return array{tenant: AssetTenant, root: AssetCostCenter, child: AssetCostCenter, grandchild: AssetCostCenter, root2: AssetCostCenter}
     */
    private function makeTree(Team $team): array
    {
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $base   = ['team_id' => $team->id, 'tenant_id' => $tenant->id];

        $root = AssetCostCenter::factory()->create($base + [
            'code' => '1000', 'name' => 'Gesellschaft', 'parent_id' => null, 'depth' => 0,
        ]);
        $child = AssetCostCenter::factory()->create($base + [
            'code' => '1100', 'name' => 'Verwaltung', 'parent_id' => $root->id, 'depth' => 1,
        ]);
        $grandchild = AssetCostCenter::factory()->create($base + [
            'code' => '1110', 'name' => 'Buchhaltung', 'parent_id' => $child->id, 'depth' => 2,
        ]);
        $root2 = AssetCostCenter::factory()->create($base + [
            'code' => '2000', 'name' => 'Zweite Gesellschaft', 'parent_id' => null, 'depth' => 0,
        ]);

        return compact('tenant', 'root', 'child', 'grandchild', 'root2');
    }

    /**
     * Der Kern: Beträge auf drei Ebenen dürfen sich im grandTotal NICHT vervielfachen.
     * 100 (Wurzel) + 30 (Kind) + 7 (Enkel) + 5 (ohne Kostenstelle) = 142 — nicht mehr.
     */
    public function test_tree_rollup_does_not_double_count_in_grand_total(): void
    {
        $team = $this->makeTeam();
        ['tenant' => $tenant, 'root' => $root, 'child' => $child, 'grandchild' => $grandchild, 'root2' => $root2]
            = $this->makeTree($team);

        $type = AssetCostType::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);

        foreach ([[$root->id, 100.00], [$child->id, 30.00], [$grandchild->id, 7.00], [null, 5.00]] as [$ccId, $amount]) {
            AssetCostLine::factory()->create([
                'team_id'        => $team->id,
                'tenant_id'      => $tenant->id,
                'cost_type_id'   => $type->id,
                'cost_center_id' => $ccId,
                'amount'         => $amount,
                'frequency'      => 'monthly',
                'active'         => true,
            ]);
        }

        $pivot = (new CostAggregationService())->costCenterByType($team->id, 'monthly', $tenant->id);

        $this->assertEqualsWithDelta(142.00, $pivot['grandTotal'], 0.01,
            'grandTotal muss die Summe der EIGENEN Betraege sein — der Rollup darf nicht mitzaehlen.');

        $this->assertEqualsWithDelta(142.00, array_sum($pivot['colTotals']), 0.01,
            'colTotals duerfen nur eigene Betraege enthalten, sonst zaehlt jeder Betrag je Vorfahr erneut.');

        $rowsByCode = collect($pivot['rows'])->keyBy('code');

        // Eigene Betraege je Knoten
        $this->assertEqualsWithDelta(100.00, $rowsByCode['1000']['rowTotal'], 0.01);
        $this->assertEqualsWithDelta(30.00,  $rowsByCode['1100']['rowTotal'], 0.01);
        $this->assertEqualsWithDelta(7.00,   $rowsByCode['1110']['rowTotal'], 0.01);
        $this->assertEqualsWithDelta(0.00,   $rowsByCode['2000']['rowTotal'], 0.01);

        // Rollup = eigen + alle Nachfahren
        $this->assertEqualsWithDelta(137.00, $rowsByCode['1000']['rollupTotal'], 0.01,
            'Wurzel-Rollup = 100 (eigen) + 30 (Kind) + 7 (Enkel).');
        $this->assertEqualsWithDelta(37.00, $rowsByCode['1100']['rollupTotal'], 0.01,
            'Kind-Rollup = 30 (eigen) + 7 (Enkel).');
        $this->assertEqualsWithDelta(7.00, $rowsByCode['1110']['rollupTotal'], 0.01,
            'Blatt-Rollup = eigener Betrag.');
        $this->assertEqualsWithDelta(0.00, $rowsByCode['2000']['rollupTotal'], 0.01,
            'Knoten ohne eigene Betraege und ohne Kinder bleibt bei 0.');

        // Baum-Struktur im Ergebnis
        $this->assertTrue($rowsByCode['1000']['has_children']);
        $this->assertFalse($rowsByCode['1110']['has_children']);
        $this->assertSame('1100', $rowsByCode['1110']['parent_code']);
        $this->assertSame(2, $rowsByCode['1110']['depth']);
        $this->assertSame($root2->id, $rowsByCode['2000']['cost_center_id']);

        // Posten ohne Kostenstelle: eigene Zeile, aber Teil des grandTotal.
        $this->assertNotNull($pivot['unassigned'], 'Posten ohne Kostenstelle brauchen eine eigene Zeile.');
        $this->assertEqualsWithDelta(5.00, $pivot['unassigned']['rowTotal'], 0.01);
    }

    /** Die Reconciliation-Invariante muss auch auf einem Baum halten — nicht nur flach. */
    public function test_reconciliation_invariant_holds_on_a_tree(): void
    {
        $team = $this->makeTeam();
        ['tenant' => $tenant, 'root' => $root, 'grandchild' => $grandchild] = $this->makeTree($team);

        $type = AssetCostType::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);

        foreach ([[$root->id, 60.00], [$grandchild->id, 40.00]] as [$ccId, $amount]) {
            AssetCostLine::factory()->create([
                'team_id'        => $team->id,
                'tenant_id'      => $tenant->id,
                'cost_type_id'   => $type->id,
                'cost_center_id' => $ccId,
                'amount'         => $amount,
                'frequency'      => 'monthly',
                'active'         => true,
            ]);
        }

        $service = new CostAggregationService();
        $total   = $service->totalMonthly($team->id);
        $pivot   = $service->costCenterByType($team->id, 'monthly', $tenant->id);

        $this->assertEqualsWithDelta($total['total'], $pivot['grandTotal'], 0.01,
            'totalMonthly()[total] muss auch bei hierarchischen Kostenstellen dem grandTotal entsprechen.');
        $this->assertEqualsWithDelta(100.00, $pivot['grandTotal'], 0.01);
    }

    /**
     * Beträge auf einer Kostenstelle, die NICHT im Baum des Tenants steckt (fremder Tenant oder
     * gelöscht), dürfen nicht still verschwinden — sie brauchen die Auffangzeile UND müssen im
     * grandTotal auftauchen. Ohne die Zeile wäre die Auswertung leise zu niedrig.
     */
    public function test_amounts_on_foreign_cost_center_land_in_orphan_row_and_count(): void
    {
        $team = $this->makeTeam();
        ['tenant' => $tenant] = $this->makeTree($team);

        // Zweiter Tenant desselben Teams mit eigener Kostenstelle.
        $otherTenant = AssetTenant::factory()->create(['team_id' => $team->id]);
        $foreign     = AssetCostCenter::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $otherTenant->id, 'code' => '9999', 'parent_id' => null, 'depth' => 0,
        ]);

        $type = AssetCostType::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);

        // Die Kostenposition gehoert zu $tenant, zeigt aber auf die Kostenstelle des anderen Tenants.
        AssetCostLine::factory()->create([
            'team_id'        => $team->id,
            'tenant_id'      => $tenant->id,
            'cost_type_id'   => $type->id,
            'cost_center_id' => $foreign->id,
            'amount'         => 25.00,
            'frequency'      => 'monthly',
            'active'         => true,
        ]);

        $pivot = (new CostAggregationService())->costCenterByType($team->id, 'monthly', $tenant->id);

        $this->assertNotNull($pivot['orphan'],
            'Betrag auf einer nicht im Baum enthaltenen Kostenstelle braucht die Auffangzeile.');
        $this->assertEqualsWithDelta(25.00, $pivot['orphan']['rowTotal'], 0.01);
        $this->assertEqualsWithDelta(25.00, $pivot['grandTotal'], 0.01,
            'Der Betrag darf nicht aus dem grandTotal fallen.');
    }

    /**
     * S5-Kriterium „neuer Tenant ohne Codeänderung anlegbar und mit eigener Kostenstruktur
     * befüllbar": zwei Tenants desselben Teams, jeder mit eigenem Kostenstellen-Baum und eigenen
     * Beträgen. Jeder Pivot zeigt ausschließlich die eigenen Zahlen — sonst wäre die Auswertung des
     * einen Kunden mit den Kosten des anderen verunreinigt.
     */
    public function test_a_second_tenant_keeps_its_own_cost_structure(): void
    {
        $team = $this->makeTeam();

        $tenantA = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $tenantB = AssetTenant::factory()->create(['team_id' => $team->id]);

        // Beide Tenants nutzen denselben Kostenstellen-Code — im Baum sind das zwei verschiedene
        // Knoten. Genau der Fall, den eine gemeinsame Auswertung sonst zu einer Zahl verschmelzen
        // würde (siehe rowsPerTenant in den MCP-Tools).
        foreach ([[$tenantA, 80.00], [$tenantB, 15.00]] as [$tenant, $amount]) {
            $center = AssetCostCenter::factory()->create([
                'team_id' => $team->id, 'tenant_id' => $tenant->id,
                'code' => '1000', 'parent_id' => null, 'depth' => 0,
            ]);
            $type = AssetCostType::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]);

            AssetCostLine::factory()->create([
                'team_id'        => $team->id,
                'tenant_id'      => $tenant->id,
                'cost_type_id'   => $type->id,
                'cost_center_id' => $center->id,
                'amount'         => $amount,
                'frequency'      => 'monthly',
                'active'         => true,
            ]);
        }

        $service = new CostAggregationService();

        $pivotA = $service->costCenterByType($team->id, 'monthly', $tenantA->id);
        $pivotB = $service->costCenterByType($team->id, 'monthly', $tenantB->id);

        $this->assertEqualsWithDelta(80.00, $pivotA['grandTotal'], 0.01,
            'Tenant A sieht nur die eigenen 80,00.');
        $this->assertEqualsWithDelta(15.00, $pivotB['grandTotal'], 0.01,
            'Tenant B sieht nur die eigenen 15,00.');

        $this->assertCount(1, $pivotA['rows'], 'Der Baum von A enthält genau seinen eigenen Knoten.');
        $this->assertCount(1, $pivotB['rows'], 'Der Baum von B enthält genau seinen eigenen Knoten.');
        $this->assertNull($pivotA['orphan'], 'Keine Fremdbeträge in A.');
        $this->assertNull($pivotB['orphan'], 'Keine Fremdbeträge in B.');
    }

    public function test_once_cost_line_does_not_inflate_monthly_total(): void
    {
        $team   = $this->makeTeam();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $type   = AssetCostType::factory()->create(['team_id' => $team->id, 'tenant_id' => $tenant->id]); // cost_line

        // once → FREQUENCY_FACTORS['once']=0.0 → monthly_amount=0 (Einmalkosten zählen nicht monatlich).
        AssetCostLine::factory()->once()->create([
            'team_id'      => $team->id,
            'tenant_id'    => $tenant->id,
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
