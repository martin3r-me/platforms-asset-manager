<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
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
    use ResolvesTenant;

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
        return [
            'type'       => 'object',
            'properties' => $this->tenantSchemaProperty(),
            'required'   => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Geräte-Counts je normalisiertem (Hersteller|Modell)-Schlüssel
            $deviceCounts = [];
            foreach (AssetDevice::where('team_id', $teamId)->get(['manufacturer', 'model']) as $d) {
                $key = AssetDeviceModel::normalizeKey($d->manufacturer, $d->model);
                $deviceCounts[$key] = ($deviceCounts[$key] ?? 0) + 1;
            }

            // Katalog ist team-weit, die Kosten dazu tenant-eigen (ADR 0016) — die cost-Relation traegt
            // den Global Scope und liefert die Zeile des aufgeloesten Tenants.
            $models = AssetDeviceModel::where('team_id', $teamId)
                ->with(['cost.costType', 'cost.vendor'])
                ->orderBy('manufacturer')->orderBy('model')->get()
                ->map(function (AssetDeviceModel $m) use ($deviceCounts) {
                    $key  = AssetDeviceModel::normalizeKey($m->manufacturer, $m->model);
                    $cost = $m->cost;

                    return [
                        'id'                  => $m->id,
                        'manufacturer'        => $m->manufacturer,
                        'model'               => $m->model,
                        'monthly_cost'        => $cost?->monthly_cost !== null ? (float) $cost->monthly_cost : null,
                        'purchase_price'      => $cost?->purchase_price !== null ? (float) $cost->purchase_price : null,
                        'depreciation_months' => $m->depreciation_months,
                        'cost_type'           => $cost?->costType?->name,
                        'cost_type_id'        => $cost?->cost_type_id,
                        'vendor'              => $cost?->vendor?->name,
                        'device_count'        => $deviceCounts[$key] ?? 0,
                    ];
                })->values()->all();

            return ToolResult::success([
                'device_models' => $models,
                'count'         => count($models),
                'note'          => 'Hersteller/Modell sind team-weit; monthly_cost, purchase_price, '
                    . 'cost_type und vendor gelten fuer den aktiven Tenant.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Geräte-Modelle: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'device-models']];
    }
}
