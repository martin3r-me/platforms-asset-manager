<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet Geräte-Modelle (Default-Kosten je Hersteller+Modell) inkl. Anzahl der real
 * vorhandenen Geräte, die auf das jeweilige Modell matchen.
 */
class ListDeviceModelsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.device-models.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/device-models - Listet Geräte-Modelle des Teams mit ihren '
            . 'Default-Kosten (monthly_cost ODER purchase_price+depreciation_months), Kostenart/Kreditor '
            . 'und der Anzahl gematchter Geräte. Default-Kosten wirken auf alle Geräte dieses Modells '
            . 'ohne eigenen Override — pflegbar via asset-manager.device-models.PUT.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Geräte-Counts je normalisiertem (Hersteller|Modell)-Schlüssel
            $deviceCounts = [];
            foreach (AssetDevice::where('team_id', $teamId)->get(['manufacturer', 'model']) as $d) {
                $key = AssetDeviceModel::normalizeKey($d->manufacturer, $d->model);
                $deviceCounts[$key] = ($deviceCounts[$key] ?? 0) + 1;
            }

            $models = AssetDeviceModel::where('team_id', $teamId)->with(['costType', 'vendor'])
                ->orderBy('manufacturer')->orderBy('model')->get()
                ->map(function (AssetDeviceModel $m) use ($deviceCounts) {
                    $key = AssetDeviceModel::normalizeKey($m->manufacturer, $m->model);
                    return [
                        'id'                  => $m->id,
                        'manufacturer'        => $m->manufacturer,
                        'model'               => $m->model,
                        'monthly_cost'        => $m->monthly_cost !== null ? (float) $m->monthly_cost : null,
                        'purchase_price'      => $m->purchase_price !== null ? (float) $m->purchase_price : null,
                        'depreciation_months' => $m->depreciation_months,
                        'cost_type'           => $m->costType?->name,
                        'cost_type_id'        => $m->cost_type_id,
                        'vendor'              => $m->vendor?->name,
                        'device_count'        => $deviceCounts[$key] ?? 0,
                    ];
                })->values()->all();

            return ToolResult::success(['device_models' => $models, 'count' => count($models)]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Geräte-Modelle: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'device-models']];
    }
}
