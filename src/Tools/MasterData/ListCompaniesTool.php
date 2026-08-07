<?php

namespace Platform\AssetManager\Tools\MasterData;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * @deprecated seit docs/adr/0016 — „Gesellschaft" ist keine eigene Entität mehr, sondern ein
 *             **oberster Knoten** des Kostenstellen-Baums. Nutze `asset-manager.cost-centers.GET`
 *             und filtere auf `depth = 0` bzw. `is_root = true`.
 *
 * Das Tool bleibt vorerst bestehen und antwortet aus dem Baum, weil Tool-Namen über den Live-Connector
 * nach außen sichtbar sind: ein hart entferntes Tool bricht bestehende LLM-Aufrufe mit einem
 * „unbekanntes Tool" statt mit einem Hinweis, wo die Information jetzt lebt. Die Antwort trägt
 * `deprecated` und `use_instead`, damit ein Modell den Umstieg selbst findet.
 *
 * Entfernen, sobald der Live-Connector eine Weile keine Aufrufe mehr darauf zeigt.
 */
class ListCompaniesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.companies.GET';
    }

    public function getDescription(): string
    {
        return 'DEPRECATED (nutze asset-manager.cost-centers.GET mit depth=0) - Listet die obersten '
            . 'Knoten des Kostenstellen-Baums eines Tenants, die früher „Gesellschaften" waren '
            . '(id, key=code, name, Anzahl direkt untergeordneter Kostenstellen).';
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

            $rows = $this->inTenant($tenantId, fn () => AssetCostCenter::query()
                ->whereNull('parent_id')
                ->withCount('children')
                ->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (AssetCostCenter $c) => [
                    'id'                 => $c->id,
                    'key'                => $c->code,   // frühere Gesellschafts-Slugs wurden zu Codes
                    'name'               => $c->name ?? $c->code,
                    'cost_centers_count' => $c->children_count,
                ])->values()->all());

            return ToolResult::success([
                'companies'   => $rows,
                'count'       => count($rows),
                'tenant_id'   => $tenantId,
                'deprecated'  => true,
                'use_instead' => 'asset-manager.cost-centers.GET (Knoten mit depth=0 / is_root=true)',
                'note'        => 'Gesellschaften sind seit ADR 0016 die obersten Knoten des '
                    . 'Kostenstellen-Baums. Der frühere Gesellschafts-Key ist jetzt der Kostenstellen-Code.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der obersten Kostenstellen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only'  => true,
            'deprecated' => true,
            'tags'       => ['asset-manager', 'cost-centers', 'master-data', 'deprecated'],
        ];
    }
}
