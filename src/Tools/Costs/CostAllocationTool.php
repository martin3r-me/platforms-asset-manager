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
 * Kostenaufteilung als Pivot Kostenstelle × Kostenart (nach Gesellschaft gruppiert),
 * inkl. Zeilen-/Spaltensummen und Gesamtsumme. Period monthly|quarterly.
 */
class CostAllocationTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.costs.allocation.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/allocation - Kostenaufteilungs-Pivot Kostenstelle × Kostenart, '
            . 'nach Gesellschaft gruppiert, mit Zeilen-/Spaltensummen und Gesamtsumme. '
            . 'Parameter period: "monthly" (Default) oder "quarterly". Bei tenant_id="all" kommt eine '
            . 'Liste by_tenant mit einem eigenen Pivot je Tenant (tenant_id/tenant_name je Eintrag) — '
            . 'Pivots verschiedener Tenants werden NICHT zusammengerechnet.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'period' => ['type' => 'string', 'enum' => ['monthly', 'quarterly'], 'description' => 'Zeitraum (Default monthly).'],
            ], $this->tenantSchemaPropertyWithAll()),
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
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId, true);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $period = ($arguments['period'] ?? 'monthly') === 'quarterly' ? 'quarterly' : 'monthly';

            // Ein Pivot hat Zeilen-, Spalten- und Gesamtsummen — die lassen sich über Tenants hinweg
            // nicht sinnvoll verschmelzen (gleiche Kostenstellen-Codes, verschiedene Kunden, ein
            // grandTotal, der niemandem gehört). Bei tenant_id="all" daher ein Pivot je Tenant.
            $perTenant = $this->rowsPerTenant($teamId, $tenantId, fn () => [
                app(CostAggregationService::class)->costCenterByType($teamId, $period),
            ]);

            if ($tenantId !== null) {
                return ToolResult::success(array_merge($perTenant[0], ['currency' => 'EUR']));
            }

            return ToolResult::success([
                'tenant_scope' => 'all',
                'period'       => $period,
                'by_tenant'    => $perTenant,
                'currency'     => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen der Kostenaufteilung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'allocation', 'pivot']];
    }
}
