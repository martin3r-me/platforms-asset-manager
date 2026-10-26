<?php

namespace Platform\AssetManager\Tools\Inventory;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\GermanValidation;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Manuelles Asset anlegen — auch mehrere gleiche Stücke in einem Aufruf. Läuft über
 * {@see AssetWriteService::createItems()}, also dieselben Regeln wie „Asset anlegen" auf der Inventar-Seite:
 * mit Träger wird der Status „Zugeordnet" und eine Zuordnung geschrieben, bei mehreren Stücken bleibt die
 * Seriennummer leer, und ohne Controlling werden keine Abschreibungsdaten gespeichert (ADR 0008).
 */
class CreateItemTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.items.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/items - Legt ein manuelles Asset an (Monitor, Dock, Drucker …). Pflicht: category_id '
            . '(IDs in der Antwort von asset-manager.inventory.GET unter categories), name. Optional: manufacturer, model, '
            . 'serial_number, holder_id (Asset-Träger → Status „Zugeordnet"), status (in_stock|assigned|retired|lost, '
            . 'Default in_stock), quantity (1–500 gleiche Stücke; Seriennummer dann leer lassen), notes; mit Controlling '
            . 'purchase_date, purchase_price, depreciation_months.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'category_id'         => ['type' => 'integer', 'description' => 'Kategorie (erforderlich).'],
                'name'                => ['type' => 'string', 'description' => 'Bezeichnung (erforderlich).'],
                'manufacturer'        => ['type' => 'string'],
                'model'               => ['type' => 'string'],
                'serial_number'       => ['type' => 'string', 'description' => 'Nur bei quantity=1.'],
                'holder_id'           => ['type' => 'integer', 'description' => 'Asset-Träger (Team). Setzt den Status auf assigned.'],
                'status'              => ['type' => 'string', 'enum' => AssetWriteService::ITEM_STATUSES],
                'quantity'            => ['type' => 'integer', 'description' => 'Anzahl gleicher Stücke (Default 1, max 500).'],
                'purchase_date'       => ['type' => 'string', 'description' => 'YYYY-MM-DD (nur mit Controlling).'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis EUR (nur mit Controlling).'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'AfA-Monate 1–240 (nur mit Controlling).'],
                'notes'               => ['type' => 'string'],
            ], $this->tenantSchemaProperty()),
            'required' => ['category_id', 'name'],
        ];
    }

    /**
     * Die Feldregeln der Inventar-Seite (Inventory/Index::createItem, Inventory/Show) — für Anlage und Änderung.
     * `$partial`: bei einer Änderung ist nichts Pflicht.
     */
    public static function rules(int $teamId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'category_id'         => [$required, 'integer', 'exists:asset_categories,id'],
            'name'                => [$required, 'string', 'max:255'],
            'manufacturer'        => ['nullable', 'string', 'max:255'],
            'model'               => ['nullable', 'string', 'max:255'],
            'serial_number'       => ['nullable', 'string', 'max:255'],
            // Asset-Träger MUSS zum eigenen Team gehören (sonst danglende cross-team FK).
            'holder_id'           => ['nullable', 'integer', Rule::exists('asset_holders', 'id')->where('team_id', $teamId)],
            'status'              => ['sometimes', 'in:' . implode(',', AssetWriteService::ITEM_STATUSES)],
            'purchase_date'       => ['nullable', 'date'],
            'purchase_price'      => ['nullable', 'numeric', 'min:0'],
            'depreciation_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'notes'               => ['nullable', 'string'],
        ];
    }

    /** Erste Validierungsmeldung je Feld als ein Satz — oder null. */
    public static function validationError(array $arguments, array $rules): ?string
    {
        $validator = Validator::make($arguments, $rules, GermanValidation::messages());

        return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
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

            $rules = self::rules($teamId) + ['quantity' => ['nullable', 'integer', 'min:1', 'max:500']];
            if ($error = self::validationError($arguments, $rules)) {
                return ToolResult::error('VALIDATION_ERROR', $error);
            }

            $quantity = (int) ($arguments['quantity'] ?? 1);
            // Eine Seriennummer gehört zu genau einem Stück — lieber ablehnen als still verwerfen (wie im UI).
            if ($quantity > 1 && trim((string) ($arguments['serial_number'] ?? '')) !== '') {
                return ToolResult::error('VALIDATION_ERROR', 'Bei mehreren Stücken bleibt die Seriennummer leer — sie wird je Stück mit asset-manager.item.PUT ergänzt.');
            }
            if (($arguments['status'] ?? null) === 'assigned' && empty($arguments['holder_id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'Status „assigned" geht nur mit holder_id.');
            }

            $controlling = app(ControllingContext::class)->enabledFor($teamId, $tenantId);
            $warnings    = [];
            $costFields  = array_intersect_key($arguments, array_flip(['purchase_date', 'purchase_price', 'depreciation_months']));
            if (! $controlling && array_filter($costFields, fn ($v) => $v !== null && $v !== '') !== []) {
                $warnings[] = 'Controlling ist für diesen Tenant aus — Kaufdatum, Kaufpreis und AfA wurden nicht gespeichert.';
            }

            $items = app(AssetWriteService::class)->createItems([
                'categoryId'         => (int) $arguments['category_id'],
                'name'               => trim((string) $arguments['name']),
                'manufacturer'       => $arguments['manufacturer'] ?? null,
                'model'              => $arguments['model'] ?? null,
                'serialNumber'       => $arguments['serial_number'] ?? null,
                'assigneeId'         => ! empty($arguments['holder_id']) ? (int) $arguments['holder_id'] : null,
                'status'             => $arguments['status'] ?? 'in_stock',
                'purchaseDate'       => $controlling ? ($arguments['purchase_date'] ?? null) : null,
                'purchasePrice'      => $controlling ? ($arguments['purchase_price'] ?? null) : null,
                'depreciationMonths' => $controlling && isset($arguments['depreciation_months']) ? (int) $arguments['depreciation_months'] : null,
                'notes'              => $arguments['notes'] ?? null,
                'tenantId'           => $tenantId,
            ], $quantity, $teamId, (int) ($context->user->id ?? 0));

            $first = $items->first()->load(['category', 'assignee']);

            return ToolResult::success([
                'created'  => $items->count(),
                'ids'      => $items->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'item'     => GetItemTool::present($first, $controlling),
                'warnings' => $warnings,
                'message'  => $items->count() > 1
                    ? "{$items->count()}× „{$first->name}“ angelegt."
                    : "Asset „{$first->name}“ angelegt.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen des Assets: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'inventory', 'item']];
    }
}
