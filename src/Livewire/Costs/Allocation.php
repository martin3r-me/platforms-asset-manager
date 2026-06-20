<?php

namespace Platform\AssetManager\Livewire\Costs;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\CostAggregationService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Allocation extends Component
{
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
        $pivot  = $service->costCenterByType($this->teamId(), $this->period);
        $period = $this->period;

        return new StreamedResponse(function () use ($pivot, $period) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

            $header = ['Gesellschaft', 'Kostenstelle', 'Bezeichnung'];
            foreach ($pivot['types'] as $t) {
                $header[] = $t['name'];
            }
            $header[] = 'Summe';
            fputcsv($out, $header, ';');

            foreach ($pivot['companies'] as $company) {
                foreach ($company['rows'] as $row) {
                    $line = [$company['name'], $row['code'], $row['name'] ?? ''];
                    foreach ($pivot['types'] as $t) {
                        $line[] = number_format($row['cells'][$t['id']] ?? 0, 2, ',', '');
                    }
                    $line[] = number_format($row['rowTotal'], 2, ',', '');
                    fputcsv($out, $line, ';');
                }
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

        return view('asset-manager::livewire.costs.allocation', [
            'pivot'      => $service->costCenterByType($teamId, $this->period),
            'byCostType' => $service->byCostType($teamId),
            'byVendor'   => $service->byVendor($teamId),
            'byCompany'  => $service->byCompany($teamId),
        ])->layout('platform::layouts.app');
    }
}
