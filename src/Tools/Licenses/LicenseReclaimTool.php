<?php

namespace Platform\AssetManager\Tools\Licenses;

use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\LicenseReclaimService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Lizenz-Reclaim im Chat (#970): aktive Träger mit Lizenz ohne Anmeldung seit N Tagen.
 */
class LicenseReclaimTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.license-reclaim.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/license-reclaim - Aktive Asset-Träger mit bezahlter Microsoft-Lizenz, die sich '
            . 'seit `days` Tagen (Default 90) nicht angemeldet haben: Name, UPN, letzte Anmeldung, Lizenzen und '
            . 'Monatsbetrag (nur mit Controlling). available=false heißt: Entra liefert keine Anmeldedaten '
            . '(AuditLog.Read.All / Entra ID P1 fehlen) — dann ist die Liste leer, nicht „alle genutzt".';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'days' => ['type' => 'integer', 'description' => 'Tage ohne Anmeldung (Default 90, min 14).'],
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

            $days   = max(14, (int) ($arguments['days'] ?? LicenseReclaimService::DEFAULT_DAYS));
            $result = app(LicenseReclaimService::class)->candidates($teamId, $days);

            // Beträge sind kaufmännisch (ADR 0008).
            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                $result['monthly'] = null;
                $result['rows']    = array_map(fn ($r) => array_diff_key($r, ['monthly' => true]), $result['rows']);
            }

            return ToolResult::success(['tenant_id' => $tenantId] + $result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Lizenz-Reclaim: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'licenses', 'reclaim']];
    }
}
