<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
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
            'properties' => [
                'manufacturer'        => ['type' => 'string', 'description' => 'Hersteller (erforderlich).'],
                'model'               => ['type' => 'string', 'description' => 'Modell (erforderlich).'],
                'monthly_cost'        => ['type' => 'number', 'description' => 'Monatliche Leasing-Rate (EUR).'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis (EUR) für AfA.'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'Abschreibungsdauer in Monaten.'],
                'cost_type_id'        => ['type' => 'integer', 'description' => 'Kostenart-ID (Team).'],
                'vendor_id'           => ['type' => 'integer', 'description' => 'Kreditor-ID (Team).'],
            ],
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

            foreach (['monthly_cost', 'purchase_price', 'depreciation_months'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $arguments[$f];
                    $modelRow->{$f} = ($v === '' || $v === null) ? null : $v;
                }
            }
            if (array_key_exists('cost_type_id', $arguments)) {
                $modelRow->cost_type_id = $arguments['cost_type_id'] ? (int) $arguments['cost_type_id'] : null;
            }
            if (array_key_exists('vendor_id', $arguments)) {
                $modelRow->vendor_id = $arguments['vendor_id'] ? (int) $arguments['vendor_id'] : null;
            }
            $modelRow->save();

            $deviceCount = AssetDevice::where('team_id', $teamId)->get(['manufacturer', 'model'])
                ->filter(fn ($d) => AssetDeviceModel::normalizeKey($d->manufacturer, $d->model) === $key)
                ->count();

            return ToolResult::success([
                'id'                  => $modelRow->id,
                'manufacturer'        => $modelRow->manufacturer,
                'model'               => $modelRow->model,
                'monthly_cost'        => $modelRow->monthly_cost !== null ? (float) $modelRow->monthly_cost : null,
                'purchase_price'      => $modelRow->purchase_price !== null ? (float) $modelRow->purchase_price : null,
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
