<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Support\DeviceCostResolver;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;

/**
 * Listet Intune-Geräte des aktiven Teams inkl. aufgelöster Monatskosten, Kostenquelle
 * (override/model/none) und zugeordnetem Asset-Träger.
 */
class ListDevicesTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesTeam;
    use ResolvesTenant;

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
            . 'limit/offset. Antwort enthält aufgelöste Monatskosten + Kostenquelle + Assignee. Kostenart und '
            . 'Kostenstelle sind ebenfalls aufgelöst (Geräte-Override → Modell-Default bzw. Kostenstelle des '
            . 'Trägers), also identisch zu dem, was die Kostenaufteilung dem Gerät zuordnet.';
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

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            $allowed = ['device_name', 'operating_system', 'os_version', 'compliance_state', 'management_state',
                'manufacturer', 'model', 'serial_number', 'user_principal_name', 'device_type', 'source'];

            $query = AssetDevice::where('team_id', $teamId)->with('assignee');
            $this->applyStandardFilters($query, $arguments, $allowed);
            $this->applyStandardSearch($query, $arguments, ['device_name', 'serial_number', 'model', 'user_principal_name']);
            $this->applyStandardSort($query, $arguments, array_merge($allowed, ['last_check_in_at', 'enrolled_at', 'monthly_cost']), 'device_name', 'asc');

            $result  = $this->applyStandardPaginationResult($query, $arguments);

            // Katalog + tenant-eigene Modell-Kosten EINMAL laden — sonst löst die Kostenauflösung je
            // Gerät den ganzen Katalog neu (N+1). Muster: InventoryService.
            $resolver = DeviceCostResolver::for($teamId);

            // Kostenart und Kostenstelle werden AUFGELÖST ausgegeben, nicht bloß der Geräte-Override:
            // `monthly_cost` löst über den Resolver auf (Override → Modell-Default), und ein Gerät ohne
            // eigenen cost_center_id erbt die Kostenstelle seines Trägers über den UPN — genau so rechnet
            // CostAggregationService::deviceCostRows(). Nur den Override zu melden hieß, ein Gerät mit
            // Modell-Preis als „ohne Kostenart" auszuweisen, obwohl die Auswertung es einer zuordnet.
            $typeNames  = AssetCostType::where('team_id', $teamId)->pluck('name', 'id');
            $ccLabels   = AssetCostCenter::where('team_id', $teamId)->get()->mapWithKeys(
                fn (AssetCostCenter $c) => [$c->id => $c->label],
            );
            $ccIdByUpn  = AssetHolder::where('team_id', $teamId)->pluck('cost_center_id', 'user_principal_name');

            $rows = $result['data']->map(function (AssetDevice $d) use ($resolver, $typeNames, $ccLabels, $ccIdByUpn) {
                $own       = AssetDevice::computeMonthlyFrom($d->monthly_cost, $d->purchase_price, $d->depreciation_months, $d->purchase_date);
                $fromModel = $resolver->modelMonthlyCost($d);
                $source    = $own !== null ? 'override' : ($fromModel !== null ? 'model' : 'none');
                $typeId    = $resolver->costTypeId($d);
                $ccId      = $d->cost_center_id ?? ($ccIdByUpn[$d->user_principal_name] ?? null);

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
                    'monthly_cost'        => round($own ?? $fromModel ?? 0.0, 2),
                    'cost_source'         => $source,
                    'cost_type'           => $typeId !== null ? ($typeNames[$typeId] ?? null) : null,
                    'cost_center'         => $ccId !== null ? ($ccLabels[$ccId] ?? null) : null,
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
