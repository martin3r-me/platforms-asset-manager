<?php

namespace Platform\AssetManager\Tools\Handovers;

use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetHandoverLine;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Übergabeprotokolle (Geräteausgaben) lesen (#975) — bisher gab es dafür kein Tool.
 *
 * Beantwortet „hat Herr X sein Laptop quittiert?", „welche Ausgaben sind noch offen?" und „welche
 * Protokolle sind nicht unterschrieben?". Die Unterschrift selbst wird nicht ausgeliefert, nur ob und
 * wann unterschrieben wurde. Read-only, tenant-rein.
 */
class ListHandoversTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const MAX_LIMIT = 200;

    public function getName(): string
    {
        return 'asset-manager.handovers.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/handovers - Übergabeprotokolle (Geräteausgaben) mit Positionen: Empfänger, '
            . 'Ausgabedatum, Status (open|partially_returned|returned), unterschrieben ja/nein + Zeitpunkt, je '
            . 'Gerät Zubehör, Rückgabe und Zustand. Filter: holder_id, device_id, status, signed (true/false), '
            . 'limit (Default 50, max 200).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'holder_id' => ['type' => 'integer', 'description' => 'Optional: nur Protokolle dieses Asset-Trägers.'],
                'device_id' => ['type' => 'integer', 'description' => 'Optional: nur Protokolle mit diesem Gerät.'],
                'status'    => ['type' => 'string', 'enum' => array_keys(AssetHandover::STATUS_LABELS), 'description' => 'Optional: Status des Protokolls.'],
                'signed'    => ['type' => 'boolean', 'description' => 'Optional: nur unterschriebene (true) bzw. nicht unterschriebene (false).'],
                'limit'     => ['type' => 'integer', 'description' => 'Anzahl (Default 50, max 200).'],
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

            $query = AssetHandover::query()
                ->where('team_id', $teamId)
                ->with(['holder', 'lines.device'])
                ->orderByDesc('issued_at')
                ->orderByDesc('id');

            if (! empty($arguments['holder_id'])) {
                $query->where('holder_id', (int) $arguments['holder_id']);
            }
            if (! empty($arguments['device_id'])) {
                $query->whereHas('lines', fn ($q) => $q->where('asset_device_id', (int) $arguments['device_id']));
            }
            if (! empty($arguments['status'])) {
                $query->where('status', (string) $arguments['status']);
            }
            if (array_key_exists('signed', $arguments) && $arguments['signed'] !== null) {
                $arguments['signed']
                    ? $query->whereNotNull('signed_at')
                    : $query->whereNull('signed_at');
            }

            $limit     = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 50)));
            $total     = (clone $query)->count();
            $handovers = $query->limit($limit)->get();

            return ToolResult::success([
                'tenant_id' => $tenantId,
                'total'     => $total,
                'truncated' => $total > $limit,
                'handovers' => $handovers->map(fn (AssetHandover $h) => [
                    'id'          => $h->id,
                    'holder_id'   => $h->holder_id,
                    'holder'      => $h->holder?->display_name ?: $h->holder?->user_principal_name,
                    'issued_at'   => $h->issued_at?->toDateString(),
                    'status'      => $h->status,
                    'status_label'=> AssetHandover::STATUS_LABELS[$h->status] ?? $h->status,
                    'signed'      => $h->signed_at !== null,
                    'signed_at'   => $h->signed_at?->toIso8601String(),
                    'signer_name' => $h->signer_name,
                    'notes'       => $h->notes,
                    'lines'       => $h->lines->map(fn (AssetHandoverLine $l) => [
                        'device_id'        => $l->asset_device_id,
                        'device_name'      => $l->deviceName(),
                        'serial_number'    => $l->serialNumber(),
                        'accessories'      => is_array($l->accessories) ? $l->accessories : [],
                        'notes'            => $l->notes,
                        'returned_at'      => $l->returned_at?->toDateString(),
                        'return_condition' => $l->return_condition,
                    ])->values()->all(),
                ])->values()->all(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Übergabeprotokolle: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'handovers', 'devices']];
    }
}
