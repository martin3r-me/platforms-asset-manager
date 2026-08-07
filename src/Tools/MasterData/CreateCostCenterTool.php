<?php

namespace Platform\AssetManager\Tools\MasterData;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Legt eine Kostenstelle an (idempotent über Code) und hängt sie optional unter einen Eltern-Knoten.
 *
 * Seit docs/adr/0016 ein Baum: `parent_code`/`parent_id` ersetzen `company_key`/`company_id`. Die alten
 * Parameter werden weiter akzeptiert (Alias) — Tool-Signaturen sind über den Connector nach außen
 * sichtbar, ein hartes Entfernen würde bestehende LLM-Aufrufe brechen.
 */
class CreateCostCenterTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.cost-centers.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/cost-centers - Legt eine Kostenstelle an. Erforderlich: code. '
            . 'Optional: name, parent_code ODER parent_id (übergeordnete Kostenstelle; ohne Angabe wird '
            . 'sie ein oberster Knoten = Gesellschaft), tenant_id, is_active, notes. '
            . 'Existiert der Code im Tenant bereits, wird die bestehende Kostenstelle zurückgegeben '
            . '(idempotent). company_key/company_id werden als Alias von parent_code/parent_id weiter '
            . 'akzeptiert (deprecated).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'code'        => ['type' => 'string', 'description' => 'Kostenstellen-Code (erforderlich), z.B. "2599".'],
                'name'        => ['type' => 'string', 'description' => 'Anzeigename.'],
                'parent_id'   => ['type' => 'integer', 'description' => 'ID der übergeordneten Kostenstelle (gleicher Tenant).'],
                'parent_code' => ['type' => 'string', 'description' => 'Code der übergeordneten Kostenstelle (alternativ zu parent_id).'],
                'company_id'  => ['type' => 'integer', 'description' => 'DEPRECATED — Alias von parent_id.'],
                'company_key' => ['type' => 'string', 'description' => 'DEPRECATED — Alias von parent_code.'],
                'is_active'   => ['type' => 'boolean', 'description' => 'Aktiv-Status (Default true).'],
                'notes'       => ['type' => 'string', 'description' => 'Notiz.'],
            ], $this->tenantSchemaProperty()),
            'required' => ['code'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            [$tenantId, $error] = $this->resolveTenant($arguments, $context, $teamId);
            if ($error) {
                return $error;
            }

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId, $tenantId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für diesen Tenant deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $code = trim((string) ($arguments['code'] ?? ''));
            if ($code === '') {
                return ToolResult::error('VALIDATION_ERROR', 'code ist erforderlich.');
            }

            return $this->inTenant($tenantId, function () use ($arguments, $teamId, $tenantId, $code) {
                [$parentId, $parentError] = $this->resolveParent($arguments, $tenantId);
                if ($parentError) {
                    return $parentError;
                }

                $existing = AssetCostCenter::where('code', $code)->first();
                if ($existing) {
                    return ToolResult::success([
                        'id'      => $existing->id,
                        'code'    => $existing->code,
                        'label'   => $existing->label,
                        'depth'   => (int) $existing->depth,
                        'created' => false,
                        'message' => "Kostenstelle '{$code}' existiert in diesem Tenant bereits.",
                    ]);
                }

                $center = AssetCostCenter::create([
                    'team_id'   => $teamId,
                    'tenant_id' => $tenantId,
                    'code'      => $code,
                    'name'      => $arguments['name'] ?? null,
                    'parent_id' => $parentId,
                    'is_active' => array_key_exists('is_active', $arguments) ? (bool) $arguments['is_active'] : true,
                    'notes'     => $arguments['notes'] ?? null,
                ]);

                $center->recomputeDepth();

                return ToolResult::success([
                    'id'      => $center->id,
                    'code'    => $center->code,
                    'label'   => $center->label,
                    'depth'   => (int) $center->depth,
                    'created' => true,
                    'message' => "Kostenstelle '{$center->label}' angelegt.",
                ]);
            });
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen der Kostenstelle: ' . $e->getMessage());
        }
    }

    /**
     * Eltern-Knoten aus parent_id/parent_code (bzw. den deprecateten company_*-Aliassen) auflösen.
     *
     * @return array{0: int|null, 1: ToolResult|null}
     */
    private function resolveParent(array $arguments, ?int $tenantId): array
    {
        $parentId   = $arguments['parent_id'] ?? $arguments['company_id'] ?? null;
        $parentCode = $arguments['parent_code'] ?? $arguments['company_key'] ?? null;

        if (!empty($parentId)) {
            // Der Global Scope schränkt bereits auf den Tenant ein → ein fremder Knoten ist hier
            // nicht auffindbar und läuft in dieselbe Meldung wie ein nicht existierender.
            if (!AssetCostCenter::whereKey((int) $parentId)->exists()) {
                return [null, ToolResult::error('VALIDATION_ERROR', 'parent_id gehört nicht zu diesem Tenant.')];
            }

            return [(int) $parentId, null];
        }

        if (!empty($parentCode)) {
            $resolved = AssetCostCenter::where('code', trim((string) $parentCode))->value('id');
            if (!$resolved) {
                return [null, ToolResult::error(
                    'VALIDATION_ERROR',
                    'parent_code nicht gefunden. Gültige Codes über asset-manager.cost-centers.GET.',
                )];
            }

            return [(int) $resolved, null];
        }

        return [null, null];
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'tags' => ['asset-manager', 'cost-centers', 'master-data']];
    }
}
