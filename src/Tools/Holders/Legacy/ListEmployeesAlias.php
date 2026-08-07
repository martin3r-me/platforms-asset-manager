<?php

namespace Platform\AssetManager\Tools\Holders\Legacy;

use Platform\AssetManager\Tools\Concerns\DeprecatedToolAlias;
use Platform\AssetManager\Tools\Holders\ListHoldersTool;
use Platform\Core\Contracts\ToolContract;

/**
 * @deprecated Alter Name von {@see ListHoldersTool} — nutze `asset-manager.holders.GET`.
 *             Entfernen, sobald der Live-Connector keine Aufrufe mehr darauf zeigt (ADR 0017).
 */
class ListEmployeesAlias extends DeprecatedToolAlias
{
    public function getName(): string
    {
        return 'asset-manager.employees.GET';
    }

    protected function target(): ToolContract
    {
        return new ListHoldersTool();
    }
}
