<?php

namespace Platform\AssetManager\Tools\Holders;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\HolderTypeService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Träger-Typ für viele Asset-Träger in einem Call setzen (ADR 0017).
 *
 * Bewusst dünn: die gesamte Fachlogik liegt im {@see HolderTypeService}, den sich dieses Tool mit
 * der Trägerliste und der Detailseite teilt. Am Typ hängt die Lizenz-Verteilungskaskade (ADR 0019) —
 * ein zweiter Satz Regeln hier wäre genau der Fehler, den es im Einzel-Tool schon einmal gab.
 *
 * `dry_run=true` liefert eine Vorschau samt Nebenwirkungen: wie oft ein Funktions-System gelöscht
 * würde, wie viele Funktionskonten ohne System blieben, und bei wie vielen der nächste Sync die
 * Änderung wieder zurückstellt.
 */
class BulkSetHolderTypeTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.holders.type.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/holders/type/bulk - Setzt den Träger-Typ (ADR 0017) für viele '
            . 'Asset-Träger auf einmal. Adressierung über holder_ids[] und/oder upns[]. '
            . 'holder_type: person|function|admin|service|external. dry_run=true liefert nur eine '
            . 'Vorschau samt Nebenwirkungen (clears_system: zugehöriges System geht verloren; '
            . 'needs_system: Funktionskonto ohne System, nach ADR 0019 nicht bewirtschaftbar; '
            . 'reverts_on_sync: der nächste Sync stellt den Typ wieder zurück, weil eine '
            . 'Erkennungsregel greift). Ein Wechsel von oder zu function löst die Neuverteilung der '
            . 'Lizenzmengen aus — genau einmal, am Ende.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'holder_ids'  => ['type' => 'array', 'description' => 'Asset-Träger-IDs.', 'items' => ['type' => 'integer']],
                'upns'        => ['type' => 'array', 'description' => 'User Principal Names (alternativ oder zusätzlich zu holder_ids).', 'items' => ['type' => 'string']],
                'holder_type' => ['type' => 'string', 'enum' => AssetHolder::TYPES, 'description' => 'Ziel-Typ für alle genannten Träger.'],
                'dry_run'     => ['type' => 'boolean', 'description' => 'Nur Vorschau, nicht schreiben (Default false).'],
            ], $this->tenantSchemaProperty()),
            'required' => ['holder_type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016) vor jeder Query setzen.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Schreibrechte (ADR 0004): dieselbe Grenze wie im UI, unabhängig vom Kanal.
            if (! Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $ids = array_map('intval', $arguments['holder_ids'] ?? []);

            // UPNs auf IDs auflösen — team- und tenant-gebunden, damit ein fremder Name nicht
            // versehentlich trifft. Unauffindbare UPNs fallen still heraus und tauchen als
            // Differenz in `summary.total` gegen die Eingabe auf.
            if (! empty($arguments['upns'])) {
                $ids = array_merge($ids, AssetHolder::where('team_id', $teamId)
                    ->whereIn('user_principal_name', (array) $arguments['upns'])
                    ->pluck('id')
                    ->all());
            }

            $result = app(HolderTypeService::class)->setType(
                $teamId,
                $tenantId,
                $ids,
                (string) $arguments['holder_type'],
                (bool) ($arguments['dry_run'] ?? false),
            );

            if (! $result['ok']) {
                return ToolResult::error($result['code'] ?? 'VALIDATION_ERROR', $result['message']);
            }

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category'              => 'mutation',
            'tags'                  => ['asset-manager', 'holders', 'holder-type', 'bulk'],
            'read_only'             => false,
            'requires_auth'         => true,
            'risk_level'            => 'write',
            'confirmation_required' => true,
        ];
    }
}
