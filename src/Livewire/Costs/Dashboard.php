<?php

namespace Platform\AssetManager\Livewire\Costs;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Concerns\ReportsAcrossTenants;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kosten pro Asset-Träger. **Auswertungs-Sicht**: befolgt die „Alle Tenants"-Präferenz
 * ({@see ReportsAcrossTenants}), sonst der aktive Tenant (docs/adr/0016).
 */
class Dashboard extends Component
{
    use ReportsAcrossTenants;

    public function exportCsv(CostAggregationService $service)
    {
        $teamId = Auth::user()->currentTeam->id;
        // Export im selben Scope wie die Ansicht — sonst enthielte die Datei einen anderen
        // Datenbestand als der Bildschirm, von dem sie ausgelöst wurde.
        $rows   = $this->inReportScope(fn () => $service->topHolders($teamId, 9999));

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Asset-Träger', 'UPN', 'Abteilung', 'Kostenstelle', 'Hardware EUR/Monat', 'Lizenzen EUR/Monat', 'Gesamt EUR/Monat']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['holder']->name,
                    $r['holder']->user_principal_name,
                    $r['holder']->department ?? '',
                    $r['holder']->cost_center ?? '',
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

        return $this->inReportScope(fn () => view('asset-manager::livewire.costs.dashboard', [
            'totals'          => $service->totalMonthly($teamId),
            'topHolders'    => $service->topHolders($teamId, 10),
            'byDepartment'    => $service->byDepartment($teamId),
            'byCostCenter'    => $service->byCostCenter($teamId),
            'byCategory'      => $service->byCategory($teamId),
            'bySku'           => $service->byLicenseSku($teamId),
            'anomalies'       => $service->anomalies($teamId),
            'showsAllTenants' => $this->showsAllTenants(),
        ])->layout('platform::layouts.app'));
    }
}
