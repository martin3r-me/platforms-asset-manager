<?php

namespace Platform\AssetManager\Tools\Employees;

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Vollständiges 360°-Profil eines Mitarbeiters: Stammdaten, Intune-Geräte, Inventar-Items,
 * Microsoft-Lizenzen, Kostenpositionen, Kostenstelle und Monatskosten-Aufschlüsselung.
 * Beantwortet u.a. "mit welchen Geräten ist X unterwegs" und "was kostet X im Monat".
 */
class GetEmployeeTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.employee.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/employee - Voll-Profil EINES Mitarbeiters per id ODER '
            . 'user_principal_name (UPN). Liefert Stammdaten, alle Intune-Geräte (Modell, Seriennummer, '
            . 'Compliance, last_check_in, aufgelöste Monatskosten), Inventar-Items, Microsoft-Lizenzen, '
            . 'Kostenpositionen, die Kostenstelle und die monatliche Kostenaufschlüsselung '
            . '(hardware/licenses/costlines/total). Genau eines von id oder user_principal_name angeben.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => [
                    'type'        => 'integer',
                    'description' => 'Mitarbeiter-ID (alternativ zu user_principal_name).',
                ],
                'user_principal_name' => [
                    'type'        => 'string',
                    'description' => 'UPN des Mitarbeiters (alternativ zu id), z.B. "max.muster@firma.de".',
                ],
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

            $query = AssetEmployee::where('team_id', $teamId)->with('costCenter.company');
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
                return ToolResult::error('NOT_FOUND', 'Mitarbeiter nicht gefunden. Nutze asset-manager.employees.GET zum Suchen.');
            }

            /** @var CostAggregationService $agg */
            $agg = app(CostAggregationService::class);

            // Geräte (Intune)
            $devices = $emp->devices()->get()->map(fn ($d) => [
                'id'               => $d->id,
                'device_name'      => $d->device_name,
                'manufacturer'     => $d->manufacturer,
                'model'            => $d->model,
                'serial_number'    => $d->serial_number,
                'operating_system' => $d->operating_system,
                'os_version'       => $d->os_version,
                'compliance'       => $d->complianceLabel(),
                'monthly_cost'     => $d->resolvedMonthlyCost(),
                'last_check_in_at' => $d->last_check_in_at?->toIso8601String(),
                'enrolled_at'      => $d->enrolled_at?->toIso8601String(),
            ])->values()->all();

            // Items + Lizenzen je EINMAL laden (mit Eager-Loading) und für Anzeige UND Kostensumme
            // wiederverwenden — vorher 2× items()/licenses() geladen + sku-N+1 je Lizenz.
            $itemModels    = $emp->items()->with('category')->get();
            $licenseModels = $emp->licenses()->with('sku')->get();

            // Inventar-Items (Drucker, Internet, Laptops …)
            $items = $itemModels->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->name,
                'category'     => $i->category?->name,
                'status'       => $i->statusLabel(),
                'serial_number' => $i->serial_number,
                'monthly_cost' => $i->monthlyCost(),
            ])->values()->all();

            // Lizenzen
            $licenses = $licenseModels->map(fn ($l) => [
                'sku_part_number' => $l->sku_part_number,
                'display_name'    => $l->display_name ?? $l->sku?->display_name,
                'unit_price'      => $l->sku?->unit_price !== null ? (float) $l->sku->unit_price : null,
                'assigned_at'     => $l->assigned_at?->toIso8601String(),
            ])->values()->all();

            // Kostenpositionen
            $costLines = $emp->costLines()->active()->validOn(now())->with(['costType', 'vendor'])->get()->map(fn ($c) => [
                'id'             => $c->id,
                'label'          => $c->label,
                'cost_type'      => $c->costType?->name,
                'vendor'         => $c->vendor?->name,
                'amount'         => (float) $c->amount,
                'frequency'      => $c->frequency,
                'monthly_amount' => (float) $c->monthly_amount,
            ])->values()->all();

            // Hardware-/Geräte-/Lizenz-Kosten aus der zentralen Quelle der Wahrheit (employeeCost) statt
            // inline neu zu rechnen — verhindert stilles Divergieren bei Kostenmodell-Änderungen (M8).
            // employeeCost trennt Items (hardware) und Geräte (device); hier wie bisher zu „hardware"
            // zusammengefasst. Die costline-Sicht ist NICHT Teil von employeeCost und wird ergänzt.
            $cost     = $agg->employeeCost($teamId, $emp);
            $hardware = round($cost['hardware'] + $cost['device'], 2);
            $licCost  = $cost['license'];
            $clCost   = (float) AssetCostLine::active()->validOn(now())->where('team_id', $teamId)
                ->where('assignee_id', $emp->id)
                ->whereHas('costType', fn ($q) => $q->where('aggregation_source', AssetCostType::SOURCE_COST_LINE))
                ->sum('monthly_amount');

            return ToolResult::success([
                'employee' => [
                    'id'                  => $emp->id,
                    'name'                => $emp->name,
                    'user_principal_name' => $emp->user_principal_name,
                    'email'               => $emp->email,
                    'department'          => $emp->department,
                    'job_title'           => $emp->job_title,
                    'is_active'           => (bool) $emp->is_active,
                    'account_type'        => $emp->account_type,
                    'cost_center'         => $emp->cost_center,
                    'cost_center_id'      => $emp->cost_center_id,
                    'cost_center_label'   => $emp->costCenter?->label,
                    'company'             => $emp->costCenter?->company?->name,
                ],
                'devices'        => $devices,
                'items'          => $items,
                'licenses'       => $licenses,
                'cost_lines'     => $costLines,
                'monthly_costs'  => [
                    'hardware'  => $hardware,
                    'licenses'  => round($licCost, 2),
                    'costlines' => round($clCost, 2),
                    'total'     => round($hardware + $licCost + $clCost, 2),
                ],
                'currency'       => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Mitarbeiters: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'employee', 'devices', 'costs']];
    }
}
