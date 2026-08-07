<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet den Kostenstellen-**Baum** eines Tenants (Code, Name, Ebene, Übergeordnet, Aktiv,
 * Trägerzahl). Liefert die gültigen Codes für die Kostenstellen-Bulk-Tools.
 *
 * Seit docs/adr/0016 hierarchisch und tenant-scoped: die obersten Knoten sind die Gesellschaften
 * (früher eine eigene Tabelle, siehe das deprecatete asset-manager.companies.GET).
 */
class ListCostCentersTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.cost-centers.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/cost-centers - Listet den Kostenstellen-Baum eines Tenants: Code, Name, '
            . 'depth (0 = oberster Knoten / Gesellschaft), parent_code, Aktiv-Status und Trägerzahl. '
            . 'Zeilen kommen in Baum-Reihenfolge. Quelle gültiger Codes für die Kostenstellen-Zuweisung '
            . '(asset-manager.employees.cost-center.bulk.PUT).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => $this->tenantSchemaProperty(),
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

            [$tenantId, $error] = $this->resolveTenant($arguments, $context, $teamId);
            if ($error) {
                return $error;
            }

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId, $tenantId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für diesen Tenant deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $nodes = AssetCostCenter::treeFor($tenantId);
            $byId  = $nodes->keyBy('id');

            $rows = $nodes->map(function (AssetCostCenter $c) use ($byId) {
                $c->loadCount('holders');

                return [
                    'id'           => $c->id,
                    'code'         => $c->code,
                    'name'         => $c->name,
                    'label'        => $c->label,
                    'depth'        => (int) $c->depth,
                    'parent_id'    => $c->parent_id,
                    'parent_code'  => $c->parent_id ? ($byId[$c->parent_id]->code ?? null) : null,
                    'is_root'      => $c->isRoot(),
                    'is_active'    => (bool) $c->is_active,
                    'holder_count' => $c->holders_count,
                ];
            })->values()->all();

            return ToolResult::success([
                'cost_centers' => $rows,
                'count'        => count($rows),
                'tenant_id'    => $tenantId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Kostenstellen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'cost-centers', 'master-data']];
    }
}
