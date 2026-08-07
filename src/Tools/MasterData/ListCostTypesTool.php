<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Listet die Kostenarten des Teams inkl. aggregation_source — wichtig, um beim Anlegen manueller
 * Kostenpositionen die richtige (cost_line-)Kostenart zu wählen.
 */
class ListCostTypesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.cost-types.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/cost-types - Listet die Kostenarten des Teams (id, key, name, '
            . 'aggregation_source [cost_line|hardware_afa|ms_license|asset_device], frequency_default, '
            . 'is_per_employee). Manuelle Kostenpositionen sind nur für cost_line-Kostenarten zulässig.';
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

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $rows = AssetCostType::where('team_id', $teamId)->with('vendorDefault')
                ->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (AssetCostType $t) => [
                    'id'                 => $t->id,
                    'key'                => $t->key,
                    'name'               => $t->name,
                    'aggregation_source' => $t->aggregation_source,
                    'frequency_default'  => $t->frequency_default,
                    'is_per_employee'    => (bool) $t->is_per_employee,
                    'vendor_default'     => $t->vendorDefault?->name,
                ])->values()->all();

            return ToolResult::success(['cost_types' => $rows, 'count' => count($rows)]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Kostenarten: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'cost-types', 'master-data']];
    }
}
