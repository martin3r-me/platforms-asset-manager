<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;

/**
 * Umbuchen von Kostenpositionen auf eine andere Kostenstelle — die **eine** Wahrheit für UI und
 * MCP-Tool.
 *
 * Die Logik lag bis zum Kostenpositionen-Redesign ausschließlich im Tool
 * ({@see \Platform\AssetManager\Tools\CostLines\BulkReassignCostLineCenterTool}), war also nur
 * über den Chat erreichbar — in der Oberfläche musste man 108 Mobilfunk-Zeilen einzeln anfassen.
 * Ein zweiter Satz Regeln im Livewire-Controller wäre die schlechtere Antwort gewesen: welche
 * Ziel-Kostenstellen zulässig sind und was mit unbekannten IDs passiert, sind fachliche
 * Entscheidungen, keine UI-Details.
 *
 * Rückgabeform wie im übrigen Service-Layer: `['ok' => bool, 'message' => string, …]`.
 * `ok = false` heißt **bewusst blockiert** (mit Begründung), nicht „Fehler" — der Aufrufer
 * entscheidet, ob daraus eine Flash-Meldung oder ein Tool-Fehler wird.
 *
 * Team-Scoping passiert hier, der Tenant kommt über den Global Scope
 * ({@see \Platform\AssetManager\Concerns\TenantScopable}) und muss vom Aufrufer gesetzt sein.
 * **Autorisierung ist Aufruferpflicht** (Tool über den ToolContext, Livewire über
 * `Gate::authorize('asset-manager.manage')`) — genau wie beim MasterDataDeletionService.
 */
class CostLineReassignService
{
    /**
     * Mehrere Kostenpositionen auf EINE bestehende Kostenstelle umbuchen.
     *
     * Die Ziel-Kostenstelle wird **nicht** angelegt, wenn sie fehlt: das Umbuchen soll eine
     * bestehende Struktur benutzen, nicht stillschweigend neue Kostenstellen aus Tippfehlern
     * erzeugen. (Deshalb bewusst nicht `CostBootstrapService::resolveCostCenter()`, das anlegt.)
     *
     * Geschrieben wird über Model-Instanzen mit `save()`, nie per Query-Builder-`update()`:
     * `cost_center_id` beeinflusst `monthly_amount` nicht, aber der `saving()`-Hook in
     * {@see AssetCostLine::booted()} ist eine Invariante des Modells, und der Builder-Weg würde
     * zusätzlich `updated_at` und alle Model-Events überspringen. Kein Präzedenzfall dafür.
     *
     * @param  list<int>  $lineIds
     * @return array{
     *     ok: bool,
     *     code: string|null,
     *     reason: string|null,
     *     message: string,
     *     target: string|null,
     *     dry_run: bool,
     *     summary: array{updated: int, unchanged: int, missing: int, total: int},
     *     missing_ids: list<int>,
     *     results: list<array<string, mixed>>
     * }
     */
    public function reassignCostCenter(
        int $teamId,
        array $lineIds,
        ?int $costCenterId = null,
        ?string $costCenterCode = null,
        bool $dryRun = false,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds))));

        if ($ids === []) {
            return $this->fail('VALIDATION_ERROR', 'Keine Kostenpositionen ausgewählt.', $dryRun, 'no_lines');
        }

        $center = $this->resolveCenter($teamId, $costCenterId, $costCenterCode);

        if ($center === null) {
            return $costCenterId === null && ($costCenterCode === null || trim($costCenterCode) === '')
                ? $this->fail('VALIDATION_ERROR', 'Ziel-Kostenstelle fehlt.', $dryRun, 'no_target')
                : $this->fail(
                    'COST_CENTER_NOT_FOUND',
                    'Ziel-Kostenstelle existiert nicht. Sie muss vorher angelegt werden.',
                    $dryRun,
                    'center_not_found',
                );
        }

        $lines   = AssetCostLine::where('team_id', $teamId)->whereIn('id', $ids)->with('costCenter')->get();
        $missing = array_values(array_diff($ids, $lines->pluck('id')->all()));

        $results   = [];
        $updated   = 0;
        $unchanged = 0;

        foreach ($lines as $line) {
            if ($line->cost_center_id === $center->id) {
                $unchanged++;
                $results[] = [
                    'id'     => $line->id,
                    'label'  => $line->label,
                    'status' => 'unchanged',
                    'to'     => $center->label,
                ];
                continue;
            }

            $from = $line->costCenter?->label;

            if (! $dryRun) {
                $line->cost_center_id = $center->id;
                $line->save();
            }

            $updated++;
            $results[] = [
                'id'     => $line->id,
                'label'  => $line->label,
                'status' => $dryRun ? 'would_update' : 'updated',
                'from'   => $from,
                'to'     => $center->label,
            ];
        }

        return [
            'ok'          => true,
            'code'        => null,
            'reason'      => null,
            'message'     => $dryRun
                ? "Vorschau: {$updated} Positionen würden umgezogen. Kein Schreibvorgang."
                : "{$updated} Positionen auf '{$center->label}' umgezogen.",
            'target'      => $center->label,
            'dry_run'     => $dryRun,
            'summary'     => [
                'updated'   => $updated,
                'unchanged' => $unchanged,
                'missing'   => count($missing),
                'total'     => count($ids),
            ],
            'missing_ids' => $missing,
            'results'     => $results,
        ];
    }

    /** Ziel-Kostenstelle über ID oder Code auflösen — nur bestehende, team-gescopet. */
    private function resolveCenter(int $teamId, ?int $id, ?string $code): ?AssetCostCenter
    {
        if ($id !== null && $id > 0) {
            return AssetCostCenter::where('team_id', $teamId)->find($id);
        }

        if ($code !== null && trim($code) !== '') {
            return AssetCostCenter::where('team_id', $teamId)->where('code', trim($code))->first();
        }

        return null;
    }

    /**
     * @return array{ok: bool, code: string, reason: string, message: string, target: null, dry_run: bool,
     *     summary: array{updated: int, unchanged: int, missing: int, total: int},
     *     missing_ids: list<int>, results: list<array<string, mixed>>}
     */
    private function fail(string $code, string $message, bool $dryRun, string $reason): array
    {
        return [
            'ok'          => false,
            'code'        => $code,
            'reason'      => $reason,
            'message'     => $message,
            'target'      => null,
            'dry_run'     => $dryRun,
            'summary'     => ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'total' => 0],
            'missing_ids' => [],
            'results'     => [],
        ];
    }
}
