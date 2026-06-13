<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet die Kostenstellen des Teams (Code, Name, Gesellschaft, Aktiv, Mitarbeiterzahl).
 * Liefert die gültigen Codes für die Kostenstellen-Bulk-Tools.
 */
class ListCostCentersTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.cost-centers.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/cost-centers - Listet alle Kostenstellen des Teams mit Code, Name, '
            . 'Gesellschaft, Aktiv-Status und Mitarbeiterzahl. Quelle gültiger Codes für die '
            . 'Kostenstellen-Zuweisung (asset-manager.employees.cost-center.bulk.PUT).';
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

            $rows = AssetCostCenter::where('team_id', $teamId)
                ->with('company')->withCount('employees')
                ->orderBy('company_id')->orderBy('code')->get()
                ->map(fn (AssetCostCenter $c) => [
                    'id'             => $c->id,
                    'code'           => $c->code,
                    'name'           => $c->name,
                    'label'          => $c->label,
                    'company'        => $c->company?->name,
                    'company_id'     => $c->company_id,
                    'is_active'      => (bool) $c->is_active,
                    'employee_count' => $c->employees_count,
                ])->values()->all();

            return ToolResult::success(['cost_centers' => $rows, 'count' => count($rows)]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Kostenstellen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'cost-centers', 'master-data']];
    }
}
