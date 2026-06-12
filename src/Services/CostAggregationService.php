<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;

class CostAggregationService
{
    /**
     * Gibt Gesamt-Monatskosten zurück (Hardware AfA + Lizenzen).
     */
    public function totalMonthly(int $teamId): array
    {
        $hardware = AssetItem::where('team_id', $teamId)
            ->get()
            ->sum(fn($i) => $i->monthlyCost());

        $licenses = AssetLicenseSku::where('team_id', $teamId)
            ->get()
            ->sum(fn($s) => $s->monthlyCost());

        return [
            'hardware' => round($hardware, 2),
            'licenses' => round($licenses, 2),
            'total'    => round($hardware + $licenses, 2),
        ];
    }

    /**
     * Top-N Mitarbeiter nach monatlichen Gesamtkosten (Hardware + Lizenzen).
     * Returns: Collection [['employee' => AssetEmployee, 'hardware' => float, 'licenses' => float, 'total' => float]]
     */
    public function topEmployees(int $teamId, int $limit = 10): Collection
    {
        $employees = AssetEmployee::where('team_id', $teamId)->get();

        // Hardware-Kosten pro Employee (via assignee_id)
        $items = AssetItem::where('team_id', $teamId)
            ->whereNotNull('assignee_id')
            ->get()
            ->groupBy('assignee_id');

        // Lizenz-SKU-Preise lookup (per sku_id)
        $skuPrices = AssetLicenseSku::where('team_id', $teamId)
            ->whereNotNull('unit_price')
            ->pluck('unit_price', 'sku_id');

        // Lizenz-Zuweisungen gruppiert per UPN
        $licenseAssignments = AssetUserLicense::where('team_id', $teamId)
            ->whereIn('sku_id', $skuPrices->keys())
            ->get()
            ->groupBy('user_principal_name');

        $results = $employees->map(function ($emp) use ($items, $licenseAssignments, $skuPrices) {
            $hardware = ($items[$emp->id] ?? collect())->sum(fn($i) => $i->monthlyCost());

            $licenses = ($licenseAssignments[$emp->user_principal_name] ?? collect())
                ->sum(fn($lic) => (float) ($skuPrices[$lic->sku_id] ?? 0));

            return [
                'employee' => $emp,
                'hardware' => round($hardware, 2),
                'licenses' => round($licenses, 2),
                'total'    => round($hardware + $licenses, 2),
            ];
        });

        return $results
            ->filter(fn($r) => $r['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->take($limit);
    }

    /**
     * Kosten pro Department.
     */
    public function byDepartment(int $teamId): Collection
    {
        $rows = $this->topEmployees($teamId, 9999);  // alle relevanten Mitarbeiter

        return $rows
            ->groupBy(fn($r) => $r['employee']->department ?: 'Ohne Abteilung')
            ->map(function ($group, $dept) {
                return [
                    'label'    => $dept,
                    'hardware' => round($group->sum('hardware'), 2),
                    'licenses' => round($group->sum('licenses'), 2),
                    'total'    => round($group->sum('total'), 2),
                    'count'    => $group->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Kosten pro Kostenstelle.
     */
    public function byCostCenter(int $teamId): Collection
    {
        $rows = $this->topEmployees($teamId, 9999);

        return $rows
            ->groupBy(fn($r) => $r['employee']->cost_center ?: 'Ohne Kostenstelle')
            ->map(function ($group, $cc) {
                return [
                    'label'    => $cc,
                    'hardware' => round($group->sum('hardware'), 2),
                    'licenses' => round($group->sum('licenses'), 2),
                    'total'    => round($group->sum('total'), 2),
                    'count'    => $group->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Hardware-Kosten pro Kategorie.
     */
    public function byCategory(int $teamId): Collection
    {
        return AssetItem::with('category')
            ->where('team_id', $teamId)
            ->get()
            ->groupBy(fn($i) => $i->category?->name ?: 'Ohne Kategorie')
            ->map(fn($group, $name) => [
                'label'   => $name,
                'monthly' => round($group->sum(fn($i) => $i->monthlyCost()), 2),
                'count'   => $group->count(),
            ])
            ->sortByDesc('monthly')
            ->values();
    }

    /**
     * Kosten der einzelnen Lizenz-SKUs.
     */
    public function byLicenseSku(int $teamId): Collection
    {
        return AssetLicenseSku::where('team_id', $teamId)
            ->get()
            ->map(fn($sku) => [
                'label'        => $sku->display_name ?? $sku->sku_part_number,
                'monthly'      => round($sku->monthlyCost(), 2),
                'consumed'     => $sku->consumed_units,
                'purchased'    => $sku->purchased_units,
                'unit_price'   => $sku->unit_price !== null ? (float) $sku->unit_price : null,
                'utilization'  => $sku->utilizationPercent(),
            ])
            ->filter(fn($r) => $r['monthly'] > 0)
            ->sortByDesc('monthly')
            ->values();
    }

    /**
     * Anomalien.
     */
    public function anomalies(int $teamId): array
    {
        // Hardware in Pool (status=in_stock) — gebundenes Kapital
        $poolItems = AssetItem::where('team_id', $teamId)
            ->where('status', 'in_stock')
            ->whereNotNull('purchase_price')
            ->get();
        $poolValue = $poolItems->sum(fn($i) => (float) $i->purchase_price);
        $poolCount = $poolItems->count();

        // Ungenutzte Lizenzen
        $unusedSkus = AssetLicenseSku::where('team_id', $teamId)
            ->where('available_units', '>', 0)
            ->whereNotNull('unit_price')
            ->get();
        $unusedSavings = $unusedSkus->sum(fn($s) => (float) $s->unit_price * $s->available_units);

        // Hardware bei inaktiven Mitarbeitern
        $inactiveEmpIds = AssetEmployee::where('team_id', $teamId)
            ->where('is_active', false)
            ->pluck('id');
        $inactiveWithItems = AssetItem::where('team_id', $teamId)
            ->whereIn('assignee_id', $inactiveEmpIds)
            ->count();
        $inactiveValue = AssetItem::where('team_id', $teamId)
            ->whereIn('assignee_id', $inactiveEmpIds)
            ->get()
            ->sum(fn($i) => $i->monthlyCost());

        return [
            'pool' => [
                'count' => $poolCount,
                'value' => round($poolValue, 2),
            ],
            'unused_licenses' => [
                'count'   => $unusedSkus->count(),
                'savings' => round($unusedSavings, 2),
                'units'   => $unusedSkus->sum('available_units'),
            ],
            'inactive_employees' => [
                'count'   => $inactiveWithItems,
                'monthly' => round($inactiveValue, 2),
            ],
        ];
    }
}
