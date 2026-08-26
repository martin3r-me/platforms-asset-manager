<?php

namespace Platform\AssetManager\Tools\CostLines;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Services\CostLineReassignService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Zieht mehrere Kostenpositionen in einem Call auf EINE (bestehende) Kostenstelle um.
 *
 * Die Fachlogik liegt im {@see CostLineReassignService} - dieselbe, die die Bulk-Umbuchung in
 * der Oberflaeche benutzt. Hier bleiben nur die kanal-eigenen Belange: Team-/Tenant-Kontext,
 * Controlling-Schalter, Rechte und das Antwortformat.
 */
class BulkReassignCostLineCenterTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.cost-lines.cost-center.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/cost-lines/cost-center/bulk - Setzt für mehrere Kostenpositionen '
            . '(cost_line_ids[]) die Kostenstelle. Ziel via cost_center_id ODER cost_center_code '
            . '(muss bereits existieren). dry_run=true liefert nur eine Vorschau (alt → neu).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'cost_line_ids'    => ['type' => 'array', 'description' => 'IDs der Kostenpositionen (erforderlich).', 'items' => ['type' => 'integer']],
                'cost_center_id'   => ['type' => 'integer', 'description' => 'Ziel-Kostenstellen-ID (alternativ zu cost_center_code).'],
                'cost_center_code' => ['type' => 'string', 'description' => 'Ziel-Kostenstellen-Code (alternativ zu cost_center_id).'],
                'dry_run'          => ['type' => 'boolean', 'description' => 'Nur Vorschau (Default false).'],
            ], $this->tenantSchemaProperty()),
            'required' => ['cost_line_ids'],
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

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $result = app(CostLineReassignService::class)->reassignCostCenter(
                $teamId,
                (array) ($arguments['cost_line_ids'] ?? []),
                isset($arguments['cost_center_id']) ? (int) $arguments['cost_center_id'] : null,
                isset($arguments['cost_center_code']) ? (string) $arguments['cost_center_code'] : null,
                (bool) ($arguments['dry_run'] ?? false),
            );

            if (! $result['ok']) {
                // Wortlaut der Tool-Antworten unveraendert: die Meldungen nennen Parameter- und
                // Tool-Namen, die der Service nicht kennen soll.
                $message = match ($result['reason']) {
                    'no_lines'         => 'cost_line_ids[] ist erforderlich.',
                    'no_target'        => 'cost_center_id ODER cost_center_code erforderlich.',
                    'center_not_found' => 'Ziel-Kostenstelle existiert nicht. Nutze asset-manager.cost-centers.GET / .POST.',
                    default            => $result['message'],
                };

                return ToolResult::error($result['code'], $message);
            }

            // Antwortform unveraendert - der Vertrag nach aussen bleibt gleich.
            return ToolResult::success([
                'dry_run'       => $result['dry_run'],
                'target_center' => $result['target'],
                'summary'       => $result['summary'],
                'missing_ids'   => $result['missing_ids'],
                'results'       => $result['results'],
                'message'       => $result['message'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Umziehen der Kostenpositionen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only'             => false,
            'risk_level'            => 'write',
            'confirmation_required' => true,
            'tags'                  => ['asset-manager', 'cost-lines', 'cost-center', 'bulk'],
        ];
    }
}
