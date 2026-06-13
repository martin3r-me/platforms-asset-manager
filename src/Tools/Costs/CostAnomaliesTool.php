<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Einsparpotenziale/Auffälligkeiten: Pool-Hardware (gebundenes Kapital), ungenutzte Lizenzen,
 * Hardware bei inaktiven Mitarbeitern.
 */
class CostAnomaliesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.costs.anomalies.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/anomalies - Auffälligkeiten/Einsparpotenziale des Teams: '
            . 'pool (Hardware im Lager, gebundenes Kapital), unused_licenses (verfügbare Einheiten × '
            . 'Stückpreis), inactive_employees (Hardware bei inaktiven Mitarbeitern).';
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

            $anomalies = app(CostAggregationService::class)->anomalies($teamId);

            return ToolResult::success(array_merge($anomalies, ['currency' => 'EUR']));
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Ermitteln der Anomalien: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'anomalies']];
    }
}
