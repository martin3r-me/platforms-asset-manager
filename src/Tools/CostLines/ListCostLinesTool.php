<?php

namespace Platform\AssetManager\Tools\CostLines;

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;

/**
 * Listet Kostenpositionen (cost lines) des Teams mit Filter/Suche/Sortierung.
 */
class ListCostLinesTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.cost-lines.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/cost-lines - Listet Kostenpositionen des Teams. Filterbare Felder: '
            . 'cost_type_id, vendor_id, cost_center_id, assignee_id, source (manual|excel_import|graph), '
            . 'active, frequency, currency, label. Nutze filters, search (label), sort, limit/offset. '
            . 'Antwort enthält Originalbetrag, Frequenz und den auf den Monat normalisierten Betrag.';
    }

    public function getSchema(): array
    {
        return $this->getStandardGetSchema();
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $allowed = ['cost_type_id', 'vendor_id', 'cost_center_id', 'assignee_id', 'source', 'active', 'frequency', 'currency', 'label', 'period_label'];

            $query = AssetCostLine::where('team_id', $teamId)->with(['costType', 'vendor', 'costCenter', 'assignee']);
            $this->applyStandardFilters($query, $arguments, $allowed);
            $this->applyStandardSearch($query, $arguments, ['label']);
            $this->applyStandardSort($query, $arguments, array_merge($allowed, ['amount', 'monthly_amount', 'created_at']), 'monthly_amount', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $rows = $result['data']->map(fn (AssetCostLine $l) => [
                'id'             => $l->id,
                'label'          => $l->label,
                'cost_type'      => $l->costType?->name,
                'cost_type_id'   => $l->cost_type_id,
                'vendor'         => $l->vendor?->name,
                'cost_center'    => $l->costCenter?->label,
                'cost_center_id' => $l->cost_center_id,
                'assignee'       => $l->assignee?->name,
                'amount'         => (float) $l->amount,
                'currency'       => $l->currency,
                'frequency'      => $l->frequency,
                'monthly_amount' => (float) $l->monthly_amount,
                'source'         => $l->source,
                'active'         => (bool) $l->active,
            ])->values()->all();

            return ToolResult::success(['cost_lines' => $rows, 'pagination' => $result['pagination'], 'currency' => 'EUR']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Kostenpositionen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'cost-lines']];
    }
}
