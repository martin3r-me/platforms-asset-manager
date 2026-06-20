<?php

namespace Platform\AssetManager\Tools\Devices;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Setzt Kosten-Overrides für mehrere ausdrücklich genannte Geräte (device_ids) in einem Call.
 * Bewusst NUR per Geräte-ID (keine Hersteller/Modell-Variante) — Modell-weite Defaults laufen über
 * asset-manager.device-models.PUT, damit es nur einen Weg pro Zweck gibt.
 */
class BulkUpdateDeviceCostTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.devices.cost.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/devices/cost/bulk - Setzt Kosten-Overrides für mehrere Geräte '
            . '(device_ids[]). Anwendbar: monthly_cost (Leasing, Vorrang) ODER purchase_price + '
            . 'depreciation_months (Kauf/AfA), purchase_date, cost_type_id, cost_center_id (Team). '
            . 'dry_run=true liefert nur eine Vorschau (alt → neu) ohne Schreibvorgang. Für Modell-weite '
            . 'Default-Kosten stattdessen asset-manager.device-models.PUT verwenden.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'device_ids'          => ['type' => 'array', 'description' => 'Geräte-IDs (erforderlich).', 'items' => ['type' => 'integer']],
                'monthly_cost'        => ['type' => 'number', 'description' => 'Monatliche Leasing-Rate (EUR).'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis (EUR) für AfA.'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'Abschreibungsdauer in Monaten.'],
                'purchase_date'       => ['type' => 'string', 'description' => 'Kaufdatum YYYY-MM-DD.'],
                'cost_type_id'        => ['type' => 'integer', 'description' => 'Kostenart-ID (Team).'],
                'cost_center_id'      => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'dry_run'             => ['type' => 'boolean', 'description' => 'Nur Vorschau (Default false).'],
            ],
            'required' => ['device_ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $ids = array_values(array_filter(array_map('intval', (array) ($arguments['device_ids'] ?? []))));
            if (empty($ids)) {
                return ToolResult::error('VALIDATION_ERROR', 'device_ids[] ist erforderlich.');
            }

            // Anzuwendende Override-Felder einsammeln
            $changes = [];
            foreach (['monthly_cost', 'purchase_price', 'depreciation_months', 'purchase_date'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $arguments[$f];
                    $changes[$f] = ($v === '' || $v === null) ? null : $v;
                }
            }
            if (array_key_exists('cost_type_id', $arguments) && $arguments['cost_type_id']) {
                if (!AssetCostType::where('team_id', $teamId)->whereKey((int) $arguments['cost_type_id'])->exists()) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team.');
                }
                $changes['cost_type_id'] = (int) $arguments['cost_type_id'];
            }
            if (array_key_exists('cost_center_id', $arguments) && $arguments['cost_center_id']) {
                if (!AssetCostCenter::where('team_id', $teamId)->whereKey((int) $arguments['cost_center_id'])->exists()) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_center_id gehört nicht zum Team.');
                }
                $changes['cost_center_id'] = (int) $arguments['cost_center_id'];
            }

            if (empty($changes)) {
                return ToolResult::error('VALIDATION_ERROR', 'Mindestens ein Override-Feld angeben (monthly_cost/purchase_price/…).');
            }

            $dryRun  = (bool) ($arguments['dry_run'] ?? false);
            $devices = AssetDevice::where('team_id', $teamId)->whereIn('id', $ids)->get();
            $found   = $devices->pluck('id')->all();
            $missing = array_values(array_diff($ids, $found));

            // Modell-Katalog EINMAL laden statt resolvedMonthlyCost() → deviceModel() je Gerät (N+1).
            $modelByKey = [];
            foreach (AssetDeviceModel::where('team_id', $teamId)->get() as $m) {
                $modelByKey[AssetDeviceModel::normalizeKey($m->manufacturer, $m->model)] = $m;
            }

            $results = [];
            $updated = 0;
            foreach ($devices as $d) {
                $before = $this->monthlyFromCatalog($d, $modelByKey);
                foreach ($changes as $k => $v) {
                    $d->{$k} = $v;
                }
                $after = $this->monthlyFromCatalog($d, $modelByKey);
                if (!$dryRun) {
                    $d->save();
                }
                $updated++;
                $results[] = [
                    'id'           => $d->id,
                    'device_name'  => $d->device_name,
                    'monthly_from' => $before,
                    'monthly_to'   => $after,
                    'status'       => $dryRun ? 'would_update' : 'updated',
                ];
            }

            return ToolResult::success([
                'dry_run'        => $dryRun,
                'summary'        => ['updated' => $updated, 'missing' => count($missing), 'total' => count($ids)],
                'missing_ids'    => $missing,
                'results'        => $results,
                'message'        => $dryRun
                    ? "Vorschau: {$updated} Geräte würden geändert. Kein Schreibvorgang."
                    : "{$updated} Geräte aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Bulk-Update der Gerätekosten: ' . $e->getMessage());
        }
    }

    /**
     * Aufgelöste Monatskosten (Override → Modell-Default) gegen den EINMAL vorgeladenen Katalog —
     * identische Logik wie AssetDevice::resolvedMonthlyCost(), aber ohne Per-Gerät-deviceModel()-Query.
     */
    private function monthlyFromCatalog(AssetDevice $d, array $modelByKey): float
    {
        $own = AssetDevice::computeMonthlyFrom($d->monthly_cost, $d->purchase_price, $d->depreciation_months, $d->purchase_date);
        if ($own !== null) {
            return $own;
        }
        $model = $modelByKey[AssetDeviceModel::normalizeKey($d->manufacturer, $d->model)] ?? null;
        if ($model) {
            $fromModel = AssetDevice::computeMonthlyFrom($model->monthly_cost, $model->purchase_price, $model->depreciation_months, null);
            if ($fromModel !== null) {
                return $fromModel;
            }
        }
        return 0.0;
    }

    public function getMetadata(): array
    {
        return [
            'read_only'             => false,
            'risk_level'            => 'write',
            'confirmation_required' => true,
            'tags'                  => ['asset-manager', 'devices', 'cost', 'bulk'],
        ];
    }
}
