<?php

namespace Platform\AssetManager\Tools\Employees;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Services\CostBootstrapService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Bulk-Umzug der Kostenstelle für viele Mitarbeiter in einem Call.
 *
 * Setzt IMMER beide Felder konsistent — den String `cost_center` (Code) UND die FK `cost_center_id`.
 * Das ist Pflicht: der MA-Kostenreport gruppiert über den Code-String, der Kostenstelle×Kostenart-Pivot
 * über die FK. Würde nur eins gesetzt, desynchronisieren die Auswertungen.
 *
 * Sicherheits-Defaults: unbekannte Kostenstellen werden NICHT angelegt (create_missing=false), und mit
 * dry_run=true wird nur eine Vorschau erzeugt, ohne zu schreiben.
 */
class BulkAssignCostCenterTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.employees.cost-center.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/employees/cost-center/bulk - Weist vielen Mitarbeitern eine '
            . 'Kostenstelle zu. Zwei Modi: (1) cost_center_code + employee_ids[] und/oder upns[] '
            . '(gleiche Kostenstelle für alle); (2) assignments[] mit {employee_id|upn, cost_center_code} '
            . '(individuell). Setzt Code + cost_center_id konsistent. dry_run=true liefert nur eine '
            . 'Vorschau (kein Schreibvorgang). create_missing=true legt fehlende Kostenstellen an, '
            . 'sonst werden unbekannte Codes pro Zeile als Fehler gemeldet.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'cost_center_code' => ['type' => 'string', 'description' => 'Modus 1: Kostenstellen-Code für alle genannten Mitarbeiter (z.B. "2599").'],
                'employee_ids'     => ['type' => 'array', 'description' => 'Modus 1: Mitarbeiter-IDs.', 'items' => ['type' => 'integer']],
                'upns'             => ['type' => 'array', 'description' => 'Modus 1: User Principal Names.', 'items' => ['type' => 'string']],
                'assignments'      => [
                    'type'        => 'array',
                    'description' => 'Modus 2: individuelle Zuweisungen.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'employee_id'      => ['type' => 'integer', 'description' => 'Mitarbeiter-ID (alternativ zu upn).'],
                            'upn'              => ['type' => 'string', 'description' => 'UPN (alternativ zu employee_id).'],
                            'cost_center_code' => ['type' => 'string', 'description' => 'Kostenstellen-Code für diesen Mitarbeiter.'],
                        ],
                        'required' => ['cost_center_code'],
                    ],
                ],
                'create_missing' => ['type' => 'boolean', 'description' => 'Fehlende Kostenstellen anlegen (Default false).'],
                'dry_run'        => ['type' => 'boolean', 'description' => 'Nur Vorschau, nicht schreiben (Default false).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $createMissing = (bool) ($arguments['create_missing'] ?? false);
            $dryRun        = (bool) ($arguments['dry_run'] ?? false);

            // Arbeitsliste normalisieren: jede Zeile = ['id'|'upn', 'code']
            $work = [];
            if (!empty($arguments['assignments']) && is_array($arguments['assignments'])) {
                foreach ($arguments['assignments'] as $a) {
                    $code = trim((string) ($a['cost_center_code'] ?? ''));
                    $work[] = ['id' => $a['employee_id'] ?? null, 'upn' => $a['upn'] ?? null, 'code' => $code];
                }
            } else {
                $code = trim((string) ($arguments['cost_center_code'] ?? ''));
                if ($code === '') {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_center_code + employee_ids/upns ODER assignments[] erforderlich.');
                }
                foreach (($arguments['employee_ids'] ?? []) as $id) {
                    $work[] = ['id' => (int) $id, 'upn' => null, 'code' => $code];
                }
                foreach (($arguments['upns'] ?? []) as $upn) {
                    $work[] = ['id' => null, 'upn' => (string) $upn, 'code' => $code];
                }
            }

            if (empty($work)) {
                return ToolResult::error('VALIDATION_ERROR', 'Keine Mitarbeiter angegeben.');
            }

            // Mitarbeiter vorab laden (zwei Queries statt N)
            $ids  = array_values(array_filter(array_column($work, 'id')));
            $upns = array_values(array_filter(array_column($work, 'upn')));
            $byId  = $ids ? AssetEmployee::where('team_id', $teamId)->whereIn('id', $ids)->get()->keyBy('id') : collect();
            $byUpn = $upns ? AssetEmployee::where('team_id', $teamId)->whereIn('user_principal_name', $upns)->get()->keyBy('user_principal_name') : collect();

            $centerCache  = []; // code => AssetCostCenter|false(=nicht gefunden)
            $createdCodes = [];

            $resolveCenter = function (string $code) use ($teamId, $createMissing, $dryRun, &$centerCache, &$createdCodes) {
                if (array_key_exists($code, $centerCache)) {
                    return $centerCache[$code];
                }
                $center = AssetCostCenter::where('team_id', $teamId)->where('code', $code)->first();
                if (!$center && $createMissing) {
                    if ($dryRun) {
                        // Vorschau: nur vormerken, nicht anlegen
                        $createdCodes[$code] = true;
                        return $centerCache[$code] = 'WOULD_CREATE';
                    }
                    $center = app(CostBootstrapService::class)->resolveCostCenter($teamId, $code);
                    if ($center) {
                        $createdCodes[$code] = true;
                    }
                }
                return $centerCache[$code] = ($center ?: false);
            };

            $results = [];
            $updated = 0;
            $unchanged = 0;
            $skipped = 0;
            $errors = 0;
            $touched = []; // verhindert Doppelschreiben bei doppelter Nennung

            foreach ($work as $w) {
                $code = $w['code'];
                $emp  = $w['id'] !== null ? ($byId[$w['id']] ?? null) : ($w['upn'] !== null ? ($byUpn[$w['upn']] ?? null) : null);
                $ref  = $w['id'] !== null ? "id={$w['id']}" : "upn={$w['upn']}";

                if (!$emp) {
                    $errors++;
                    $results[] = ['ref' => $ref, 'status' => 'not_found', 'message' => 'Mitarbeiter nicht im Team gefunden.'];
                    continue;
                }
                if ($code === '') {
                    $errors++;
                    $results[] = ['ref' => $ref, 'employee' => $emp->name, 'status' => 'error', 'message' => 'Leerer cost_center_code.'];
                    continue;
                }

                $center = $resolveCenter($code);
                if ($center === false) {
                    $errors++;
                    $results[] = ['ref' => $ref, 'employee' => $emp->name, 'status' => 'error', 'message' => "Kostenstelle '{$code}' existiert nicht (create_missing=false)."];
                    continue;
                }

                $from   = $emp->cost_center;
                $wouldCreate = $center === 'WOULD_CREATE';
                $toLabel = $wouldCreate ? "{$code} (neu)" : ($center->label ?? $code);

                if (!$wouldCreate && $emp->cost_center_id === $center->id && (string) $emp->cost_center === (string) $center->code) {
                    $unchanged++;
                    $results[] = ['ref' => $ref, 'employee' => $emp->name, 'status' => 'unchanged', 'from' => $from, 'to' => $toLabel];
                    continue;
                }

                if ($dryRun) {
                    $results[] = ['ref' => $ref, 'employee' => $emp->name, 'status' => 'would_update', 'from' => $from, 'to' => $toLabel];
                    $updated++;
                    continue;
                }

                if (isset($touched[$emp->id])) {
                    $skipped++;
                    $results[] = ['ref' => $ref, 'employee' => $emp->name, 'status' => 'skipped', 'message' => 'Mitarbeiter mehrfach genannt — nur erste Zuweisung angewendet.'];
                    continue;
                }

                $emp->cost_center    = $center->code;
                $emp->cost_center_id = $center->id;
                $emp->save();
                $touched[$emp->id] = true;
                $updated++;
                $results[] = ['ref' => $ref, 'employee' => $emp->name, 'status' => 'updated', 'from' => $from, 'to' => $center->label ?? $code];
            }

            return ToolResult::success([
                'dry_run'          => $dryRun,
                'summary'          => [
                    'updated'   => $updated,
                    'unchanged' => $unchanged,
                    'skipped'   => $skipped,
                    'errors'    => $errors,
                    'total'     => count($work),
                ],
                'created_centers'  => array_keys($createdCodes),
                'results'          => $results,
                'message'          => $dryRun
                    ? "Vorschau: {$updated} Mitarbeiter würden geändert, {$errors} Fehler. Kein Schreibvorgang."
                    : "{$updated} Mitarbeiter aktualisiert, {$errors} Fehler.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Bulk-Update der Kostenstellen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only'             => false,
            'risk_level'            => 'write',
            'confirmation_required' => true,
            'tags'                  => ['asset-manager', 'employees', 'cost-center', 'bulk'],
        ];
    }
}
