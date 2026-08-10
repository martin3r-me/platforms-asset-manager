<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Tools\Concerns\DeletesMasterData;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Löscht eine Kostenart — nur, wenn sie keine Kosten mehr trägt.
 */
class DeleteCostTypeTool implements ToolContract, ToolMetadataContract
{
    use DeletesMasterData;

    public function getName(): string
    {
        return 'asset-manager.cost-types.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /asset-manager/cost-types - Löscht eine Kostenart (per id). Zwei Sperren gegen '
            . 'stillen Datenverlust, beide melden sich als DELETE_BLOCKED mit Begründung: (1) hängen noch '
            . 'Kostenpositionen dran, würden diese mitgelöscht (cascade) — erst umbuchen oder löschen; '
            . '(2) virtuelle Quellen (hardware_afa/ms_license/asset_device) haben nie eigene '
            . 'Kostenpositionen, tragen ihre Beträge aber aus Inventar-AfA, Lizenzen oder Geräten — '
            . 'solange sie Kosten tragen, bleibt die Kostenart gesperrt.';
    }

    public function getSchema(): array
    {
        return $this->deleteSchema('Kostenart-ID (erforderlich). IDs über asset-manager.cost-types.GET.');
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->runDeletion('deleteCostType', $arguments, $context, 'Kostenart');
    }
}
