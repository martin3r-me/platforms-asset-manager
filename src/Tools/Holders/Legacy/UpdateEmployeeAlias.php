<?php

namespace Platform\AssetManager\Tools\Holders\Legacy;

use Platform\AssetManager\Tools\Concerns\DeprecatedToolAlias;
use Platform\AssetManager\Tools\Holders\UpdateHolderTool;
use Platform\Core\Contracts\ToolContract;

/**
 * @deprecated Alter Name von {@see UpdateHolderTool} — nutze `asset-manager.holder.PUT`.
 *             Entfernen, sobald der Live-Connector keine Aufrufe mehr darauf zeigt (ADR 0017).
 */
class UpdateEmployeeAlias extends DeprecatedToolAlias
{
    public function getName(): string
    {
        return 'asset-manager.employee.PUT';
    }

    protected function target(): ToolContract
    {
        return new UpdateHolderTool();
    }
}
