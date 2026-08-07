<?php

namespace Platform\AssetManager\Tools\Sync;

use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Services\AssetDeviceService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
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
    use ResolvesTenant;

    private const TARGETS = ['devices', 'licenses', 'users'];

    public function getName(): string
    {
        return 'asset-manager.sync.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/sync - Stößt einen Sync gegen Microsoft Graph an (asynchron). '
            . 'target: "devices" (Intune-Geräte), "licenses" (M365-Lizenzen) oder "users" (Tenant-User → '
            . 'Asset-Träger). dry_run=true prüft nur Berechtigung und Connector-Bereitschaft. Der Job läuft '
            . 'im Hintergrund — Fortschritt via asset-manager.sync.GET abfragen. Erfordert Owner/Admin.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'target'  => ['type' => 'string', 'enum' => self::TARGETS, 'description' => 'Was synchronisiert wird (erforderlich).'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Nur prüfen, nicht starten (Default false).'],
            ], $this->tenantSchemaProperty()),
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

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

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

            // Fan-out je aktivem Connector des Teams (Multi-Tenant).
            match ($target) {
                'devices'  => app(AssetDeviceService::class)->dispatchSync($teamId),
                'licenses' => SyncLicensesJob::dispatchForTeam($teamId),
                'users'    => ImportTenantUsersJob::dispatchForTeam($teamId),
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
