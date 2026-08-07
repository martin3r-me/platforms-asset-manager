<?php

namespace Platform\AssetManager\Tools\Holders\Legacy;

use Platform\AssetManager\Tools\Concerns\DeprecatedToolAlias;
use Platform\AssetManager\Tools\Holders\BulkAssignCostCenterTool;
use Platform\Core\Contracts\ToolContract;

/**
 * @deprecated Alter Name von {@see BulkAssignCostCenterTool} — nutze
 *             `asset-manager.holders.cost-center.bulk.PUT`.
 *             Entfernen, sobald der Live-Connector keine Aufrufe mehr darauf zeigt (ADR 0017).
 */
class BulkAssignCostCenterAlias extends DeprecatedToolAlias
{
    public function getName(): string
    {
        return 'asset-manager.employees.cost-center.bulk.PUT';
    }

    protected function target(): ToolContract
    {
        return new BulkAssignCostCenterTool();
    }
}
