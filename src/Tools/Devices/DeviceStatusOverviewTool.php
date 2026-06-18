<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Geräte-Lifecycle-Status je Tenant (M4): Stückzahlen je Status (in Betrieb/Reserve/Reparatur/
 * defekt/ausgemustert/verloren/ohne Status) plus eine optional gefilterte Geräteliste. Read-only,
 * strikt team-scoped; mit `tenant_id` tenant-rein, mit `status` auf einen Status eingegrenzt.
 */
class DeviceStatusOverviewTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    /** Listen-Obergrenze (verhindert riesige Antworten; `truncated` meldet, wenn gekürzt wurde). */
    private const MAX_DEVICES = 200;

    public function getName(): string
    {
        return 'asset-manager.device-status.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/device-status - Geräte nach Lifecycle-Status (in_use/spare/repair/'
            . 'defect/retired/lost/none) mit Stückzahlen je Status und einer Geräteliste. Optional je '
            . 'Tenant (tenant_id) und/oder Status (status) gefiltert. Verfügbare Tenants kommen mit.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tenant_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: nur Geräte dieses Tenants. Gültige IDs siehe "tenants" in der Antwort.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => array_merge(AssetDevice::LIFECYCLE_STATUSES, ['none']),
                    'description' => 'Optional: nur Geräte mit diesem Lifecycle-Status. "none" = kein Status gesetzt.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $tenants = AssetTenant::where('team_id', $teamId)
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'is_default']);

            // Tenant-Filter validieren (muss zum Team gehören) — sonst klare Fehlermeldung statt leerem Ergebnis.
            $tenantId = isset($arguments['tenant_id']) ? (int) $arguments['tenant_id'] : null;
            if ($tenantId !== null && ! $tenants->contains('id', $tenantId)) {
                return ToolResult::error('INVALID_TENANT', "Tenant {$tenantId} gehört nicht zu diesem Team.");
            }

            $status = isset($arguments['status']) ? (string) $arguments['status'] : null;
            $validStatuses = array_merge(AssetDevice::LIFECYCLE_STATUSES, ['none']);
            if ($status !== null && ! in_array($status, $validStatuses, true)) {
                return ToolResult::error('INVALID_STATUS', "Unbekannter Status '{$status}'. Erlaubt: " . implode(', ', $validStatuses));
            }

            $base = fn () => AssetDevice::where('team_id', $teamId)->forTenant($tenantId);

            // Stückzahl je Status (immer für die gewählte Tenant-Sicht, unabhängig vom status-Filter).
            $countsByStatus = [];
            foreach (AssetDevice::LIFECYCLE_STATUSES as $s) {
                $countsByStatus[] = [
                    'status' => $s,
                    'label'  => (new AssetDevice(['lifecycle_status' => $s]))->lifecycleLabel(),
                    'count'  => $base()->where('lifecycle_status', $s)->count(),
                ];
            }
            $countsByStatus[] = [
                'status' => 'none',
                'label'  => 'Ohne Status',
                'count'  => $base()->where(fn ($q) => $q->whereNull('lifecycle_status')->orWhere('lifecycle_status', ''))->count(),
            ];

            // Geräteliste nach optionalem Status-Filter.
            $listQuery = $base();
            if ($status === 'none') {
                $listQuery->where(fn ($q) => $q->whereNull('lifecycle_status')->orWhere('lifecycle_status', ''));
            } elseif ($status !== null) {
                $listQuery->where('lifecycle_status', $status);
            }

            $total   = (clone $listQuery)->count();
            $devices = $listQuery->orderBy('device_name')->limit(self::MAX_DEVICES)->get();

            return ToolResult::success([
                'team_id'          => $teamId,
                'tenant_id'        => $tenantId,
                'status_filter'    => $status,
                'tenants'          => $tenants->map(fn ($t) => [
                    'id'         => $t->id,
                    'name'       => $t->name,
                    'is_default' => (bool) $t->is_default,
                ])->all(),
                'total'            => $base()->count(),
                'counts_by_status' => $countsByStatus,
                'devices_total'    => $total,
                'devices_returned' => $devices->count(),
                'truncated'        => $total > $devices->count(),
                'devices'          => $devices->map(fn (AssetDevice $d) => [
                    'id'                  => $d->id,
                    'tenant_id'           => $d->tenant_id,
                    'device_name'         => $d->device_name,
                    'user_principal_name' => $d->user_principal_name,
                    'lifecycle_status'    => $d->lifecycle_status,
                    'lifecycle_label'     => $d->lifecycleLabel(),
                    'last_check_in_at'    => $d->last_check_in_at?->toIso8601String(),
                ])->all(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Geräte-Status: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'devices', 'status', 'lifecycle']];
    }
}
