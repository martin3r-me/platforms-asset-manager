<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Monatskosten gruppiert nach einer wählbaren Dimension — ein Tool für sieben Auswertungen.
 */
class CostByDimensionTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    private const DIMENSIONS = ['department', 'cost_center', 'company', 'category', 'license_sku', 'vendor', 'cost_type'];

    public function getName(): string
    {
        return 'asset-manager.costs.by.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/by - Monatskosten gruppiert nach dimension: '
            . 'department, cost_center, company, category, license_sku, vendor oder cost_type. '
            . 'Liefert je Gruppe Label und Summe (je nach Dimension zusätzlich Counts/Auslastung).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dimension' => [
                    'type'        => 'string',
                    'enum'        => self::DIMENSIONS,
                    'description' => 'Gruppierungs-Dimension (erforderlich).',
                ],
            ],
            'required' => ['dimension'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $dimension = (string) ($arguments['dimension'] ?? '');
            if (!in_array($dimension, self::DIMENSIONS, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'dimension muss eines von: ' . implode(', ', self::DIMENSIONS));
            }

            $agg    = app(CostAggregationService::class);
            $method = [
                'department'  => 'byDepartment',
                'cost_center' => 'byCostCenter',
                'company'     => 'byCompany',
                'category'    => 'byCategory',
                'license_sku' => 'byLicenseSku',
                'vendor'      => 'byVendor',
                'cost_type'   => 'byCostType',
            ][$dimension];

            $rows = $agg->{$method}($teamId);

            return ToolResult::success([
                'dimension' => $dimension,
                'rows'      => $rows->values()->all(),
                'currency'  => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei der Kostenauswertung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'report']];
    }
}
