<?php

namespace Platform\AssetManager\Tools\Licenses;

use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet die Microsoft-Lizenz-SKUs des Teams inkl. Auslastung und Monatskosten.
 * Optional nur unterausgelastete SKUs (verfügbare, ungenutzte Einheiten).
 */
class ListLicensesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.licenses.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/licenses - Listet Microsoft-Lizenz-SKUs des Teams: purchased/consumed/'
            . 'available Einheiten, Stückpreis, Monatskosten (unit_price × consumed) und Auslastung in %. '
            . 'Mit only_underutilized=true nur SKUs mit verfügbaren (ungenutzten) Einheiten.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'only_underutilized' => ['type' => 'boolean', 'description' => 'Nur SKUs mit available_units > 0 (Default false).'],
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

            $query = AssetLicenseSku::where('team_id', $teamId);
            if (!empty($arguments['only_underutilized'])) {
                $query->where('available_units', '>', 0);
            }

            $skus = $query->get()->map(fn (AssetLicenseSku $s) => [
                'sku_id'          => $s->sku_id,
                'sku_part_number' => $s->sku_part_number,
                'display_name'    => $s->display_name ?? $s->sku_part_number,
                'purchased_units' => $s->purchased_units,
                'consumed_units'  => $s->consumed_units,
                'available_units' => $s->available_units,
                'unit_price'      => $s->unit_price !== null ? (float) $s->unit_price : null,
                'monthly_cost'    => round($s->monthlyCost(), 2),
                'utilization'     => $s->utilizationPercent(),
            ])->sortByDesc('monthly_cost')->values()->all();

            return ToolResult::success([
                'licenses' => $skus,
                'count'    => count($skus),
                'currency' => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Lizenzen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'licenses']];
    }
}
