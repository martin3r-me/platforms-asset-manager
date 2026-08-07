<?php

namespace Platform\AssetManager\Tools\Holders\Legacy;

use Platform\AssetManager\Tools\Concerns\DeprecatedToolAlias;
use Platform\AssetManager\Tools\Holders\GetHolderTool;
use Platform\Core\Contracts\ToolContract;

/**
 * @deprecated Alter Name von {@see GetHolderTool} — nutze `asset-manager.holder.GET`.
 *             Entfernen, sobald der Live-Connector keine Aufrufe mehr darauf zeigt (ADR 0017).
 */
class GetEmployeeAlias extends DeprecatedToolAlias
{
    public function getName(): string
    {
        return 'asset-manager.employee.GET';
    }

    protected function target(): ToolContract
    {
        return new GetHolderTool();
    }
}
