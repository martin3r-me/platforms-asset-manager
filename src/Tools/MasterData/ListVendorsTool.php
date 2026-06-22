<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet die Kreditoren des Teams.
 */
class ListVendorsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.vendors.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/vendors - Listet die Kreditoren des Teams (id, name, creditor_no) '
            . 'inkl. Anzahl verknüpfter Kostenpositionen.';
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

            $rows = AssetVendor::where('team_id', $teamId)->withCount('costLines')
                ->orderBy('name')->get()
                ->map(fn (AssetVendor $v) => [
                    'id'              => $v->id,
                    'name'            => $v->name,
                    'creditor_no'     => $v->creditor_no,
                    'cost_lines_count' => $v->cost_lines_count,
                ])->values()->all();

            return ToolResult::success(['vendors' => $rows, 'count' => count($rows)]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Kreditoren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'vendors', 'master-data']];
    }
}
