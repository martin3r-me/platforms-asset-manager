<?php

namespace Platform\AssetManager\Tools\Employees;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;

/**
 * Listet Mitarbeiter des aktiven Teams (Suche/Filter/Sortierung). Liefert nur Counts je
 * Mitarbeiter (Geräte/Lizenzen/Items) — keine Kostensummen (die wären pro Zeile teuer);
 * dafür gibt es asset-manager.employee.GET (360°) bzw. asset-manager.costs.top-employees.GET.
 */
class ListEmployeesTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.employees.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/employees - Listet Mitarbeiter des aktiven Teams. Filterbare/'
            . 'durchsuchbare Felder: display_name, user_principal_name, email, department, cost_center, '
            . 'is_active, account_type, source. Nutze filters (z.B. {"field":"department","op":"eq",'
            . '"value":"IT"}), search, sort, limit/offset. Antwort enthält je Mitarbeiter Counts '
            . '(devices/licenses/items) und die zugeordnete Kostenstelle. Für ein Voll-Profil eines '
            . 'einzelnen Mitarbeiters → asset-manager.employee.GET.';
    }

    public function getSchema(): array
    {
        return $this->getStandardGetSchema();
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $allowed = ['display_name', 'user_principal_name', 'email', 'department', 'cost_center', 'is_active', 'account_type', 'source'];

            $query = AssetEmployee::where('team_id', $teamId)->with('costCenter');
            $this->applyStandardFilters($query, $arguments, $allowed);
            $this->applyStandardSearch($query, $arguments, ['display_name', 'user_principal_name', 'email']);
            $this->applyStandardSort($query, $arguments, $allowed, 'display_name', 'asc');

            $result     = $this->applyStandardPaginationResult($query, $arguments);
            $employees  = $result['data'];
            $upns       = $employees->pluck('user_principal_name')->filter()->values()->all();
            $ids        = $employees->pluck('id')->all();

            $deviceCounts  = $upns ? AssetDevice::where('team_id', $teamId)->whereIn('user_principal_name', $upns)
                ->selectRaw('user_principal_name, COUNT(*) AS c')->groupBy('user_principal_name')->pluck('c', 'user_principal_name') : collect();
            $licenseCounts = $upns ? AssetUserLicense::where('team_id', $teamId)->whereIn('user_principal_name', $upns)
                ->selectRaw('user_principal_name, COUNT(*) AS c')->groupBy('user_principal_name')->pluck('c', 'user_principal_name') : collect();
            $itemCounts    = $ids ? AssetItem::where('team_id', $teamId)->whereIn('assignee_id', $ids)
                ->selectRaw('assignee_id, COUNT(*) AS c')->groupBy('assignee_id')->pluck('c', 'assignee_id') : collect();

            $rows = $employees->map(fn (AssetEmployee $e) => [
                'id'                  => $e->id,
                'name'                => $e->name,
                'user_principal_name' => $e->user_principal_name,
                'email'               => $e->email,
                'department'          => $e->department,
                'job_title'           => $e->job_title,
                'cost_center'         => $e->cost_center,
                'cost_center_id'      => $e->cost_center_id,
                'cost_center_label'   => $e->costCenter?->label,
                'is_active'           => (bool) $e->is_active,
                'account_type'        => $e->account_type,
                'source'              => $e->source,
                'counts'              => [
                    'devices'  => (int) ($deviceCounts[$e->user_principal_name] ?? 0),
                    'licenses' => (int) ($licenseCounts[$e->user_principal_name] ?? 0),
                    'items'    => (int) ($itemCounts[$e->id] ?? 0),
                ],
            ])->values()->all();

            return ToolResult::success([
                'employees'  => $rows,
                'pagination' => $result['pagination'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Mitarbeiter: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'employees']];
    }
}
