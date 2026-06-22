<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Models\AssetCompany;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet die Gesellschaften des Teams (mit Anzahl Kostenstellen).
 */
class ListCompaniesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.companies.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/companies - Listet die Gesellschaften des Teams (id, key, name) '
            . 'inkl. Anzahl zugeordneter Kostenstellen.';
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

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $rows = AssetCompany::where('team_id', $teamId)->withCount('costCenters')
                ->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (AssetCompany $c) => [
                    'id'                 => $c->id,
                    'key'                => $c->key,
                    'name'               => $c->name,
                    'cost_centers_count' => $c->cost_centers_count,
                ])->values()->all();

            return ToolResult::success(['companies' => $rows, 'count' => count($rows)]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Gesellschaften: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'companies', 'master-data']];
    }
}
