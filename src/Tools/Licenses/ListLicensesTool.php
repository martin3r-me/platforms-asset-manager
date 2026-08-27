<?php

namespace Platform\AssetManager\Tools\Licenses;

use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
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
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.licenses.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/licenses - Listet Microsoft-Lizenz-SKUs des Teams: purchased/consumed/'
            . 'available Einheiten, Preis und Monatskosten sowie Auslastung in %. Der Preis kommt aus den '
            . 'Vertragszeilen der Lizenz-Abrechnung, sonst aus dem handgepflegten Stueckpreis '
            . '(price_source). Bei mehreren Tarifen je Lizenz ist unit_price null und price_range '
            . '{min,max,count} traegt die Spanne. '
            . 'Mit only_underutilized=true nur SKUs mit verfügbaren (ungenutzten) Einheiten.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'only_underutilized' => ['type' => 'boolean', 'description' => 'Nur SKUs mit available_units > 0 (Default false).'],
            ], $this->tenantSchemaProperty()),
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

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            $query = AssetLicenseSku::where('team_id', $teamId);
            if (!empty($arguments['only_underutilized'])) {
                $query->where('available_units', '>', 0);
            }

            // Preisherkunft: Vertragszeilen vor Handpreis (ADR 0019). Eine Lizenz kann mehrere Tarife
            // haben — dann bleibt `unit_price` leer und `price_range` traegt die Information. Ohne das
            // meldete das Tool bei rechnungsgedeckten Lizenzen `unit_price: null, monthly_cost: 0`,
            // obwohl die Kosten belegt sind.
            $book = \Platform\AssetManager\Support\LicensePriceBook::for($teamId);

            $skus = $query->get()->map(function (AssetLicenseSku $s) use ($book) {
                $range = $book->priceRangeForSku((string) $s->sku_id);

                return [
                    'sku_id'          => $s->sku_id,
                    'sku_part_number' => $s->sku_part_number,
                    'display_name'    => $s->display_name ?? $s->sku_part_number,
                    'purchased_units' => $s->purchased_units,
                    'consumed_units'  => $s->consumed_units,
                    'available_units' => $s->available_units,
                    'unit_price'      => $range !== null
                        ? ($range['min'] === $range['max'] ? $range['min'] : null)
                        : ($s->unit_price !== null ? (float) $s->unit_price : null),
                    'price_range'     => $range,
                    'price_source'    => $range !== null ? 'contract' : ($s->unit_price !== null ? 'manual' : null),
                    'monthly_cost'    => $range !== null
                        ? $book->monthlyCostForSku((string) $s->sku_id)
                        : round($s->monthlyCost(), 2),
                    'utilization'     => $s->utilizationPercent(),
                ];
            })->sortByDesc('monthly_cost')->values()->all();

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
