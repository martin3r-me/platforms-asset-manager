<?php

namespace Platform\AssetManager\Tools\CostLines;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Legt eine manuelle Kostenposition an (source='manual'). monthly_amount wird vom Model automatisch
 * aus amount/fx_rate/frequency abgeleitet.
 *
 * STRIKTE Kostenart-Prüfung: nur Kostenarten mit aggregation_source='cost_line' sind zulässig — andere
 * fallen still aus der Kostenaufteilung (normalizedLines zählt nur cost_line-Typen).
 */
class CreateCostLineTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    private const FREQUENCIES = ['monthly', 'quarterly', 'yearly', 'once'];

    public function getName(): string
    {
        return 'asset-manager.cost-lines.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/cost-lines - Legt eine manuelle Kostenposition an. Erforderlich: '
            . 'cost_type_id (Kostenart MUSS aggregation_source=cost_line haben), amount, frequency '
            . '(monthly|quarterly|yearly|once). Optional: label, currency (Default EUR), fx_rate, '
            . 'vendor_id, cost_center_id, assignee_id, valid_from, valid_to. monthly_amount wird '
            . 'automatisch berechnet. Für gültige Kostenarten → asset-manager.cost-types.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'cost_type_id'   => ['type' => 'integer', 'description' => 'Kostenart-ID (aggregation_source=cost_line). Erforderlich.'],
                'amount'         => ['type' => 'number', 'description' => 'Betrag in der angegebenen Frequenz/Währung. Erforderlich.'],
                'frequency'      => ['type' => 'string', 'enum' => self::FREQUENCIES, 'description' => 'Frequenz. Erforderlich.'],
                'label'          => ['type' => 'string', 'description' => 'Bezeichnung der Position.'],
                'currency'       => ['type' => 'string', 'description' => 'Währung (Default EUR).'],
                'fx_rate'        => ['type' => 'number', 'description' => 'Umrechnungskurs zu EUR (Default 1).'],
                'vendor_id'      => ['type' => 'integer', 'description' => 'Kreditor-ID (Team).'],
                'cost_center_id' => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'assignee_id'    => ['type' => 'integer', 'description' => 'Mitarbeiter-ID (Team).'],
                'valid_from'     => ['type' => 'string', 'description' => 'Gültig ab YYYY-MM-DD.'],
                'valid_to'       => ['type' => 'string', 'description' => 'Gültig bis YYYY-MM-DD.'],
            ],
            'required' => ['cost_type_id', 'amount', 'frequency'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $frequency = (string) ($arguments['frequency'] ?? '');
            if (!in_array($frequency, self::FREQUENCIES, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'frequency muss eines von: ' . implode(', ', self::FREQUENCIES));
            }
            if (!isset($arguments['amount']) || !is_numeric($arguments['amount'])) {
                return ToolResult::error('VALIDATION_ERROR', 'amount (Zahl) ist erforderlich.');
            }

            $costType = AssetCostType::where('team_id', $teamId)->find((int) ($arguments['cost_type_id'] ?? 0));
            if (!$costType) {
                return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team. Nutze asset-manager.cost-types.GET.');
            }
            if ($costType->aggregation_source !== 'cost_line') {
                $valid = AssetCostType::where('team_id', $teamId)->where('aggregation_source', 'cost_line')
                    ->pluck('name', 'id')->map(fn ($n, $id) => "$id=$n")->values()->implode(', ');
                return ToolResult::error('INVALID_COST_TYPE', "Kostenart '{$costType->name}' hat aggregation_source='{$costType->aggregation_source}'. "
                    . "Manuelle Kostenpositionen sind nur für cost_line-Kostenarten zulässig (sonst unsichtbar im Pivot). Gültig: {$valid}");
            }

            $error = $this->validateOptionalRefs($arguments, $teamId);
            if ($error) {
                return $error;
            }

            $line = AssetCostLine::create([
                'team_id'        => $teamId,
                'cost_type_id'   => $costType->id,
                'amount'         => (float) $arguments['amount'],
                'frequency'      => $frequency,
                'currency'       => $arguments['currency'] ?? 'EUR',
                'fx_rate'        => $arguments['fx_rate'] ?? null,
                'label'          => $arguments['label'] ?? null,
                'vendor_id'      => $arguments['vendor_id'] ?? null,
                'cost_center_id' => $arguments['cost_center_id'] ?? null,
                'assignee_id'    => $arguments['assignee_id'] ?? null,
                'valid_from'     => !empty($arguments['valid_from']) ? $arguments['valid_from'] : null,
                'valid_to'       => !empty($arguments['valid_to']) ? $arguments['valid_to'] : null,
                'source'         => 'manual',
                'active'         => true,
            ]);

            return ToolResult::success([
                'id'             => $line->id,
                'label'          => $line->label,
                'cost_type'      => $costType->name,
                'amount'         => (float) $line->amount,
                'frequency'      => $line->frequency,
                'monthly_amount' => (float) $line->monthly_amount,
                'message'        => 'Kostenposition angelegt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen der Kostenposition: ' . $e->getMessage());
        }
    }

    /** Prüft, dass optionale FKs (vendor/cost_center/assignee) zum Team gehören. */
    private function validateOptionalRefs(array $arguments, int $teamId): ?ToolResult
    {
        if (!empty($arguments['vendor_id']) && !AssetVendor::where('team_id', $teamId)->whereKey((int) $arguments['vendor_id'])->exists()) {
            return ToolResult::error('VALIDATION_ERROR', 'vendor_id gehört nicht zum Team.');
        }
        if (!empty($arguments['cost_center_id']) && !AssetCostCenter::where('team_id', $teamId)->whereKey((int) $arguments['cost_center_id'])->exists()) {
            return ToolResult::error('VALIDATION_ERROR', 'cost_center_id gehört nicht zum Team.');
        }
        if (!empty($arguments['assignee_id']) && !AssetEmployee::where('team_id', $teamId)->whereKey((int) $arguments['assignee_id'])->exists()) {
            return ToolResult::error('VALIDATION_ERROR', 'assignee_id gehört nicht zum Team.');
        }
        return null;
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'cost-lines']];
    }
}
