<?php

namespace Platform\AssetManager\Livewire\Costs;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\ReportsAcrossTenants;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\CostAggregationService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kostenaufteilung (Pivot Kostenstellen-Baum × Kostenart). **Auswertungs-Sicht**: befolgt die
 * „Alle Tenants"-Präferenz ({@see ReportsAcrossTenants}), sonst der aktive Tenant (docs/adr/0016).
 */
class Allocation extends Component
{
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

    public function exportCsv(CostAggregationService $service)
    {
        // Export im selben Scope wie die Ansicht (ADR 0016).
        $pivot  = $service->costCenterByType($this->teamId(), $this->period, $this->reportTenantId());
        $period = $this->period;

        return new StreamedResponse(function () use ($pivot, $period) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

            // Ebene + Übergeordnet statt der früheren Gesellschafts-Spalte: der Kostenstellen-Baum
            // ist beliebig tief (docs/adr/0016), eine feste Gesellschafts-Spalte könnte ihn nicht
            // abbilden. Exportiert werden die EIGENEN Beträge je Knoten — so bleibt die Summe der
            // Zeilen gleich der Gesamtsumme, statt Rollups doppelt zu zählen.
            $header = ['Ebene', 'Kostenstelle', 'Bezeichnung', 'Übergeordnet'];
            foreach ($pivot['types'] as $t) {
                $header[] = $t['name'];
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
                foreach ($pivot['types'] as $t) {
                    $line[] = number_format($row['cells'][$t['id']] ?? 0, 2, ',', '');
                }
                $line[] = number_format($row['rowTotal'], 2, ',', '');
                fputcsv($out, $line, ';');
            }

            // Summenzeile
            $totals = ['', 'SUMME', ''];
            foreach ($pivot['types'] as $t) {
                $totals[] = number_format($pivot['colTotals'][$t['id']] ?? 0, 2, ',', '');
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

        return $this->inReportScope(fn () => view('asset-manager::livewire.costs.allocation', [
            'pivot'           => $service->costCenterByType($teamId, $this->period, $this->reportTenantId()),
            'showsAllTenants' => $this->showsAllTenants(),
            'byCostType'      => $service->byCostType($teamId),
            'byVendor'        => $service->byVendor($teamId),
            // Früher „je Gesellschaft" — jetzt je oberster Kostenstellen-Ebene (docs/adr/0016).
            'byCostCenterRoot' => $service->byCostCenterRoot($teamId, $this->reportTenantId()),
        ])->layout('platform::layouts.app'));
    }
}
