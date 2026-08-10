<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Tools\Concerns\DeletesMasterData;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Löscht eine Kostenstelle aus dem Baum. Untergeordnete Knoten werden NICHT mitgelöscht.
 */
class DeleteCostCenterTool implements ToolContract, ToolMetadataContract
{
    use DeletesMasterData;

    public function getName(): string
    {
        return 'asset-manager.cost-centers.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /asset-manager/cost-centers - Löscht eine Kostenstelle (per id). Untergeordnete '
            . 'Kostenstellen werden NICHT mitgelöscht, sondern rutschen zu obersten Knoten hoch '
            . '(promoted_children nennt ihre Anzahl). Zuordnungen von Asset-Trägern und '
            . 'Kostenpositionen an diese Kostenstelle werden entfernt — die Datensätze selbst bleiben, '
            . 'ihre Beträge landen danach in der Zeile "Ohne Kostenstelle" der Kostenaufteilung.';
    }

    public function getSchema(): array
    {
        return $this->deleteSchema('Kostenstellen-ID (erforderlich). IDs über asset-manager.cost-centers.GET.');
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->runDeletion('deleteCostCenter', $arguments, $context, 'Kostenstelle');
    }
}
