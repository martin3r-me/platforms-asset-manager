<?php

namespace Platform\AssetManager\Tools;

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Ein-Call-Einstieg in den Asset-Manager eines Teams: Gesamtkosten, Mengengerüst,
 * Top-Kostentreiber und Anomalien. Gedacht als erstes Tool, das die LLM aufruft.
 */
class OverviewTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.overview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/overview - Kompaktes Dashboard für das aktive Team: monatliche '
            . 'Gesamtkosten (Hardware/Lizenzen/Kostenpositionen/gesamt), Mengengerüst (Mitarbeiter, '
            . 'Intune-Geräte, Lizenz-SKUs, Inventar-Items, Kostenpositionen), die teuersten Mitarbeiter '
            . 'sowie Anomalien (Pool-Hardware, ungenutzte Lizenzen, Hardware bei inaktiven Mitarbeitern). '
            . 'Idealer Startpunkt, um den Zustand des Asset-Managers zu erfassen.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            /** @var CostAggregationService $agg */
            $agg = app(CostAggregationService::class);

            $totals    = $agg->totalMonthly($teamId);
            $anomalies = $agg->anomalies($teamId);
            $top       = $agg->topEmployees($teamId, 5)->map(fn ($r) => [
                'employee'  => $r['employee']->name,
                'upn'       => $r['employee']->user_principal_name,
                'total'     => $r['total'],
                'hardware'  => $r['hardware'],
                'licenses'  => $r['licenses'],
                'costlines' => $r['costlines'],
            ])->values()->all();

            return ToolResult::success([
                'monthly_costs' => $totals,
                'counts'        => [
                    'employees'        => AssetEmployee::where('team_id', $teamId)->count(),
                    'employees_active' => AssetEmployee::where('team_id', $teamId)->where('is_active', true)->count(),
                    'devices'          => AssetDevice::where('team_id', $teamId)->count(),
                    'license_skus'     => AssetLicenseSku::where('team_id', $teamId)->count(),
                    'items'            => AssetItem::where('team_id', $teamId)->count(),
                    'cost_lines'       => AssetCostLine::where('team_id', $teamId)->count(),
                ],
                'top_employees' => $top,
                'anomalies'     => $anomalies,
                'currency'      => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Übersicht: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'tier'      => 'common',
            'read_only' => true,
            'tags'      => ['asset-manager', 'overview', 'costs', 'dashboard'],
        ];
    }
}
