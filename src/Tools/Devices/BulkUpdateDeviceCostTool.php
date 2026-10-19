<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Services\AssetWriteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Support\DeviceCostResolver;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
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
    /** Obergrenze je Aufruf — ein Sammel-Override über die ganze Flotte gehört in mehrere Schritte. */
    private const MAX_DEVICES = 500;

    use ResolvesTeam;
    use ResolvesTenant;

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
            'properties' => array_merge([
                'device_ids'          => ['type' => 'array', 'description' => 'Geräte-IDs (erforderlich).', 'items' => ['type' => 'integer']],
                'monthly_cost'        => ['type' => 'number', 'description' => 'Monatliche Leasing-Rate (EUR).'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis (EUR) für AfA.'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'Abschreibungsdauer in Monaten.'],
                'purchase_date'       => ['type' => 'string', 'description' => 'Kaufdatum YYYY-MM-DD.'],
                'cost_type_id'        => ['type' => 'integer', 'description' => 'Kostenart-ID (Team).'],
                'cost_center_id'      => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'dry_run'             => ['type' => 'boolean', 'description' => 'Nur Vorschau (Default false).'],
            ], $this->tenantSchemaProperty()),
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

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage', [$teamId])) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $ids = array_values(array_filter(array_map('intval', (array) ($arguments['device_ids'] ?? []))));
            if (empty($ids)) {
                return ToolResult::error('VALIDATION_ERROR', 'device_ids[] ist erforderlich.');
            }
            if (count($ids) > self::MAX_DEVICES) {
                return ToolResult::error('VALIDATION_ERROR', 'Höchstens ' . self::MAX_DEVICES . ' Geräte je Aufruf.');
            }

            // Dieselben Regeln wie die Detailseite (#959): keine negativen Beträge, AfA ab 1 Monat.
            $costErrors = AssetWriteService::deviceCostErrors($arguments);
            if ($costErrors !== []) {
                return ToolResult::error('VALIDATION_ERROR', implode(' ', $costErrors));
            }

            // Anzuwendende Override-Felder einsammeln
            $changes = [];
            foreach (['monthly_cost', 'purchase_price', 'depreciation_months', 'purchase_date'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $v = $arguments[$f];
                    $changes[$f] = ($v === '' || $v === null) ? null : $v;
                }
            }
            $warnings = [];
            if (array_key_exists('cost_type_id', $arguments) && $arguments['cost_type_id']) {
                $type = AssetCostType::where('team_id', $teamId)->find((int) $arguments['cost_type_id']);
                if (!$type) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team.');
                }
                if ($type->aggregation_source !== AssetCostType::SOURCE_ASSET_DEVICE) {
                    $warnings[] = "Kostenart '{$type->name}' hat aggregation_source='{$type->aggregation_source}' — Gerätekosten zählen nur auf Kostenarten mit Quelle asset_device; die Geräte fallen mit 0 € aus der Aufteilung.";
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

            // Katalog + tenant-eigene Modell-Kosten EINMAL laden statt je Gerät (N+1).
            $resolver = DeviceCostResolver::for($teamId);

            $results = [];
            $updated = 0;
            // Alles oder nichts — ein Fehler mitten in der Liste ließ sonst einen Teil geändert zurück.
            DB::beginTransaction();
            foreach ($devices as $d) {
                $before = $resolver->monthlyCost($d);
                foreach ($changes as $k => $v) {
                    $d->{$k} = $v;
                }
                $after = $resolver->monthlyCost($d);
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

            DB::commit();

            return ToolResult::success([
                'dry_run'        => $dryRun,
                'warnings'       => $warnings,
                'summary'        => ['updated' => $updated, 'missing' => count($missing), 'total' => count($ids)],
                'missing_ids'    => $missing,
                'results'        => $results,
                'message'        => $dryRun
                    ? "Vorschau: {$updated} Geräte würden geändert. Kein Schreibvorgang."
                    : "{$updated} Geräte aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Bulk-Update der Gerätekosten: ' . $e->getMessage());
        }
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
