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
 * Einzel-Update eines Mitarbeiters (Kostenstelle, Abteilung, Aktiv-Status, Jobtitel, Kontotyp).
 * Für Massenänderungen der Kostenstelle → asset-manager.employees.cost-center.bulk.PUT.
 */
class UpdateEmployeeTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.employee.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/employee - Aktualisiert EINEN Mitarbeiter (per id ODER '
            . 'user_principal_name). Optionale Felder: cost_center (Kostenstellen-Code; setzt Code + '
            . 'cost_center_id konsistent; "" leert die Zuordnung), department, is_active (boolean), '
            . 'job_title, account_type ("function" für Funktionskonten). Unbekannter Kostenstellen-Code '
            . 'wird nur mit create_missing=true angelegt, sonst Fehler.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'                  => ['type' => 'integer', 'description' => 'Mitarbeiter-ID (alternativ zu user_principal_name).'],
                'user_principal_name' => ['type' => 'string', 'description' => 'UPN (alternativ zu id).'],
                'cost_center'         => ['type' => 'string', 'description' => 'Kostenstellen-Code (z.B. "2599"). "" zum Leeren.'],
                'department'          => ['type' => 'string', 'description' => 'Abteilung.'],
                'is_active'           => ['type' => 'boolean', 'description' => 'Aktiv-Status.'],
                'job_title'           => ['type' => 'string', 'description' => 'Jobtitel.'],
                'account_type'        => ['type' => 'string', 'description' => 'Kontotyp, z.B. "function".'],
                'create_missing'      => ['type' => 'boolean', 'description' => 'Wenn true: fehlende Kostenstelle anlegen (Default false).'],
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

            $query = AssetEmployee::where('team_id', $teamId);
            if (!empty($arguments['id'])) {
                $query->where('id', (int) $arguments['id']);
            } elseif (!empty($arguments['user_principal_name'])) {
                $query->where('user_principal_name', (string) $arguments['user_principal_name']);
            } else {
                return ToolResult::error('VALIDATION_ERROR', 'Bitte id ODER user_principal_name angeben.');
            }

            /** @var AssetEmployee|null $emp */
            $emp = $query->first();
            if (!$emp) {
                return ToolResult::error('NOT_FOUND', 'Mitarbeiter nicht gefunden.');
            }

            // Kostenstelle (setzt cost_center-Code UND cost_center_id konsistent)
            if (array_key_exists('cost_center', $arguments)) {
                $code = trim((string) $arguments['cost_center']);
                if ($code === '') {
                    $emp->cost_center    = null;
                    $emp->cost_center_id = null;
                } else {
                    $center = AssetCostCenter::where('team_id', $teamId)->where('code', $code)->first();
                    if (!$center) {
                        if (!empty($arguments['create_missing'])) {
                            $center = app(CostBootstrapService::class)->resolveCostCenter($teamId, $code);
                        } else {
                            return ToolResult::error('COST_CENTER_NOT_FOUND', "Kostenstelle '{$code}' existiert nicht. create_missing=true zum Anlegen, oder asset-manager.cost-centers.GET für gültige Codes.");
                        }
                    }
                    $emp->cost_center    = $center->code;
                    $emp->cost_center_id = $center->id;
                }
            }

            foreach (['department', 'job_title', 'account_type'] as $field) {
                if (array_key_exists($field, $arguments)) {
                    $emp->{$field} = $arguments[$field] !== '' ? $arguments[$field] : null;
                }
            }
            if (array_key_exists('is_active', $arguments)) {
                $emp->is_active = (bool) $arguments['is_active'];
            }

            $emp->save();
            $emp->load('costCenter');

            return ToolResult::success([
                'id'                => $emp->id,
                'name'              => $emp->name,
                'cost_center'       => $emp->cost_center,
                'cost_center_id'    => $emp->cost_center_id,
                'cost_center_label' => $emp->costCenter?->label,
                'department'        => $emp->department,
                'is_active'         => (bool) $emp->is_active,
                'job_title'         => $emp->job_title,
                'account_type'      => $emp->account_type,
                'message'           => "Mitarbeiter '{$emp->name}' aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Mitarbeiters: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'employee']];
    }
}
