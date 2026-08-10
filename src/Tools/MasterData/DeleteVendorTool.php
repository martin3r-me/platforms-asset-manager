<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Tools\Concerns\DeletesMasterData;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Löscht einen Kreditor. Zuordnungen fallen weg, Positionen und Kostenarten bleiben.
 */
class DeleteVendorTool implements ToolContract, ToolMetadataContract
{
    use DeletesMasterData;

    public function getName(): string
    {
        return 'asset-manager.vendors.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /asset-manager/vendors - Löscht einen Kreditor (per id). Kostenpositionen und '
            . 'Kostenarten, die auf ihn zeigen, bleiben erhalten — sie verlieren nur die Zuordnung '
            . '(vendor_id bzw. vendor_default_id werden geleert).';
    }

    public function getSchema(): array
    {
        return $this->deleteSchema('Kreditor-ID (erforderlich). IDs über asset-manager.vendors.GET.');
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return $this->runDeletion('deleteVendor', $arguments, $context, 'Kreditor');
    }
}
