<?php

namespace Platform\AssetManager\Tools\Devices;

use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Geräte-Verlauf über die ganze Flotte (#888, ADR 0007) — bisher nur je Gerät auf der Detailseite lesbar.
 *
 * Beantwortet „welche Geräte haben letzte Woche den Besitzer gewechselt / sind aus Intune verschwunden /
 * wurden ausgemustert, und von wem?" ohne Gerät für Gerät zu öffnen. Read-only, tenant-rein.
 */
class ListDeviceEventsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const MAX_LIMIT = 500;

    public function getName(): string
    {
        return 'asset-manager.device-events.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/device-events - Verlauf aller Geräte des Tenants (neueste zuerst): '
            . 'Besitzerwechsel, Compliance, OS, Neu-Einbindung, Lifecycle, Ausgabe/Rücknahme, Abgang/Wiederkehr '
            . 'aus Intune. Filter: event_type (z. B. owner_changed, compliance_changed, os_changed, reenrolled, '
            . 'lifecycle_changed, issued, returned, handover_deleted, removed, restored, created), device_id, from/to (YYYY-MM-DD), '
            . 'limit (Default 100, max 500). Mit Gerätename, Seriennummer und Urheber (bei manuellen Änderungen).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'event_type' => ['type' => 'string', 'description' => 'Optional: nur dieser Ereignistyp.'],
                'device_id'  => ['type' => 'integer', 'description' => 'Optional: nur dieses Gerät.'],
                'from'       => ['type' => 'string', 'description' => 'Optional: ab Datum YYYY-MM-DD.'],
                'to'         => ['type' => 'string', 'description' => 'Optional: bis Datum YYYY-MM-DD (inklusive).'],
                'limit'      => ['type' => 'integer', 'description' => 'Anzahl (Default 100, max 500).'],
            ], $this->tenantSchemaProperty()),
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

            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            $query = AssetDeviceEvent::query()
                ->where('team_id', $teamId)
                ->with(['device' => fn ($q) => $q->withTrashed(), 'actor'])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if (! empty($arguments['event_type'])) {
                $query->where('event_type', (string) $arguments['event_type']);
            }
            if (! empty($arguments['device_id'])) {
                $query->where('asset_device_id', (int) $arguments['device_id']);
            }
            foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
                if (! empty($arguments[$key])) {
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $arguments[$key])) {
                        return ToolResult::error('VALIDATION_ERROR', "{$key} muss das Format YYYY-MM-DD haben.");
                    }
                    $query->whereDate('created_at', $op, (string) $arguments[$key]);
                }
            }

            $limit  = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 100)));
            $total  = (clone $query)->count();
            $events = $query->limit($limit)->get();

            return ToolResult::success([
                'tenant_id' => $tenantId,
                'total'     => $total,
                'truncated' => $total > $limit,
                'events'    => $events->map(fn (AssetDeviceEvent $e) => [
                    'id'            => $e->id,
                    'at'            => $e->created_at?->toIso8601String(),
                    'event_type'    => $e->event_type,
                    'label'         => $e->eventLabel(),
                    'description'   => $e->description,
                    'old_value'     => $e->old_value,
                    'new_value'     => $e->new_value,
                    'device_id'     => $e->asset_device_id,
                    'device_name'   => $e->device?->device_name,
                    'serial_number' => $e->device?->serial_number,
                    'by'            => $e->actor?->name,
                ])->values()->all(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Geräte-Verlaufs: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'devices', 'history']];
    }
}
