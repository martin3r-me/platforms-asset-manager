<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Detailansicht eines einzelnen Intune-Geräts inkl. vollständiger Kosten-Auflösungskette
 * (Geräte-Override → Modell-Default), gematchtem Geräte-Modell, Assignee und Kostenstelle/Kostenart.
 */
class GetDeviceTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.device.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/device - Detail eines Intune-Geräts per id. Zeigt die '
            . 'Kosten-Auflösungskette (Geräte-Override vs. Modell-Default), das gematchte Geräte-Modell, '
            . 'den zugeordneten Mitarbeiter und Kostenstelle/Kostenart.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Geräte-ID.']],
            'required'   => ['id'],
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
            $d = AssetDevice::where('team_id', $teamId)->with(['assignee', 'costType', 'costCenter'])->find((int) $arguments['id']);
            if (!$d) {
                return ToolResult::error('NOT_FOUND', 'Gerät nicht gefunden. Nutze asset-manager.devices.GET zum Suchen.');
            }

            $own   = AssetDevice::computeMonthlyFrom($d->monthly_cost, $d->purchase_price, $d->depreciation_months, $d->purchase_date);
            $model = $d->deviceModel();
            $fromModel = $model ? AssetDevice::computeMonthlyFrom($model->monthly_cost, $model->purchase_price, $model->depreciation_months, null) : null;

            return ToolResult::success([
                'id'                  => $d->id,
                'device_name'         => $d->device_name,
                'intune_id'           => $d->intune_id,
                'manufacturer'        => $d->manufacturer,
                'model'               => $d->model,
                'serial_number'       => $d->serial_number,
                'operating_system'    => $d->operating_system,
                'os_version'          => $d->os_version,
                'compliance'          => $d->complianceLabel(),
                'management_state'    => $d->management_state,
                'device_type'         => $d->device_type,
                'user_principal_name' => $d->user_principal_name,
                'assignee'            => $d->assignee ? ['id' => $d->assignee->id, 'name' => $d->assignee->name] : null,
                'enrolled_at'         => $d->enrolled_at?->toIso8601String(),
                'last_check_in_at'    => $d->last_check_in_at?->toIso8601String(),
                'cost' => [
                    'resolved_monthly'    => $d->resolvedMonthlyCost(),
                    'resolved_cost_type'  => $d->resolvedCostTypeId(),
                    'source'              => $own !== null ? 'override' : ($fromModel !== null ? 'model' : 'none'),
                    'override' => [
                        'monthly_cost'        => $d->monthly_cost !== null ? (float) $d->monthly_cost : null,
                        'purchase_price'      => $d->purchase_price !== null ? (float) $d->purchase_price : null,
                        'depreciation_months' => $d->depreciation_months,
                        'purchase_date'       => $d->purchase_date?->toDateString(),
                        'cost_type'           => $d->costType?->name,
                        'cost_center'         => $d->costCenter?->label,
                    ],
                    'model' => $model ? [
                        'id'                  => $model->id,
                        'manufacturer'        => $model->manufacturer,
                        'model'               => $model->model,
                        'monthly_cost'        => $model->monthly_cost !== null ? (float) $model->monthly_cost : null,
                        'purchase_price'      => $model->purchase_price !== null ? (float) $model->purchase_price : null,
                        'depreciation_months' => $model->depreciation_months,
                        'monthly_from_model'  => $fromModel,
                    ] : null,
                ],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Geräts: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'device', 'intune']];
    }
}
