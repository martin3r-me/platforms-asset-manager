<?php

namespace Platform\AssetManager\Tools\CostLines;

use Platform\AssetManager\Tools\Concerns\DeletesMasterData;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Löscht eine Kostenposition (Soft-Delete, wie im UI).
 */
class DeleteCostLineTool implements ToolContract, ToolMetadataContract
{
    use DeletesMasterData;

    public function getName(): string
    {
        return 'asset-manager.cost-lines.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /asset-manager/cost-lines - Löscht eine Kostenposition (per id). Soft-Delete: '
            . 'die Zeile verschwindet aus allen Auswertungen. Stammt sie aus dem Excel-Import, wird sie '
            . 'beim nächsten Import NICHT wiederbelebt — die bewusste Löschung gewinnt.';
    }

    public function getSchema(): array
    {
        return $this->deleteSchema('Kostenpositions-ID (erforderlich). IDs über asset-manager.cost-lines.GET.');
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->runDeletion('deleteCostLine', $arguments, $context, 'Kostenposition');
    }
}
