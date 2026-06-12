<?php

namespace Platform\AssetManager\Livewire\Costs;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\CostAggregationService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Dashboard extends Component
{
    public function exportCsv(CostAggregationService $service)
    {
        $teamId = Auth::user()->currentTeam->id;
        $rows   = $service->topEmployees($teamId, 9999);

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Mitarbeiter', 'UPN', 'Abteilung', 'Kostenstelle', 'Hardware EUR/Monat', 'Lizenzen EUR/Monat', 'Gesamt EUR/Monat']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['employee']->name,
                    $r['employee']->user_principal_name,
                    $r['employee']->department ?? '',
                    $r['employee']->cost_center ?? '',
                    number_format($r['hardware'], 2, ',', ''),
                    number_format($r['licenses'], 2, ',', ''),
                    number_format($r['total'], 2, ',', ''),
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kosten-pro-mitarbeiter-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function render(CostAggregationService $service)
    {
        $teamId = Auth::user()->currentTeam->id;

        return view('asset-manager::livewire.costs.dashboard', [
            'totals'       => $service->totalMonthly($teamId),
            'topEmployees' => $service->topEmployees($teamId, 10),
            'byDepartment' => $service->byDepartment($teamId),
            'byCostCenter' => $service->byCostCenter($teamId),
            'byCategory'   => $service->byCategory($teamId),
            'bySku'        => $service->byLicenseSku($teamId),
            'anomalies'    => $service->anomalies($teamId),
        ])->layout('platform::layouts.app');
    }
}
