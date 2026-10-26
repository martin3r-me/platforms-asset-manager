<?php

namespace Platform\AssetManager\Tools\Inventory;

use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\InventoryService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\InventoryRow;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Das kanonische Inventar (ADR 0012) per MCP: manuelle Assets UND Intune-Geräte in einer Liste — dieselbe
 * Quelle wie die Inventar-Seite ({@see InventoryService}), also dieselben Filter und dieselbe Sortierung.
 * Vorher waren per MCP nur die Geräte sichtbar; manuelle Assets (Monitore, Docks, Drucker …) tauchten nur
 * als Zahl in der Übersicht und an einzelnen Trägern auf.
 */
class ListInventoryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const MAX_LIMIT = 200;
    private const SORTS     = ['name', 'assignedTo', 'status', 'monthlyCost'];

    public function getName(): string
    {
        return 'asset-manager.inventory.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/inventory - Inventar des Tenants: manuelle Assets (type=manual) und Intune-Geräte '
            . '(type=intune) in einer Liste, wie die Inventar-Seite. Filter: search (Wörter in Name/Hersteller/Modell/'
            . 'Seriennr./Zuordnung), type (manual|intune), assignment (assigned|unassigned), status (Asset-Status '
            . 'in_stock|assigned|retired|lost bzw. Geräte-Lifecycle), category_id (nur manuelle), cost_center_id (nur '
            . 'Geräte). sort: name|assignedTo|status|monthlyCost, direction asc|desc, limit (Default 50, max 200), page. '
            . 'Monatskosten nur bei aktivem Controlling. Liefert auch die Kategorien (IDs für items.POST). Details eines manuellen Assets: asset-manager.item.GET, eines '
            . 'Geräts: asset-manager.device.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'search'         => ['type' => 'string', 'description' => 'Suchwörter; jedes muss vorkommen.'],
                'type'           => ['type' => 'string', 'enum' => ['manual', 'intune'], 'description' => 'Nur manuelle Assets oder nur Intune-Geräte.'],
                'assignment'     => ['type' => 'string', 'enum' => ['assigned', 'unassigned'], 'description' => 'Nur zugeordnete bzw. freie.'],
                'status'         => ['type' => 'string', 'description' => 'Asset-Status (in_stock|assigned|retired|lost) oder Geräte-Lifecycle (in_use|spare|repair|defect|retired|lost).'],
                'category_id'    => ['type' => 'integer', 'description' => 'Kategorie (nur manuelle Assets; Geräte fallen raus).'],
                'cost_center_id' => ['type' => 'integer', 'description' => 'Kostenstelle (nur Geräte; manuelle Assets fallen raus).'],
                'sort'           => ['type' => 'string', 'enum' => self::SORTS, 'description' => 'Sortierung (Default name; monthlyCost nur mit Controlling).'],
                'direction'      => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit'          => ['type' => 'integer', 'description' => 'Zeilen je Seite (Default 50, max 200).'],
                'page'           => ['type' => 'integer', 'description' => 'Seite (ab 1).'],
            ], $this->tenantSchemaProperty()),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Ohne Controlling keine Kosten (ADR 0008) — auch nicht als Sortierschlüssel.
            $controlling = app(ControllingContext::class)->enabledFor($teamId, $tenantId);

            $sort = in_array($arguments['sort'] ?? null, self::SORTS, true) ? $arguments['sort'] : 'name';
            if ($sort === 'monthlyCost' && ! $controlling) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Sortierung nach Monatskosten braucht die Controlling-Schicht (für diesen Tenant deaktiviert).');
            }
            $direction = ($arguments['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $limit     = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 50)));
            $page      = max(1, (int) ($arguments['page'] ?? 1));

            $filters = [
                'search'     => (string) ($arguments['search'] ?? ''),
                'type'       => in_array($arguments['type'] ?? '', ['manual', 'intune'], true) ? $arguments['type'] : '',
                'assignment' => in_array($arguments['assignment'] ?? '', ['assigned', 'unassigned'], true) ? $arguments['assignment'] : '',
                'status'     => (string) ($arguments['status'] ?? ''),
                'category'   => isset($arguments['category_id']) ? (int) $arguments['category_id'] : null,
                'costCenter' => isset($arguments['cost_center_id']) ? (int) $arguments['cost_center_id'] : null,
            ];

            $service   = app(InventoryService::class);
            $paginator = $service->page($teamId, $tenantId, $filters, $sort, $direction, $limit, $page);

            $rows = collect($paginator->items())->map(fn (InventoryRow $r) => array_filter([
                'type'          => $r->type,
                'id'            => $r->id,
                'name'          => $r->name,
                'manufacturer'  => $r->manufacturer,
                'model'         => $r->model,
                'serial_number' => $r->serialNumber,
                'assigned_to'   => $r->assignedTo,
                'status'        => $r->statusSortKey !== '' ? $r->statusSortKey : null,
                'status_label'  => $r->statusLabel,
                'category'      => $r->categoryName,
                'cost_center'   => $r->costCenterName,
                'location'      => $r->location,
                'monthly_cost'  => $controlling ? round($r->monthlyCost, 2) : null,
            ], fn ($v, $k) => $v !== null || in_array($k, ['assigned_to', 'status'], true), ARRAY_FILTER_USE_BOTH))->values()->all();

            return ToolResult::success([
                'inventory'  => $rows,
                'counts'     => $service->counts($teamId, $tenantId),
                'pagination' => [
                    'page'     => $paginator->currentPage(),
                    'limit'    => $limit,
                    'total'    => $paginator->total(),
                    'has_more' => $paginator->hasMorePages(),
                ],
                'tenant_id'  => $tenantId,
                'statuses'   => ['manual' => AssetWriteService::ITEM_STATUSES],
                // Kategorien sind global — die IDs braucht asset-manager.items.POST.
                'categories' => AssetCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (AssetCategory $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
                'controlling_enabled' => $controlling,
                'hint'       => 'Manuelle Assets ändern: asset-manager.item.PUT; anlegen: asset-manager.items.POST.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Inventars: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'inventory', 'items', 'devices']];
    }
}
