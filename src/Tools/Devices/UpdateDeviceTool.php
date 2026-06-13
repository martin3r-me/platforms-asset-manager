<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Einzel-Update eines Intune-Geräts: Kosten-Override, Kostenstelle/Kostenart, UPN-Neuzuordnung.
 * Für viele Geräte → asset-manager.devices.cost.bulk.PUT bzw. Modell-Defaults via device-models.PUT.
 */
class UpdateDeviceTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.device.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/device - Aktualisiert EIN Gerät (per id). Optionale Felder: '
            . 'monthly_cost (Leasing-Rate, hat Vorrang) ODER purchase_price + depreciation_months (Kauf/AfA), '
            . 'purchase_date, cost_type_id, cost_center_id (müssen zum Team gehören), user_principal_name '
            . '(Neuzuordnung; "" entfernt die Zuordnung). Zahlenfelder auf null/"" setzen löscht den Override.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'                  => ['type' => 'integer', 'description' => 'Geräte-ID (erforderlich).'],
                'monthly_cost'        => ['type' => 'number', 'description' => 'Monatliche Leasing-Rate (EUR). Hat Vorrang vor Kauf/AfA.'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis (EUR) für AfA.'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'Abschreibungsdauer in Monaten.'],
                'purchase_date'       => ['type' => 'string', 'description' => 'Kaufdatum YYYY-MM-DD.'],
                'cost_type_id'        => ['type' => 'integer', 'description' => 'Kostenart-ID (Team).'],
                'cost_center_id'      => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'user_principal_name' => ['type' => 'string', 'description' => 'UPN-Neuzuordnung; "" entfernt.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }
            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            /** @var AssetDevice|null $d */
            $d = AssetDevice::where('team_id', $teamId)->find((int) $arguments['id']);
            if (!$d) {
                return ToolResult::error('NOT_FOUND', 'Gerät nicht gefunden.');
            }

            if (array_key_exists('cost_type_id', $arguments) && $arguments['cost_type_id']) {
                if (!AssetCostType::where('team_id', $teamId)->whereKey((int) $arguments['cost_type_id'])->exists()) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team. Nutze asset-manager.cost-types.GET.');
                }
                $d->cost_type_id = (int) $arguments['cost_type_id'];
            }
            if (array_key_exists('cost_center_id', $arguments) && $arguments['cost_center_id']) {
                if (!AssetCostCenter::where('team_id', $teamId)->whereKey((int) $arguments['cost_center_id'])->exists()) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_center_id gehört nicht zum Team. Nutze asset-manager.cost-centers.GET.');
                }
                $d->cost_center_id = (int) $arguments['cost_center_id'];
            }

            foreach (['monthly_cost', 'purchase_price', 'depreciation_months', 'purchase_date'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $arguments[$f];
                    $d->{$f} = ($v === '' || $v === null) ? null : $v;
                }
            }
            if (array_key_exists('user_principal_name', $arguments)) {
                $upn = trim((string) $arguments['user_principal_name']);
                $d->user_principal_name = $upn === '' ? null : $upn;
            }

            $d->save();

            return ToolResult::success([
                'id'                  => $d->id,
                'device_name'         => $d->device_name,
                'resolved_monthly'    => $d->resolvedMonthlyCost(),
                'user_principal_name' => $d->user_principal_name,
                'cost_type_id'        => $d->cost_type_id,
                'cost_center_id'      => $d->cost_center_id,
                'message'             => "Gerät '{$d->device_name}' aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Geräts: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'device']];
    }
}
