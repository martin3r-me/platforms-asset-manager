<?php

namespace Platform\AssetManager\Tools\Sync;

use Platform\AssetManager\Models\AssetImportRun;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Das Import-Protokoll der Kosten-Import-Seite per MCP: Verzeichnis-Abgleich (Entra → Asset-Träger, inkl.
 * Stilllegungen und Umbenennungen), Vodafone-Mobilfunk/-Festnetz, Lizenz-Abrechnung, RingCentral-Liste und
 * Excel-Kostenaufteilung — mit Status, Kennzahlen, Befunden und Fehler.
 *
 * Bisher war der Verzeichnis-Abgleich nur auf der Seite prüfbar; asset-manager.sync.GET kennt nur Geräte- und
 * Lizenz-Sync. Die Rechnungsläufe tragen Beträge — ohne Controlling (ADR 0008) bleibt nur der Abgleich sichtbar.
 */
class ListImportRunsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const MAX_LIMIT = 100;
    private const SOURCES   = [
        AssetImportRun::SOURCE_DIRECTORY, AssetImportRun::SOURCE_VODAFONE, AssetImportRun::SOURCE_VODAFONE_FIXEDNET,
        AssetImportRun::SOURCE_LICENSE_INVOICE, AssetImportRun::SOURCE_TELEPHONY_SEATS, AssetImportRun::SOURCE_EXCEL,
    ];

    public function getName(): string
    {
        return 'asset-manager.import-runs.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/import-runs - Import-Protokoll (neueste zuerst): Verzeichnis-Abgleich (source=directory: '
            . 'Benutzer, stillgelegt, umbenannt, zusammengeführt), Vodafone-Rechnung (vodafone), Vodafone-Festnetz '
            . '(vodafone_fixednet), Lizenz-Abrechnung (license_invoice), RingCentral-Benutzerliste (telephony_seats), '
            . 'Kostenaufteilung (excel). Je Lauf Status (ok|warning|error), Probelauf ja/nein, Kennzahlen, Befunde, '
            . 'Fehler, Dauer, Datei, Benutzer. Filter: source, status, include_dry_runs (Default true), limit (Default 25, '
            . 'max 100). Ohne Controlling nur der Verzeichnis-Abgleich. Geräte-/Lizenz-Sync: asset-manager.sync.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'source'           => ['type' => 'string', 'enum' => self::SOURCES, 'description' => 'Optional: nur diese Quelle.'],
                'status'           => ['type' => 'string', 'enum' => [AssetImportRun::STATUS_OK, AssetImportRun::STATUS_WARNING, AssetImportRun::STATUS_ERROR]],
                'include_dry_runs' => ['type' => 'boolean', 'description' => 'Probeläufe mit ausgeben (Default true).'],
                'limit'            => ['type' => 'integer', 'description' => 'Anzahl (Default 25, max 100).'],
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

            $controlling = app(ControllingContext::class)->enabledFor($teamId, $tenantId);
            $source      = $arguments['source'] ?? null;
            if ($source !== null && ! in_array($source, self::SOURCES, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'source muss einer von ' . implode('|', self::SOURCES) . ' sein.');
            }
            if ($source !== null && $source !== AssetImportRun::SOURCE_DIRECTORY && ! $controlling) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Rechnungs- und Kostenimporte gehören zur Controlling-Schicht — für diesen Tenant deaktiviert.');
            }

            $limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 25)));

            $runs = AssetImportRun::where('team_id', $teamId)
                ->with('user:id,name')
                ->when($source !== null, fn ($q) => $q->where('source', $source))
                ->when(! $controlling, fn ($q) => $q->where('source', AssetImportRun::SOURCE_DIRECTORY))
                ->when(! empty($arguments['status']), fn ($q) => $q->where('status', (string) $arguments['status']))
                ->when(array_key_exists('include_dry_runs', $arguments) && ! $arguments['include_dry_runs'], fn ($q) => $q->where('dry_run', false))
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $rows = $runs->map(fn (AssetImportRun $run) => [
                'id'           => $run->id,
                'source'       => $run->source,
                'source_label' => $run->sourceLabel(),
                'status'       => $run->status,
                'status_label' => $run->statusLabel(),
                'dry_run'      => (bool) $run->dry_run,
                'headline'     => $run->headline,
                'metrics'      => $run->metrics(),
                'findings'     => $run->findings(),
                'error'        => $run->error,
                'file_name'    => $run->file_name,
                'duration_ms'  => $run->duration_ms !== null ? (int) $run->duration_ms : null,
                'user'         => $run->user?->name,
                'created_at'   => $run->created_at?->toIso8601String(),
            ])->values()->all();

            return ToolResult::success([
                'runs'                => $rows,
                'count'               => count($rows),
                'tenant_id'           => $tenantId,
                'controlling_enabled' => $controlling,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Import-Protokolls: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'import', 'sync', 'protocol']];
    }
}
