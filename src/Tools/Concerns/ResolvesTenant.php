<?php

namespace Platform\AssetManager\Tools\Concerns;

use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\TenantContext;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Einheitliche Tenant-Auflösung für alle Asset-Manager-Tools (siehe docs/adr/0016).
 *
 * Bis ADR 0016 antworteten die Tools **team-weit** — `asset-manager.user-licenses.GET` lieferte also
 * den kompletten Auszug über alle Kunden hinweg. Mit dem Tenant als Zugriffsgrenze ist das eine
 * Grenzverletzung, und über den Live-Connector eine, die nach außen wirkt.
 *
 * **Nur additiv**: `tenant_id` ist optional, Default ist die gespeicherte Auswahl des Users. Bestehende
 * LLM-Aufrufe ohne den Parameter funktionieren unverändert — sie sehen nur nicht mehr fremde Tenants.
 * Tool-Namen und Signaturen sind über den Connector nach außen sichtbar; ein neuer Pflichtparameter
 * würde bestehende Aufrufe brechen.
 *
 * Zwei Ausbaustufen:
 *  - {@see tenantSchemaProperty()} für Tools, die genau einen Tenant beantworten,
 *  - {@see tenantSchemaPropertyWithAll()} für Auswertungs-Tools, die zusätzlich `"all"` erlauben.
 *    Dort MUSS das Ergebnis je Zeile den Tenant ausweisen, sonst sind die Zahlen nicht zuordenbar.
 */
trait ResolvesTenant
{
    /** Sentinel für „über alle Tenants" — nur bei Auswertungs-Tools zulässig. */
    protected const TENANT_ALL = 'all';

    /** Schema-Baustein für Tools, die genau einen Tenant beantworten. */
    protected function tenantSchemaProperty(): array
    {
        return [
            'tenant_id' => [
                'type'        => 'integer',
                'description' => 'Optional: Tenant (Kundenkontext), dessen Daten geliefert werden. '
                    . 'Default ist der aktuell gewählte Tenant. IDs über asset-manager.overview.GET.',
            ],
        ];
    }

    /** Schema-Baustein für Auswertungs-Tools, die zusätzlich "all" erlauben. */
    protected function tenantSchemaPropertyWithAll(): array
    {
        return [
            'tenant_id' => [
                'description' => 'Optional: Tenant (Kundenkontext) als ID, oder "all" für alle Tenants '
                    . 'des Teams (nur bei Auswertungen; das Ergebnis weist den Tenant je Zeile aus). '
                    . 'Default ist der aktuell gewählte Tenant.',
                'oneOf' => [
                    ['type' => 'integer'],
                    ['type' => 'string', 'enum' => [self::TENANT_ALL]],
                ],
            ],
        ];
    }

    /**
     * Tenant für diesen Aufruf auflösen.
     *
     * @return array{0: int|null, 1: ToolResult|null} [$tenantId, $error] — $tenantId null = alle Tenants.
     */
    protected function resolveTenant(array $arguments, ToolContext $context, int $teamId, bool $allowAll = false): array
    {
        $raw = $arguments['tenant_id'] ?? null;

        if ($raw === null || $raw === '') {
            // Kein Parameter: gespeicherte Auswahl des Users. Ohne User-Kontext (Service-Token) der
            // Default-Tenant des Teams — nie „alle", das wäre eine stille Grenzöffnung.
            $userId = $context->user?->getAuthIdentifier();

            $tenantId = $userId !== null
                ? TenantContext::current($teamId, (int) $userId)
                : null;

            return [$tenantId ?? TenantContext::defaultTenantId($teamId), null];
        }

        if (is_string($raw) && strtolower($raw) === self::TENANT_ALL) {
            if (! $allowAll) {
                return [null, ToolResult::error(
                    'VALIDATION_ERROR',
                    'tenant_id="all" ist nur bei Auswertungs-Tools zulässig (Übersicht, Kosten). '
                    . 'Für Listen und Detailabfragen einen konkreten Tenant angeben.',
                )];
            }

            return [null, null];
        }

        $tenantId = (int) $raw;

        if (! AssetTenant::query()->whereKey($tenantId)->where('team_id', $teamId)->exists()) {
            // Nicht „nicht gefunden" von „nicht berechtigt" unterscheiden — das würde die Existenz
            // fremder Tenants preisgeben (dieselbe Begründung wie 404 statt 403 im UI).
            return [null, ToolResult::error('NOT_FOUND', 'tenant_id nicht gefunden.')];
        }

        return [$tenantId, null];
    }

    /**
     * Callback im Tenant-Kontext ausführen — der Global Scope filtert dann jede Query darin.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inTenant(?int $tenantId, callable $callback): mixed
    {
        return TenantContext::runFor($tenantId, $callback);
    }

    /**
     * Tenant-Namen je ID — für Auswertungen mit `tenant_id="all"`, die den Tenant je Zeile ausweisen.
     *
     * @return array<int, string>
     */
    protected function tenantNames(int $teamId): array
    {
        return AssetTenant::query()
            ->where('team_id', $teamId)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
