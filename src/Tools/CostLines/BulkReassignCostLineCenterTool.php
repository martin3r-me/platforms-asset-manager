<?php

namespace Platform\AssetManager\Tools\CostLines;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Zieht mehrere Kostenpositionen in einem Call auf EINE (bestehende) Kostenstelle um.
 */
class BulkReassignCostLineCenterTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

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
            'properties' => [
                'cost_line_ids'    => ['type' => 'array', 'description' => 'IDs der Kostenpositionen (erforderlich).', 'items' => ['type' => 'integer']],
                'cost_center_id'   => ['type' => 'integer', 'description' => 'Ziel-Kostenstellen-ID (alternativ zu cost_center_code).'],
                'cost_center_code' => ['type' => 'string', 'description' => 'Ziel-Kostenstellen-Code (alternativ zu cost_center_id).'],
                'dry_run'          => ['type' => 'boolean', 'description' => 'Nur Vorschau (Default false).'],
            ],
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

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $ids = array_values(array_filter(array_map('intval', (array) ($arguments['cost_line_ids'] ?? []))));
            if (empty($ids)) {
                return ToolResult::error('VALIDATION_ERROR', 'cost_line_ids[] ist erforderlich.');
            }

            // Ziel-Kostenstelle auflösen (nur bestehende)
            $center = null;
            if (!empty($arguments['cost_center_id'])) {
                $center = AssetCostCenter::where('team_id', $teamId)->find((int) $arguments['cost_center_id']);
            } elseif (!empty($arguments['cost_center_code'])) {
                $center = AssetCostCenter::where('team_id', $teamId)->where('code', trim((string) $arguments['cost_center_code']))->first();
            } else {
                return ToolResult::error('VALIDATION_ERROR', 'cost_center_id ODER cost_center_code erforderlich.');
            }
            if (!$center) {
                return ToolResult::error('COST_CENTER_NOT_FOUND', 'Ziel-Kostenstelle existiert nicht. Nutze asset-manager.cost-centers.GET / .POST.');
            }

            $dryRun = (bool) ($arguments['dry_run'] ?? false);
            $lines  = AssetCostLine::where('team_id', $teamId)->whereIn('id', $ids)->with('costCenter')->get();
            $missing = array_values(array_diff($ids, $lines->pluck('id')->all()));

            $results = [];
            $updated = 0;
            $unchanged = 0;
            foreach ($lines as $l) {
                $from = $l->costCenter?->label;
                if ($l->cost_center_id === $center->id) {
                    $unchanged++;
                    $results[] = ['id' => $l->id, 'label' => $l->label, 'status' => 'unchanged', 'to' => $center->label];
                    continue;
                }
                if (!$dryRun) {
                    $l->cost_center_id = $center->id;
                    $l->save();
                }
                $updated++;
                $results[] = ['id' => $l->id, 'label' => $l->label, 'status' => $dryRun ? 'would_update' : 'updated', 'from' => $from, 'to' => $center->label];
            }

            return ToolResult::success([
                'dry_run'         => $dryRun,
                'target_center'   => $center->label,
                'summary'         => ['updated' => $updated, 'unchanged' => $unchanged, 'missing' => count($missing), 'total' => count($ids)],
                'missing_ids'     => $missing,
                'results'         => $results,
                'message'         => $dryRun
                    ? "Vorschau: {$updated} Positionen würden umgezogen. Kein Schreibvorgang."
                    : "{$updated} Positionen auf '{$center->label}' umgezogen.",
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
