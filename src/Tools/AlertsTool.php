<?php

namespace Platform\AssetManager\Tools;

use Platform\AssetManager\Services\AlertService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * „Fristen & Warnungen" für den Chat (#973) — dieselbe Liste wie die Dashboard-Kachel.
 *
 * Kein Push (Entscheidung 2026-10-19): wer wissen will, was ansteht, fragt hier — etwa wöchentlich.
 */
class AlertsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.alerts.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/alerts - Fristen & Warnungen des Tenants, nach Dringlichkeit: ablaufende '
            . 'Garantie/Leasing (90 Tage), Kündigungsfenster der Lizenzverträge (90 Tage) und fehlende '
            . 'Kündbar-Daten, unklar ausgegangene Rechnungsläufe, wiederholt fehlschlagende Syncs, veralteter '
            . 'Verzeichnis-Abgleich. Kaufmännische Punkte nur bei aktivem Controlling.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => $this->tenantSchemaProperty(),
            'required'   => [],
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

            $alerts = app(AlertService::class)->collect($teamId, app(ControllingContext::class)->enabledFor($teamId));

            return ToolResult::success([
                'tenant_id' => $tenantId,
                'count'     => count($alerts),
                'alerts'    => array_map(fn ($a) => [
                    'key'      => $a['key'],
                    'severity' => $a['severity'],
                    'title'    => $a['title'],
                    'detail'   => $a['detail'],
                    'url'      => $a['route'] ? route($a['route'], $a['params']) : null,
                ], $alerts),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Warnungen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'alerts', 'overview']];
    }
}
