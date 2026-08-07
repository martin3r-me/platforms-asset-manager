<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
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
    use ResolvesTenant;

    private const DIMENSIONS = ['department', 'cost_center', 'company', 'category', 'license_sku', 'vendor', 'cost_type'];

    public function getName(): string
    {
        return 'asset-manager.costs.by.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/by - Monatskosten gruppiert nach dimension: '
            . 'department, cost_center, company, category, license_sku, vendor oder cost_type. '
            . 'Liefert je Gruppe Label und Summe (je nach Dimension zusätzlich Counts/Auslastung). '
            . 'Bei tenant_id="all" trägt jede Zeile zusätzlich tenant_id und tenant_name — gleichnamige '
            . 'Gruppen verschiedener Tenants bleiben so getrennt.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'dimension' => [
                    'type'        => 'string',
                    'enum'        => self::DIMENSIONS,
                    'description' => 'Gruppierungs-Dimension (erforderlich).',
                ],
            ], $this->tenantSchemaPropertyWithAll()),
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

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId, true);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
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

            // Bei tenant_id="all" je Tenant auswerten und die Zeilen kennzeichnen — sonst addierte
            // sich z. B. die Kostenstelle „1000" zweier Kunden zu einer nicht zuordenbaren Zahl.
            $rows = $this->rowsPerTenant($teamId, $tenantId, fn () => $agg->{$method}($teamId));

            return ToolResult::success([
                'dimension' => $dimension,
                'tenant_scope' => $tenantId === null ? 'all' : $tenantId,
                'rows'      => $rows,
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
