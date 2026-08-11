<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostPlausibilityService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Plausibilitätsprüfung der Kostenpositionen — findet Datenfehler, die im Pivot als echte Kosten
 * mitlaufen.
 */
class CostPlausibilityTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.costs.plausibility.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/plausibility - Prüft die Kostenpositionen auf Datenfehler, die '
            . 'als echte Kosten mitlaufen. Sechs Regeln: personenbezogene Kostenart ohne Asset-Träger, '
            . 'Kosten auf inaktiven Trägern, Kosten auf fragwürdigen Kostenstellen (Import-Artefakte wie '
            . 'Kopf-/Summenzeilen), Sammelposten deren Betrag ein glattes Vielfaches des Einzelpreises ist, '
            . 'Kostenart mit uneinheitlicher Frequenz, mögliche Dubletten. Jeder Befund nennt Anzahl, '
            . 'betroffene Monatssumme, eine Begründung und Beispielzeilen mit ihrer id — die lassen sich '
            . 'mit asset-manager.cost-lines.PUT/DELETE direkt korrigieren. Rein lesend, korrigiert nichts '
            . 'von selbst: die Regeln sind Heuristiken, und die Beträge sind echtes Geld.';
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
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId, true);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $service = app(CostPlausibilityService::class);

            // Bei "all" je Tenant prüfen: eine gemeinsame Liste wäre nicht zuordenbar, und die Regeln
            // (Einzelpreis je Kostenart, Kostenstellen-Baum) gelten ohnehin nur innerhalb eines Tenants.
            $perTenant = $this->rowsPerTenant($teamId, $tenantId, fn () => [$service->check($teamId)]);

            if ($tenantId !== null) {
                return ToolResult::success($perTenant[0] + ['tenant_id' => $tenantId, 'currency' => 'EUR']);
            }

            return ToolResult::success([
                'tenant_scope' => 'all',
                'by_tenant'    => $perTenant,
                'currency'     => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei der Plausibilitätsprüfung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'quality']];
    }
}
