<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Teuerste Mitarbeiter nach monatlichen Gesamtkosten (Hardware inkl. Geräte, Lizenzen, Kostenpositionen).
 */
class TopEmployeesByCostTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.costs.top-employees.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/top-employees - Mitarbeiter mit den höchsten monatlichen '
            . 'Gesamtkosten (hardware/licenses/costlines/total). Parameter limit (Default 10, max 200).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Anzahl Mitarbeiter (Default 10, max 200).'],
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

            $limit = min(max((int) ($arguments['limit'] ?? 10), 1), 200);

            $rows = app(CostAggregationService::class)->topEmployees($teamId, $limit)
                ->map(fn ($r) => [
                    'employee'            => $r['employee']->name,
                    'user_principal_name' => $r['employee']->user_principal_name,
                    'department'          => $r['employee']->department,
                    'cost_center'         => $r['employee']->cost_center,
                    'hardware'            => $r['hardware'],
                    'licenses'            => $r['licenses'],
                    'costlines'           => $r['costlines'],
                    'total'               => $r['total'],
                ])->values()->all();

            return ToolResult::success(['employees' => $rows, 'count' => count($rows), 'currency' => 'EUR']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Berechnen der Top-Mitarbeiter: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'employees']];
    }
}
