<?php

namespace Platform\AssetManager\Tools\Devices;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetDeviceModelCost;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Legt Default-Kosten je Geräte-Modell (Hersteller + Modell) an oder aktualisiert sie.
 * Das Matching ist case-/whitespace-tolerant (AssetDeviceModel::normalizeKey) — wirkt auf alle
 * Geräte dieses Modells, die keinen eigenen Override haben.
 */
class UpsertDeviceModelTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.device-models.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/device-models - Upsert Default-Kosten je (manufacturer, model). '
            . 'Felder: manufacturer + model (erforderlich), monthly_cost (Leasing, Vorrang) ODER '
            . 'purchase_price + depreciation_months, cost_type_id, vendor_id (Team). Wirkt auf alle '
            . 'Geräte dieses Modells ohne eigenen Override; die Antwort meldet die Anzahl betroffener Geräte.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'manufacturer'        => ['type' => 'string', 'description' => 'Hersteller (erforderlich).'],
                'model'               => ['type' => 'string', 'description' => 'Modell (erforderlich).'],
                'monthly_cost'        => ['type' => 'number', 'description' => 'Monatliche Leasing-Rate (EUR).'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis (EUR) für AfA.'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'Abschreibungsdauer in Monaten.'],
                'cost_type_id'        => ['type' => 'integer', 'description' => 'Kostenart-ID (Team).'],
                'vendor_id'           => ['type' => 'integer', 'description' => 'Kreditor-ID (Team).'],
            ], $this->tenantSchemaProperty()),
            'required' => ['manufacturer', 'model'],
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

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $manufacturer = trim((string) ($arguments['manufacturer'] ?? ''));
            $model        = trim((string) ($arguments['model'] ?? ''));
            if ($manufacturer === '' || $model === '') {
                return ToolResult::error('VALIDATION_ERROR', 'manufacturer und model sind erforderlich.');
            }

            if (!empty($arguments['cost_type_id']) && !AssetCostType::where('team_id', $teamId)->whereKey((int) $arguments['cost_type_id'])->exists()) {
                return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team.');
            }
            if (!empty($arguments['vendor_id']) && !AssetVendor::where('team_id', $teamId)->whereKey((int) $arguments['vendor_id'])->exists()) {
                return ToolResult::error('VALIDATION_ERROR', 'vendor_id gehört nicht zum Team.');
            }

            // Bestehendes Modell via normalisiertem Key finden (sonst neu anlegen)
            $key      = AssetDeviceModel::normalizeKey($manufacturer, $model);
            $existing = AssetDeviceModel::where('team_id', $teamId)->get()
                ->first(fn ($m) => AssetDeviceModel::normalizeKey($m->manufacturer, $m->model) === $key);

            $modelRow = $existing ?: new AssetDeviceModel(['team_id' => $teamId, 'manufacturer' => $manufacturer, 'model' => $model]);

            // Nutzungsdauer bleibt am Modell (team-weit, Hardware-Eigenschaft).
            if (array_key_exists('depreciation_months', $arguments)) {
                $v = $arguments['depreciation_months'];
                $modelRow->depreciation_months = ($v === '' || $v === null) ? null : $v;
            }
            $modelRow->save();

            // Preise, Kostenart und Kreditor sind pro Kunde verhandelt -> tenant-eigene Kostenzeile
            // (ADR 0016). Nur die uebergebenen Felder anfassen, damit ein Teil-Update nichts nullt.
            $costRow = AssetDeviceModelCost::withoutTenantScope()->firstOrNew([
                'tenant_id'       => $tenantId,
                'device_model_id' => $modelRow->id,
            ]);
            $costRow->team_id = $teamId;

            foreach (['monthly_cost', 'purchase_price'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $arguments[$f];
                    $costRow->{$f} = ($v === '' || $v === null) ? null : $v;
                }
            }
            if (array_key_exists('cost_type_id', $arguments)) {
                $costRow->cost_type_id = $arguments['cost_type_id'] ? (int) $arguments['cost_type_id'] : null;
            }
            if (array_key_exists('vendor_id', $arguments)) {
                $costRow->vendor_id = $arguments['vendor_id'] ? (int) $arguments['vendor_id'] : null;
            }
            $costRow->save();

            $deviceCount = AssetDevice::where('team_id', $teamId)->get(['manufacturer', 'model'])
                ->filter(fn ($d) => AssetDeviceModel::normalizeKey($d->manufacturer, $d->model) === $key)
                ->count();

            return ToolResult::success([
                'id'                  => $modelRow->id,
                'manufacturer'        => $modelRow->manufacturer,
                'model'               => $modelRow->model,
                'tenant_id'           => $tenantId,
                'monthly_cost'        => $costRow->monthly_cost !== null ? (float) $costRow->monthly_cost : null,
                'purchase_price'      => $costRow->purchase_price !== null ? (float) $costRow->purchase_price : null,
                'depreciation_months' => $modelRow->depreciation_months,
                'created'             => !$existing,
                'affected_devices'    => $deviceCount,
                'message'             => ($existing ? 'Geräte-Modell aktualisiert' : 'Geräte-Modell angelegt')
                    . " — wirkt auf {$deviceCount} Gerät(e) ohne eigenen Override.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Speichern des Geräte-Modells: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'device-models']];
    }
}
