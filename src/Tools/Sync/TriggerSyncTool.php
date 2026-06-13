<?php

namespace Platform\AssetManager\Tools\Sync;

use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Services\AssetDeviceService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Illuminate\Support\Facades\Gate;

/**
 * Stößt einen Sync gegen Microsoft Graph an (asynchron, als Queue-Job). Erfordert die sync-Berechtigung
 * (Owner/Admin, wie im UI). dry_run prüft nur Berechtigung/Connector-Bereitschaft, ohne zu dispatchen.
 */
class TriggerSyncTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    private const TARGETS = ['devices', 'licenses', 'users'];

    public function getName(): string
    {
        return 'asset-manager.sync.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/sync - Stößt einen Sync gegen Microsoft Graph an (asynchron). '
            . 'target: "devices" (Intune-Geräte), "licenses" (M365-Lizenzen) oder "users" (Tenant-User → '
            . 'Mitarbeiter). dry_run=true prüft nur Berechtigung und Connector-Bereitschaft. Der Job läuft '
            . 'im Hintergrund — Fortschritt via asset-manager.sync.GET abfragen. Erfordert Owner/Admin.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'target'  => ['type' => 'string', 'enum' => self::TARGETS, 'description' => 'Was synchronisiert wird (erforderlich).'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Nur prüfen, nicht starten (Default false).'],
            ],
            'required' => ['target'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $target = (string) ($arguments['target'] ?? '');
            if (!in_array($target, self::TARGETS, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'target muss eines von: ' . implode(', ', self::TARGETS));
            }

            // Berechtigung wie im UI: nur Owner/Admin dürfen synchronisieren
            if (!Gate::forUser($context->user)->allows('sync', AssetDevice::class)) {
                return ToolResult::error('ACCESS_DENIED', 'Sync erfordert die Rolle Owner oder Admin im Team.');
            }

            $status  = app(AssetDeviceService::class)->getConnectorStatus($teamId);
            $dryRun  = (bool) ($arguments['dry_run'] ?? false);

            if (!$status['configured']) {
                return ToolResult::error('CONNECTOR_NOT_CONFIGURED', 'Der Connector ist nicht konfiguriert (Azure-Zugangsdaten fehlen).');
            }

            if ($dryRun) {
                return ToolResult::success([
                    'dry_run'        => true,
                    'target'         => $target,
                    'would_dispatch' => true,
                    'connector'      => ['configured' => $status['configured'], 'enabled' => $status['enabled']],
                    'message'        => "Vorschau: Sync '{$target}' würde gestartet. Kein Job dispatcht.",
                ]);
            }

            match ($target) {
                'devices'  => app(AssetDeviceService::class)->dispatchSync($teamId),
                'licenses' => SyncLicensesJob::dispatch($teamId),
                'users'    => ImportTenantUsersJob::dispatch($teamId),
            };

            return ToolResult::success([
                'dry_run'  => false,
                'target'   => $target,
                'status'   => 'queued',
                'message'  => "Sync '{$target}' wurde in die Queue gestellt. Status via asset-manager.sync.GET abfragen.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Starten des Syncs: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only'             => false,
            'risk_level'            => 'write',
            'confirmation_required' => true,
            'cost_class'            => 'external_api_paid',
            'tags'                  => ['asset-manager', 'sync', 'intune'],
        ];
    }
}
