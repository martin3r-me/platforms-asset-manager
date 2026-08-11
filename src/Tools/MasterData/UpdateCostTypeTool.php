<?php

namespace Platform\AssetManager\Tools\MasterData;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Aktualisiert eine Kostenart — Bezugsebene, Name, Default-Frequenz, Gutschrift-Flag.
 *
 * Die Kostenart war über MCP bisher nur lesbar. Das fiel zweimal auf: „Lap+Dock" musste auf
 * quartalsweise gestellt werden (ging nur über die Oberfläche, sonst wären alle 68 Positionen beim
 * nächsten Import wieder monatlich gewesen), und die Bezugsebene von ChatGPT/MobileIron war falsch.
 *
 * `aggregation_source` ist bewusst NICHT änderbar: sie bestimmt, aus welcher Quelle die Kostenart
 * ihren Pivot-Wert zieht (ADR 0001), und ein Wechsel verschiebt Beträge zwischen den Buckets.
 * Das gehört in die Oberfläche, wo die Folgen sichtbar sind.
 */
class UpdateCostTypeTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const FREQUENCIES = ['monthly', 'quarterly', 'yearly', 'once'];

    public function getName(): string
    {
        return 'asset-manager.cost-types.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/cost-types - Aktualisiert eine Kostenart (per id). Felder: '
            . 'allocation_level (person|cost_center — an was die Kosten hängen; steuert die '
            . 'Plausibilitätsprüfung), name, frequency_default (monthly|quarterly|yearly|once — die '
            . 'Frequenz, die der Excel-Import neuen Positionen gibt), allow_negative (Gutschriften), '
            . 'sort_order. aggregation_source ist NICHT änderbar: sie bestimmt die Pivot-Quelle '
            . '(ADR 0001) und ein Wechsel verschiebt Beträge zwischen den Kosten-Buckets.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id'                => ['type' => 'integer', 'description' => 'Kostenart-ID (erforderlich). IDs über asset-manager.cost-types.GET.'],
                'allocation_level'  => ['type' => 'string', 'enum' => AssetCostType::LEVELS, 'description' => 'Bezugsebene: person = gehört einem Asset-Träger, cost_center = gehört nur einer Kostenstelle.'],
                'name'              => ['type' => 'string', 'description' => 'Anzeigename.'],
                'frequency_default' => ['type' => 'string', 'enum' => self::FREQUENCIES, 'description' => 'Default-Frequenz für neue/importierte Positionen.'],
                'allow_negative'    => ['type' => 'boolean', 'description' => 'Erlaubt negative Beträge (Gutschrift/Rabatt).'],
                'sort_order'        => ['type' => 'integer', 'description' => 'Spaltenreihenfolge im Pivot.'],
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

            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            if (! Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $type = AssetCostType::where('team_id', $teamId)->find((int) ($arguments['id'] ?? 0));
            if (! $type) {
                return ToolResult::error('NOT_FOUND', 'Kostenart nicht gefunden.');
            }

            if (array_key_exists('allocation_level', $arguments)) {
                if (! in_array($arguments['allocation_level'], AssetCostType::LEVELS, true)) {
                    return ToolResult::error('VALIDATION_ERROR', 'allocation_level muss person oder cost_center sein.');
                }
                $type->allocation_level = $arguments['allocation_level'];
            }

            if (array_key_exists('frequency_default', $arguments)) {
                if (! in_array($arguments['frequency_default'], self::FREQUENCIES, true)) {
                    return ToolResult::error('VALIDATION_ERROR', 'frequency_default ungültig.');
                }
                $type->frequency_default = $arguments['frequency_default'];
            }

            if (array_key_exists('name', $arguments) && trim((string) $arguments['name']) !== '') {
                $type->name = trim((string) $arguments['name']);
            }
            if (array_key_exists('allow_negative', $arguments)) {
                $type->allow_negative = (bool) $arguments['allow_negative'];
            }
            if (array_key_exists('sort_order', $arguments)) {
                $type->sort_order = (int) $arguments['sort_order'];
            }

            $type->save();

            return ToolResult::success([
                'id'                => $type->id,
                'key'               => $type->key,
                'name'              => $type->name,
                'allocation_level'  => $type->allocation_level,
                'frequency_default' => $type->frequency_default,
                'allow_negative'    => (bool) $type->allow_negative,
                'sort_order'        => $type->sort_order,
                'tenant_id'         => $tenantId,
                // Die Default-Frequenz wirkt auf KÜNFTIGE Positionen. Bestehende behalten ihre eigene
                // — sonst würde ein Stammdaten-Klick rückwirkend Monatsbeträge verschieben.
                'note'              => 'frequency_default gilt für neue/importierte Positionen; bestehende Positionen bleiben unverändert (asset-manager.cost-lines.PUT).',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Kostenart: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'cost-types']];
    }
}
