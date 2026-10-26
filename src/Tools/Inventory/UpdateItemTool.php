<?php

namespace Platform\AssetManager\Tools\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Ein manuelles Asset ändern — die vier Dialoge der Inventar-Detailseite in einem Aufruf: Details/Status,
 * Zuordnung, Abschreibung, Notiz. Jeder Teil läuft über denselben {@see AssetWriteService}-Pfad wie dort
 * (Status-Regel #941: Ausmustern/Verlust/Lager löst die Zuordnung). Nur übergebene Felder ändern sich;
 * alles oder nichts — ein abgelehnter Teil nimmt die anderen mit zurück (#960).
 */
class UpdateItemTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const DETAIL_FIELDS = ['category_id', 'name', 'manufacturer', 'model', 'serial_number', 'status'];
    private const COST_FIELDS   = ['purchase_date', 'purchase_price', 'depreciation_months'];

    public function getName(): string
    {
        return 'asset-manager.item.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/item - Ändert EIN manuelles Asset (per id). Nur übergebene Felder ändern sich. '
            . 'Details: category_id, name, manufacturer, model, serial_number. status (in_stock|assigned|retired|lost): '
            . 'retired/lost/in_stock lösen eine Zuordnung, assigned nur mit Träger. Zuordnen: holder_id (Asset-Träger; '
            . 'null = zurück ins Lager), optional valid_from/valid_until (YYYY-MM-DD) — nicht zusammen mit status. '
            . 'Mit Controlling: purchase_date, purchase_price, depreciation_months. notes ("" leert).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id'                  => ['type' => 'integer', 'description' => 'ID des manuellen Assets (erforderlich).'],
                'category_id'         => ['type' => 'integer'],
                'name'                => ['type' => 'string'],
                'manufacturer'        => ['type' => 'string', 'description' => '"" leert.'],
                'model'               => ['type' => 'string', 'description' => '"" leert.'],
                'serial_number'       => ['type' => 'string', 'description' => '"" leert.'],
                'status'              => ['type' => 'string', 'enum' => AssetWriteService::ITEM_STATUSES],
                'holder_id'           => ['type' => ['integer', 'null'], 'description' => 'Asset-Träger zuordnen; null = zurück ins Lager.'],
                'valid_from'          => ['type' => 'string', 'description' => 'Zuordnung gültig ab YYYY-MM-DD (Default heute).'],
                'valid_until'         => ['type' => 'string', 'description' => 'Zuordnung gültig bis YYYY-MM-DD (optional).'],
                'purchase_date'       => ['type' => 'string', 'description' => 'YYYY-MM-DD, "" leert (nur mit Controlling).'],
                'purchase_price'      => ['type' => 'number', 'description' => 'EUR, null leert (nur mit Controlling).'],
                'depreciation_months' => ['type' => 'integer', 'description' => '1–240, null leert (nur mit Controlling).'],
                'notes'               => ['type' => 'string', 'description' => '"" leert.'],
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

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (! Gate::forUser($context->user)->allows('asset-manager.manage', [$teamId])) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }
            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            /** @var AssetItem|null $item */
            $item = AssetItem::where('team_id', $teamId)->find((int) $arguments['id']);
            if (! $item) {
                return ToolResult::error('NOT_FOUND', 'Asset nicht gefunden. Nutze asset-manager.inventory.GET (type=manual).');
            }

            $rules = CreateItemTool::rules($teamId, partial: true) + [
                'valid_from'  => ['nullable', 'date'],
                'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            ];
            if ($error = CreateItemTool::validationError($arguments, $rules)) {
                return ToolResult::error('VALIDATION_ERROR', $error);
            }

            $assigning = array_key_exists('holder_id', $arguments);
            if ($assigning && array_key_exists('status', $arguments)) {
                return ToolResult::error('VALIDATION_ERROR', 'holder_id und status nicht zusammen: zuordnen setzt den Status selbst (assigned bzw. in_stock).');
            }
            if (! $assigning && (array_key_exists('valid_from', $arguments) || array_key_exists('valid_until', $arguments))) {
                return ToolResult::error('VALIDATION_ERROR', 'valid_from/valid_until gelten nur zusammen mit holder_id.');
            }
            if (($arguments['status'] ?? null) === 'assigned' && $item->status !== 'assigned' && ! $item->assignee_id) {
                return ToolResult::error('VALIDATION_ERROR', '„assigned" geht nur mit Asset-Träger — bitte holder_id statt status übergeben.');
            }

            $touchesCost = array_intersect_key($arguments, array_flip(self::COST_FIELDS)) !== [];
            $controlling = app(ControllingContext::class)->enabledFor($teamId, $tenantId);
            if ($touchesCost && ! $controlling) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Abschreibungsdaten gehören zur Controlling-Schicht — für diesen Tenant deaktiviert.');
            }

            $writer = app(AssetWriteService::class);

            DB::transaction(function () use ($writer, $item, $arguments, $assigning, $touchesCost, $teamId) {
                // Details: der Service setzt fehlende Felder auf leer — deshalb der heutige Stand als Basis.
                if (array_intersect_key($arguments, array_flip(self::DETAIL_FIELDS)) !== []) {
                    $writer->updateItemDetails($item, [
                        'categoryId'   => array_key_exists('category_id', $arguments) ? (int) $arguments['category_id'] : $item->category_id,
                        'name'         => array_key_exists('name', $arguments) ? trim((string) $arguments['name']) : $item->name,
                        'manufacturer' => array_key_exists('manufacturer', $arguments) ? $arguments['manufacturer'] : $item->manufacturer,
                        'model'        => array_key_exists('model', $arguments) ? $arguments['model'] : $item->model,
                        'serialNumber' => array_key_exists('serial_number', $arguments) ? $arguments['serial_number'] : $item->serial_number,
                        'status'       => $arguments['status'] ?? $item->status,
                    ]);
                    $item->refresh();
                }

                if ($assigning) {
                    $holder = ! empty($arguments['holder_id'])
                        ? AssetHolder::where('team_id', $teamId)->find((int) $arguments['holder_id'])
                        : null;
                    $writer->assignItem($item, $holder, $arguments['valid_from'] ?? null, $arguments['valid_until'] ?? null);
                    $item->refresh();
                }

                if ($touchesCost) {
                    $writer->updateItemDepreciation($item, [
                        'purchaseDate'       => array_key_exists('purchase_date', $arguments) ? $arguments['purchase_date'] : $item->purchase_date?->toDateString(),
                        'purchasePrice'      => array_key_exists('purchase_price', $arguments) ? $arguments['purchase_price'] : $item->purchase_price,
                        'depreciationMonths' => array_key_exists('depreciation_months', $arguments)
                            ? ($arguments['depreciation_months'] !== null && $arguments['depreciation_months'] !== '' ? (int) $arguments['depreciation_months'] : null)
                            : $item->depreciation_months,
                    ]);
                }

                if (array_key_exists('notes', $arguments)) {
                    $writer->updateItemNotes($item, $arguments['notes'] !== null ? (string) $arguments['notes'] : null);
                }
            });

            $item->refresh()->load(['category', 'assignee']);

            return ToolResult::success([
                'item'    => GetItemTool::present($item, $controlling),
                'message' => "Asset „{$item->name}“ aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Assets: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'inventory', 'item']];
    }
}
