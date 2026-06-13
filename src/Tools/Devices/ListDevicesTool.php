<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;

/**
 * Listet Intune-Geräte des aktiven Teams inkl. aufgelöster Monatskosten, Kostenquelle
 * (override/model/none) und zugeordnetem Mitarbeiter.
 */
class ListDevicesTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.devices.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/devices - Listet Intune-Geräte des aktiven Teams. Filterbare Felder: '
            . 'device_name, operating_system, os_version, compliance_state, management_state, manufacturer, '
            . 'model, serial_number, user_principal_name, device_type, source. Tipp: nicht zugewiesene '
            . 'Geräte via filters [{"field":"user_principal_name","op":"is_null"}]. Nutze search, sort, '
            . 'limit/offset. Antwort enthält aufgelöste Monatskosten + Kostenquelle + Assignee.';
    }

    public function getSchema(): array
    {
        return $this->getStandardGetSchema();
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $allowed = ['device_name', 'operating_system', 'os_version', 'compliance_state', 'management_state',
                'manufacturer', 'model', 'serial_number', 'user_principal_name', 'device_type', 'source'];

            $query = AssetDevice::where('team_id', $teamId)->with(['assignee', 'costType', 'costCenter']);
            $this->applyStandardFilters($query, $arguments, $allowed);
            $this->applyStandardSearch($query, $arguments, ['device_name', 'serial_number', 'model', 'user_principal_name']);
            $this->applyStandardSort($query, $arguments, array_merge($allowed, ['last_check_in_at', 'enrolled_at', 'monthly_cost']), 'device_name', 'asc');

            $result  = $this->applyStandardPaginationResult($query, $arguments);

            $rows = $result['data']->map(function (AssetDevice $d) {
                $own   = AssetDevice::computeMonthlyFrom($d->monthly_cost, $d->purchase_price, $d->depreciation_months, $d->purchase_date);
                $model = $d->deviceModel();
                $fromModel = $model ? AssetDevice::computeMonthlyFrom($model->monthly_cost, $model->purchase_price, $model->depreciation_months, null) : null;
                $source = $own !== null ? 'override' : ($fromModel !== null ? 'model' : 'none');

                return [
                    'id'                  => $d->id,
                    'device_name'         => $d->device_name,
                    'manufacturer'        => $d->manufacturer,
                    'model'               => $d->model,
                    'serial_number'       => $d->serial_number,
                    'operating_system'    => $d->operating_system,
                    'os_version'          => $d->os_version,
                    'compliance'          => $d->complianceLabel(),
                    'compliance_state'    => $d->compliance_state,
                    'user_principal_name' => $d->user_principal_name,
                    'assignee'            => $d->assignee?->name,
                    'monthly_cost'        => $d->resolvedMonthlyCost(),
                    'cost_source'         => $source,
                    'cost_type'           => $d->costType?->name,
                    'cost_center'         => $d->costCenter?->label,
                    'last_check_in_at'    => $d->last_check_in_at?->toIso8601String(),
                ];
            })->values()->all();

            return ToolResult::success([
                'devices'    => $rows,
                'pagination' => $result['pagination'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Geräte: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'devices', 'intune']];
    }
}
