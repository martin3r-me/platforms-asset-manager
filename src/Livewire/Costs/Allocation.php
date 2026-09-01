<?php

namespace Platform\AssetManager\Livewire\Costs;

use Livewire\Component;
use Platform\AssetManager\Concerns\CustomizesTableView;
use Platform\AssetManager\Concerns\ReportsAcrossTenants;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Support\PivotTableShaper;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kostenaufteilung (Pivot Kostenstellen-Baum × Kostenart). **Auswertungs-Sicht**: befolgt die
 * „Alle Tenants"-Präferenz ({@see ReportsAcrossTenants}), sonst der aktive Tenant (docs/adr/0016).
 *
 * Die Tabelle ist pro Nutzer anpassbar ({@see CustomizesTableView}, docs/adr/0020): Spaltenbreiten,
 * Spaltenreihenfolge, aus-/eingeblendete Spalten und Zeilen, eingeklappte Gruppen. Das betrifft
 * ausschließlich die Anzeige — Beträge und Summen bleiben davon unberührt.
 */
class Allocation extends Component
{
    use CustomizesTableView;
    use ReportsAcrossTenants;
    use ResolvesCurrentTeam;

    public string $period = 'monthly'; // monthly|quarterly

    protected $queryString = [
        'period' => ['except' => 'monthly'],
    ];

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['monthly', 'quarterly'], true) ? $period : 'monthly';
    }

    protected function tableViewKey(): string
    {
        return 'costs.allocation';
    }

    /**
     * Die Kostenarten in derselben Ursprungsreihenfolge, in der der Pivot sie liefert
     * (CostAggregationService: sort_order, dann name) — sonst würde „verschieben" gegen eine andere
     * Grundreihenfolge rechnen als die Ansicht zeigt.
     *
     * @return array<int,string>
     */
    protected function tableViewColumnKeys(): array
    {
        return AssetCostType::where('team_id', $this->teamId())
            ->orderBy('sort_order')->orderBy('name')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function exportCsv(CostAggregationService $service)
    {
        // Export im selben Scope wie die Ansicht (ADR 0016).
        $pivot  = $service->costCenterByType($this->teamId(), $this->period, $this->reportTenantId());
        $period = $this->period;

        // Der Export folgt der Ansicht in der **Reihenfolge**, lässt aber bewusst nichts weg: eine
        // ausgeblendete Spalte ist eine Bildschirm-Entscheidung, kein Grund, Zahlen aus einer
        // Auswertung zu tilgen. Ausgeblendete Spalten stehen darum hinten und sind benannt.
        $columns = (new PivotTableShaper($this->tableView()))->shape($pivot)['columns'];
        usort($columns, fn ($a, $b) => ($a['hidden'] ? 1 : 0) <=> ($b['hidden'] ? 1 : 0));

        return new StreamedResponse(function () use ($pivot, $period, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

            // Ebene + Übergeordnet statt der früheren Gesellschafts-Spalte: der Kostenstellen-Baum
            // ist beliebig tief (docs/adr/0016), eine feste Gesellschafts-Spalte könnte ihn nicht
            // abbilden. Exportiert werden die EIGENEN Beträge je Knoten — so bleibt die Summe der
            // Zeilen gleich der Gesamtsumme, statt Rollups doppelt zu zählen.
            $header = ['Ebene', 'Kostenstelle', 'Bezeichnung', 'Übergeordnet'];
            foreach ($columns as $column) {
                $header[] = $column['name'] . ($column['hidden'] ? ' (ausgeblendet)' : '');
            }
            $header[] = 'Summe';
            fputcsv($out, $header, ';');

            $extraRows = array_values(array_filter([$pivot['unassigned'] ?? null, $pivot['orphan'] ?? null]));

            foreach (array_merge($pivot['rows'], $extraRows) as $row) {
                $line = [
                    (int) ($row['depth'] ?? 0),
                    $row['code'],
                    $row['name'] ?? '',
                    $row['parent_code'] ?? '',
                ];
                foreach ($columns as $column) {
                    $line[] = number_format($row['cells'][$column['id']] ?? 0, 2, ',', '');
                }
                $line[] = number_format($row['rowTotal'], 2, ',', '');
                fputcsv($out, $line, ';');
            }

            // Summenzeile
            $totals = ['', 'SUMME', ''];
            foreach ($columns as $column) {
                $totals[] = number_format($pivot['colTotals'][$column['id']] ?? 0, 2, ',', '');
            }
            $totals[] = number_format($pivot['grandTotal'], 2, ',', '');
            fputcsv($out, $totals, ';');

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kostenaufteilung-' . $period . '-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function render(CostAggregationService $service)
    {
        $teamId = $this->teamId();

        return $this->inReportScope(function () use ($service, $teamId) {
            $pivot = $service->costCenterByType($teamId, $this->period, $this->reportTenantId());

            return view('asset-manager::livewire.costs.allocation', [
                'pivot'           => $pivot,
                // Fertig geformte Ansicht (Reihenfolge, Breiten, Sichtbarkeit, Faltung) — das Blade
                // trifft keine dieser Entscheidungen mehr selbst.
                'grid'            => (new PivotTableShaper($this->tableView()))->shape($pivot),
                'showsAllTenants' => $this->showsAllTenants(),
                'byCostType'      => $service->byCostType($teamId),
                'byVendor'        => $service->byVendor($teamId),
                // Früher „je Gesellschaft" — jetzt je oberster Kostenstellen-Ebene (docs/adr/0016).
                'byCostCenterRoot' => $service->byCostCenterRoot($teamId, $this->reportTenantId()),
            ])->layout('platform::layouts.app');
        });
    }
}
