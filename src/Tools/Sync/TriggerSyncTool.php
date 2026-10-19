<?php

namespace Platform\AssetManager\Tools\Sync;

use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Jobs\SyncLicensesJob;
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
            if (!Gate::forUser($context->user)->allows('asset-manager.manage', [$teamId])) {
                return ToolResult::error('ACCESS_DENIED', 'Sync erfordert die Rolle Owner oder Admin im Team.');
            }

            $dryRun = (bool) ($arguments['dry_run'] ?? false);

            // Nur die Connectoren des AUFGELÖSTEN Tenants (#954). Vorher löste das Tool den Tenant auf,
            // dispatchte dann aber team-weit — ein User mit gewähltem Kunden A synchronisierte alle Kunden.
            // Dieselben Bedingungen wie dispatchForTeam: aktiv, konfiguriert, Admin-Consent bestätigt.
            $connectors = AssetConnectorConfig::where('team_id', $teamId)
                ->where('tenant_id', $tenantId)
                ->where('enabled', true)
                ->get()
                ->filter(fn (AssetConnectorConfig $c) => $c->isConfigured() && $c->isConsentConfirmed())
                ->values();

            if ($connectors->isEmpty()) {
                return ToolResult::error('CONNECTOR_NOT_CONFIGURED', 'Für diesen Tenant gibt es keinen aktiven, konfigurierten Connector mit bestätigtem Admin-Consent.');
            }

            if ($dryRun) {
                return ToolResult::success([
                    'dry_run'        => true,
                    'target'         => $target,
                    'tenant_id'      => $tenantId,
                    'would_dispatch' => $connectors->count(),
                    'message'        => "Vorschau: Sync '{$target}' würde für {$connectors->count()} Connector(en) gestartet. Kein Job dispatcht.",
                ]);
            }

            $job = match ($target) {
                'devices'  => SyncIntuneDevicesJob::class,
                'licenses' => SyncLicensesJob::class,
                'users'    => ImportTenantUsersJob::class,
            };
            foreach ($connectors as $connector) {
                $job::dispatch($connector->id);
            }

            return ToolResult::success([
                'dry_run'    => false,
                'target'     => $target,
                'tenant_id'  => $tenantId,
                'status'     => 'queued',
                'dispatched' => $connectors->count(),
                'message'    => "Sync '{$target}' wurde in die Queue gestellt. Status via asset-manager.sync.GET abfragen.",
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
