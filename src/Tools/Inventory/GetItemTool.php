<?php

namespace Platform\AssetManager\Tools\Inventory;

use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Ein manuelles Asset im Detail — dieselben Angaben wie die Inventar-Detailseite: Stammdaten, Status,
 * aktuelle Zuordnung samt Verlauf, Notiz und (nur mit Controlling) Abschreibung und Monatskosten.
 */
class GetItemTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.item.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/item - Ein manuelles Asset (per id aus asset-manager.inventory.GET, type=manual): '
            . 'Kategorie, Hersteller/Modell/Seriennr., Status, aktueller Asset-Träger, Zuordnungsverlauf (letzte 20), '
            . 'Notiz; mit Controlling zusätzlich Kaufdatum, Kaufpreis, AfA-Monate und Monatskosten.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id' => ['type' => 'integer', 'description' => 'ID des manuellen Assets (erforderlich).'],
            ], $this->tenantSchemaProperty()),
            'required' => ['id'],
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

            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            /** @var AssetItem|null $item */
            $item = AssetItem::where('team_id', $teamId)->with(['category', 'assignee'])->find((int) $arguments['id']);
            if (! $item) {
                return ToolResult::error('NOT_FOUND', 'Asset nicht gefunden. Nutze asset-manager.inventory.GET (type=manual).');
            }

            return ToolResult::success(self::present($item, app(ControllingContext::class)->enabledFor($teamId, $tenantId)));
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Assets: ' . $e->getMessage());
        }
    }

    /** Gemeinsame Darstellung für item.GET und die Antworten der Schreib-Tools. */
    public static function present(AssetItem $item, bool $controlling): array
    {
        $history = $item->assignments()->with('holder')->orderByDesc('assigned_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($a) => [
                'holder_id'   => $a->holder_id,
                'holder'      => $a->holder?->name,
                'assigned_at' => $a->assigned_at?->toDateString(),
                'returned_at' => $a->returned_at?->toDateString(),
                'source'      => $a->source,
            ])->values()->all();

        $data = [
            'id'            => $item->id,
            'name'          => $item->name,
            'category_id'   => $item->category_id,
            'category'      => $item->category?->name,
            'manufacturer'  => $item->manufacturer,
            'model'         => $item->model,
            'serial_number' => $item->serial_number,
            'status'        => $item->status,
            'status_label'  => $item->statusLabel(),
            'holder_id'     => $item->assignee_id,
            'holder'        => $item->assignee?->name,
            'assigned_at'   => $item->assigned_at?->toDateString(),
            'notes'         => $item->notes,
            'assignments'   => $history,
            'tenant_id'     => $item->tenant_id,
        ];

        // Kosten nur mit Controlling (ADR 0008).
        if ($controlling) {
            $data += [
                'purchase_date'       => $item->purchase_date?->toDateString(),
                'purchase_price'      => $item->purchase_price !== null ? (float) $item->purchase_price : null,
                'depreciation_months' => $item->depreciation_months !== null ? (int) $item->depreciation_months : null,
                'monthly_cost'        => round($item->monthlyCost(), 2),
            ];
        }

        return $data;
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'inventory', 'item']];
    }
}
