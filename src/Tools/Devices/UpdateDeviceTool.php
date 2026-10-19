<?php

namespace Platform\AssetManager\Tools\Devices;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
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
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.device.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/device - Aktualisiert EIN Gerät (per id). Optionale Felder: '
            . 'monthly_cost (Leasing-Rate, hat Vorrang) ODER purchase_price + depreciation_months (Kauf/AfA), '
            . 'purchase_date, cost_type_id, cost_center_id (müssen zum Team gehören), user_principal_name '
            . '(nur Nicht-Intune-Geräte), lifecycle_status, warranty_until, lease_until (mit Audit-Eintrag). '
            . 'Zahlenfelder auf null/"" setzen löscht den Override.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id'                  => ['type' => 'integer', 'description' => 'Geräte-ID (erforderlich).'],
                'monthly_cost'        => ['type' => 'number', 'description' => 'Monatliche Leasing-Rate (EUR). Hat Vorrang vor Kauf/AfA.'],
                'purchase_price'      => ['type' => 'number', 'description' => 'Kaufpreis (EUR) für AfA.'],
                'depreciation_months' => ['type' => 'integer', 'description' => 'Abschreibungsdauer in Monaten.'],
                'purchase_date'       => ['type' => 'string', 'description' => 'Kaufdatum YYYY-MM-DD.'],
                'cost_type_id'        => ['type' => 'integer', 'description' => 'Kostenart-ID (Team).'],
                'cost_center_id'      => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'user_principal_name' => ['type' => 'string', 'description' => 'UPN-Neuzuordnung nur für Nicht-Intune-Geräte; "" entfernt.'],
                'lifecycle_status'    => ['type' => 'string', 'enum' => \Platform\AssetManager\Models\AssetDevice::LIFECYCLE_STATUSES, 'description' => 'Lifecycle (in_use|spare|repair|defect|retired|lost); terminal = retired/lost/defect schützt vor dem Reconcile.'],
                'warranty_until'      => ['type' => 'string', 'description' => 'Garantie bis YYYY-MM-DD ("" leert).'],
                'lease_until'         => ['type' => 'string', 'description' => 'Leasing bis YYYY-MM-DD ("" leert).'],
            ], $this->tenantSchemaProperty()),
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
            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            /** @var AssetDevice|null $d */
            $d = AssetDevice::where('team_id', $teamId)->find((int) $arguments['id']);
            if (!$d) {
                return ToolResult::error('NOT_FOUND', 'Gerät nicht gefunden.');
            }

            // Die Zuordnung eines Intune-Geräts kommt aus Intune (#959): der stündliche Sync überschriebe
            // eine hier gesetzte UPN, und der Verlauf zeigte zwei Besitzerwechsel, die es nie gab.
            if (array_key_exists('user_principal_name', $arguments) && $d->intune_id) {
                return ToolResult::error('VALIDATION_ERROR', 'Die Zuordnung dieses Geräts kommt aus Intune und wird beim nächsten Sync überschrieben — bitte in Intune (primärer Benutzer) ändern.');
            }

            $costErrors = AssetWriteService::deviceCostErrors($arguments);
            if ($costErrors !== []) {
                return ToolResult::error('VALIDATION_ERROR', implode(' ', $costErrors));
            }

            $warnings = [];
            if (array_key_exists('cost_type_id', $arguments) && $arguments['cost_type_id']) {
                $type = AssetCostType::where('team_id', $teamId)->find((int) $arguments['cost_type_id']);
                if (!$type) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team. Nutze asset-manager.cost-types.GET.');
                }
                // Laptop-Falle: auf einer Kostenart ohne Quelle asset_device zählt das Gerät still 0 €.
                if ($type->aggregation_source !== AssetCostType::SOURCE_ASSET_DEVICE) {
                    $warnings[] = "Kostenart '{$type->name}' hat aggregation_source='{$type->aggregation_source}' — Gerätekosten zählen nur auf Kostenarten mit Quelle asset_device; dieses Gerät fällt mit 0 € aus der Aufteilung.";
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

            // Lifecycle (#975) über denselben Weg wie die Detailseite — schreibt das Audit-Event mit
            // Urheber (ADR 0007). Nur übergebene Felder ändern sich.
            if (array_intersect_key($arguments, array_flip(['lifecycle_status', 'warranty_until', 'lease_until'])) !== []) {
                $status = $arguments['lifecycle_status'] ?? $d->lifecycle_status;
                if ($status !== null && $status !== '' && ! in_array($status, AssetDevice::LIFECYCLE_STATUSES, true)) {
                    return ToolResult::error('VALIDATION_ERROR', 'lifecycle_status muss einer von ' . implode('|', AssetDevice::LIFECYCLE_STATUSES) . ' sein.');
                }
                foreach (['warranty_until', 'lease_until'] as $f) {
                    if (! empty($arguments[$f]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $arguments[$f])) {
                        return ToolResult::error('VALIDATION_ERROR', "{$f} muss das Format YYYY-MM-DD haben.");
                    }
                }
                app(AssetWriteService::class)->updateDeviceLifecycle($d, [
                    'status'    => $status,
                    'warranty'  => array_key_exists('warranty_until', $arguments) ? $arguments['warranty_until'] : $d->warranty_until?->toDateString(),
                    'lease'     => array_key_exists('lease_until', $arguments) ? $arguments['lease_until'] : $d->lease_until?->toDateString(),
                    'vendor'    => $d->vendor_id,
                    'orderNo'   => $d->order_no,
                    'orderDate' => $d->order_date?->toDateString(),
                    'location'  => $d->location,
                ], $context->user?->id ? (int) $context->user->id : null);
                $d->refresh();
            }

            return ToolResult::success([
                'id'                  => $d->id,
                'device_name'         => $d->device_name,
                'resolved_monthly'    => $d->resolvedMonthlyCost(),
                'user_principal_name' => $d->user_principal_name,
                'cost_type_id'        => $d->cost_type_id,
                'cost_center_id'      => $d->cost_center_id,
                'lifecycle_status'    => $d->lifecycle_status,
                'warranty_until'      => $d->warranty_until?->toDateString(),
                'lease_until'         => $d->lease_until?->toDateString(),
                'warnings'            => $warnings,
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
