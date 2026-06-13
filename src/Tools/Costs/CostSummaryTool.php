<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Monatliche Gesamtkosten des Teams (Hardware inkl. Intune-Geräte, Lizenzen, Kostenpositionen).
 */
class CostSummaryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.costs.summary.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/summary - Monatliche Gesamtkosten des aktiven Teams: '
            . 'hardware (Inventar-AfA + Intune-Geräte), licenses (Microsoft), costlines (sonstige '
            . 'Kostenpositionen) und total. Doppelzählungsfrei über cost_type.aggregation_source.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $totals = app(CostAggregationService::class)->totalMonthly($teamId);

            return ToolResult::success(['monthly_costs' => $totals, 'currency' => 'EUR']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Berechnen der Gesamtkosten: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'summary']];
    }
}
