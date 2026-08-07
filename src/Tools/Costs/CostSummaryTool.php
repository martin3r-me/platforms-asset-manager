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
 * Monatliche Gesamtkosten des Teams (Hardware inkl. Intune-Geräte, Lizenzen, Kostenpositionen).
 */
class CostSummaryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.costs.summary.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/summary - Monatliche Gesamtkosten des aktiven Teams: '
            . 'hardware (AfA ZUGEWIESENER Inventar-Items + Intune-Geräte), licenses (zugewiesene '
            . 'Microsoft-Lizenzen), costlines (sonstige Kostenpositionen) und total. total ist per '
            . 'Konstruktion identisch mit dem grandTotal der Kostenstellen×Kostenart-Pivot '
            . '(asset-manager.costs.by). Zusätzlich capacity = Bestand, der dem Pivot NICHT zugeteilt '
            . 'wird (hardware_unassigned: AfA von Lager-/Pool-Items ohne Asset-Träger; licenses_catalog: '
            . 'SKU-Katalogkosten) — bewusst NICHT Teil von total. Bei tenant_id="all" kommt statt '
            . 'monthly_costs eine Liste by_tenant (je Eintrag tenant_id/tenant_name + dieselben '
            . 'Kennzahlen) plus total als Summe über alle Tenants.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => $this->tenantSchemaPropertyWithAll(),
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

            // Bei tenant_id="all" ist eine einzige Summe über alle Kunden zwar rechnerisch richtig,
            // aber betriebswirtschaftlich nutzlos — die Frage lautet immer „wieviel je Kunde". Darum
            // je Tenant rechnen und die Gesamtsumme zusätzlich ausweisen.
            $perTenant = $this->rowsPerTenant($teamId, $tenantId, fn () => [
                app(CostAggregationService::class)->totalMonthly($teamId),
            ]);

            if ($tenantId !== null) {
                return ToolResult::success(['monthly_costs' => $perTenant[0], 'currency' => 'EUR']);
            }

            return ToolResult::success([
                'tenant_scope' => 'all',
                'by_tenant'    => $perTenant,
                'total'        => round(array_sum(array_map(
                    fn (array $r) => (float) ($r['total'] ?? 0),
                    $perTenant,
                )), 2),
                'currency'     => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Berechnen der Gesamtkosten: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'summary']];
    }
}
