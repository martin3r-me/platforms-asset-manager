<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Kostenaufteilung als Pivot Kostenstelle × Kostenart (nach Gesellschaft gruppiert),
 * inkl. Zeilen-/Spaltensummen und Gesamtsumme. Period monthly|quarterly.
 */
class CostAllocationTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.costs.allocation.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/allocation - Kostenaufteilungs-Pivot Kostenstelle × Kostenart, '
            . 'nach Gesellschaft gruppiert, mit Zeilen-/Spaltensummen und Gesamtsumme. '
            . 'Parameter period: "monthly" (Default) oder "quarterly".';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => ['type' => 'string', 'enum' => ['monthly', 'quarterly'], 'description' => 'Zeitraum (Default monthly).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $period = ($arguments['period'] ?? 'monthly') === 'quarterly' ? 'quarterly' : 'monthly';
            $pivot  = app(CostAggregationService::class)->costCenterByType($teamId, $period);

            return ToolResult::success(array_merge($pivot, ['currency' => 'EUR']));
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen der Kostenaufteilung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'allocation', 'pivot']];
    }
}
