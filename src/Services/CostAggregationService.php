<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetCompany;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;

class CostAggregationService
{
    /**
     * Gibt Gesamt-Monatskosten zurück (Hardware AfA + MS-Lizenzen + sonstige Kostenpositionen).
     */
    public function totalMonthly(int $teamId): array
    {
        $hardware = AssetItem::where('team_id', $teamId)
            ->get()
            ->sum(fn($i) => $i->monthlyCost());

        $licenses = AssetLicenseSku::where('team_id', $teamId)
            ->get()
            ->sum(fn($s) => $s->monthlyCost());

        // Sonstige wiederkehrende Kostenpositionen (Opex: Mobilfunk, Leasing, Abos, Internet, Drucker …)
        $costLines = AssetCostLine::active()
            ->where('team_id', $teamId)
            ->whereHas('costType', fn($q) => $q->where('aggregation_source', 'cost_line'))
            ->sum('monthly_amount');

        // Geräte-Kosten (Intune-Geräte mit asset_device-Kostenart)
        $devices = (float) $this->deviceCostRows($teamId)->sum('amount');

        return [
            'hardware'  => round($hardware + $devices, 2),
            'licenses'  => round($licenses, 2),
            'costlines' => round((float) $costLines, 2),
            'total'     => round($hardware + $devices + $licenses + (float) $costLines, 2),
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

        // Sonstige Kostenpositionen (cost_line) pro Mitarbeiter
        $costLineSums = AssetCostLine::active()
            ->where('team_id', $teamId)
            ->whereNotNull('assignee_id')
            ->whereHas('costType', fn($q) => $q->where('aggregation_source', 'cost_line'))
            ->get(['assignee_id', 'monthly_amount'])
            ->groupBy('assignee_id')
            ->map(fn($g) => (float) $g->sum('monthly_amount'));

        // Geräte-Kosten pro Mitarbeiter (via UPN) — werden zur Hardware-Summe gezählt
        $deviceSums = $this->deviceCostRows($teamId)
            ->groupBy('upn')
            ->map(fn($g) => (float) $g->sum('amount'));

        $results = $employees->map(function ($emp) use ($items, $licenseAssignments, $skuPrices, $costLineSums, $deviceSums) {
            $hardware = ($items[$emp->id] ?? collect())->sum(fn($i) => $i->monthlyCost())
                + (float) ($deviceSums[$emp->user_principal_name] ?? 0);

            $licenses = ($licenseAssignments[$emp->user_principal_name] ?? collect())
                ->sum(fn($lic) => (float) ($skuPrices[$lic->sku_id] ?? 0));

            $costlines = (float) ($costLineSums[$emp->id] ?? 0);

            return [
                'employee'  => $emp,
                'hardware'  => round($hardware, 2),
                'licenses'  => round($licenses, 2),
                'costlines' => round($costlines, 2),
                'total'     => round($hardware + $licenses + $costlines, 2),
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
                    'label'     => $dept,
                    'hardware'  => round($group->sum('hardware'), 2),
                    'licenses'  => round($group->sum('licenses'), 2),
                    'costlines' => round($group->sum('costlines'), 2),
                    'total'     => round($group->sum('total'), 2),
                    'count'     => $group->count(),
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
                    'label'     => $cc,
                    'hardware'  => round($group->sum('hardware'), 2),
                    'licenses'  => round($group->sum('licenses'), 2),
                    'costlines' => round($group->sum('costlines'), 2),
                    'total'     => round($group->sum('total'), 2),
                    'count'     => $group->count(),
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

    // ─────────────────────────────────────────────────────────────────────────
    //  Kostenaufteilung (Pivot Kostenstelle × Kostenart) — bildet Excel Sheet1/2 ab
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Vereinheitlichte Postenliste (monatlich, EUR) aus DREI Quellen — doppelzählungsfrei
     * gesteuert über cost_type.aggregation_source:
     *   - cost_line     → asset_cost_lines
     *   - hardware_afa  → asset_items.monthlyCost()  (Kostenstelle via Assignee)
     *   - ms_license    → asset_user_licenses × SKU-Preis (Kostenstelle via Assignee)
     *
     * @return Collection<int,array{cost_center_id:?int, cost_type_id:int, amount:float}>
     */
    public function normalizedLines(int $teamId): Collection
    {
        $types = AssetCostType::where('team_id', $teamId)->get()->keyBy('id');
        $rows  = collect();

        // 1) cost_line
        $lineTypeIds = $types->where('aggregation_source', 'cost_line')->keys();
        if ($lineTypeIds->isNotEmpty()) {
            AssetCostLine::active()
                ->where('team_id', $teamId)
                ->whereIn('cost_type_id', $lineTypeIds)
                ->get(['cost_type_id', 'cost_center_id', 'monthly_amount'])
                ->each(fn($l) => $rows->push([
                    'cost_center_id' => $l->cost_center_id,
                    'cost_type_id'   => (int) $l->cost_type_id,
                    'amount'         => (float) $l->monthly_amount,
                ]));
        }

        // Kostenstelle je Mitarbeiter (per id + per UPN)
        $employees    = AssetEmployee::where('team_id', $teamId)->get(['id', 'user_principal_name', 'cost_center_id']);
        $ccById       = $employees->pluck('cost_center_id', 'id');
        $ccByUpn      = $employees->pluck('cost_center_id', 'user_principal_name');

        // 2) hardware_afa
        $afaType = $types->firstWhere('aggregation_source', 'hardware_afa');
        if ($afaType) {
            AssetItem::where('team_id', $teamId)
                ->whereNotNull('assignee_id')
                ->get()
                ->each(function ($i) use ($rows, $afaType, $ccById) {
                    $c = $i->monthlyCost();
                    if ($c <= 0) return;
                    $rows->push([
                        'cost_center_id' => $ccById[$i->assignee_id] ?? null,
                        'cost_type_id'   => (int) $afaType->id,
                        'amount'         => (float) $c,
                    ]);
                });
        }

        // 3) ms_license
        $msType = $types->firstWhere('aggregation_source', 'ms_license');
        if ($msType) {
            $skuPrices = AssetLicenseSku::where('team_id', $teamId)
                ->whereNotNull('unit_price')
                ->pluck('unit_price', 'sku_id');

            if ($skuPrices->isNotEmpty()) {
                AssetUserLicense::where('team_id', $teamId)
                    ->whereIn('sku_id', $skuPrices->keys())
                    ->get(['sku_id', 'user_principal_name'])
                    ->each(function ($lic) use ($rows, $msType, $skuPrices, $ccByUpn) {
                        $p = (float) ($skuPrices[$lic->sku_id] ?? 0);
                        if ($p <= 0) return;
                        $rows->push([
                            'cost_center_id' => $ccByUpn[$lic->user_principal_name] ?? null,
                            'cost_type_id'   => (int) $msType->id,
                            'amount'         => $p,
                        ]);
                    });
            }
        }

        // 4) asset_device — Intune-Geräte, deren (aufgelöste) Kostenart aggregation_source='asset_device' ist
        $this->deviceCostRows($teamId)->each(fn ($r) => $rows->push([
            'cost_center_id' => $r['cost_center_id'],
            'cost_type_id'   => $r['cost_type_id'],
            'amount'         => $r['amount'],
        ]));

        return $rows;
    }

    /**
     * Geräte-Kostenposten (virtuell): pro Intune-Gerät der aufgelöste Monatsbetrag (Override → Modell-Default),
     * die aufgelöste Kostenart (Override → Modell) und die Kostenstelle (Geräte-Override → Mitarbeiter via UPN).
     * Gezählt werden nur Geräte, deren Kostenart aggregation_source='asset_device' hat — das verhindert
     * Doppelzählung (z. B. wenn die alte Laptop-Kostenart von cost_line auf asset_device umgestellt wird,
     * fallen ihre manuellen Importzeilen automatisch aus dem cost_line-Block).
     *
     * @return Collection<int,array{cost_center_id:?int, cost_type_id:int, amount:float, upn:?string}>
     */
    protected function deviceCostRows(int $teamId): Collection
    {
        $deviceTypeIds = AssetCostType::where('team_id', $teamId)
            ->where('aggregation_source', 'asset_device')
            ->pluck('id')
            ->map(fn($id) => (int) $id);

        if ($deviceTypeIds->isEmpty()) {
            return collect();
        }

        $ccByUpn = AssetEmployee::where('team_id', $teamId)->pluck('cost_center_id', 'user_principal_name');

        $models = AssetDeviceModel::where('team_id', $teamId)->get()
            ->keyBy(fn($m) => $this->deviceModelKey($m->manufacturer, $m->model));

        return AssetDevice::where('team_id', $teamId)->get()
            ->map(function ($d) use ($deviceTypeIds, $models, $ccByUpn) {
                $model  = $models[$this->deviceModelKey($d->manufacturer, $d->model)] ?? null;
                $typeId = $d->cost_type_id ?? $model?->cost_type_id;
                if (!$typeId || !$deviceTypeIds->contains((int) $typeId)) {
                    return null;
                }

                $amount = AssetDevice::computeMonthlyFrom($d->monthly_cost, $d->purchase_price, $d->depreciation_months, $d->purchase_date);
                if ($amount === null && $model) {
                    $amount = AssetDevice::computeMonthlyFrom($model->monthly_cost, $model->purchase_price, $model->depreciation_months, null);
                }
                if (!$amount || $amount <= 0) {
                    return null;
                }

                return [
                    'cost_center_id' => $d->cost_center_id ?? ($ccByUpn[$d->user_principal_name] ?? null),
                    'cost_type_id'   => (int) $typeId,
                    'amount'         => (float) $amount,
                    'upn'            => $d->user_principal_name,
                ];
            })
            ->filter()
            ->values();
    }

    /** Map-Key für den (Hersteller, Modell)-Abgleich Gerät ↔ Geräte-Modell (case-/whitespace-tolerant). */
    protected function deviceModelKey(?string $manufacturer, ?string $model): string
    {
        return mb_strtolower(trim((string) $manufacturer)) . '|' . mb_strtolower(trim((string) $model));
    }

    /**
     * Pivot Kostenstelle × Kostenart (Excel Sheet1 = monthly, Sheet2 = quarterly).
     * Blade-fertige Struktur inkl. Gesellschaft-Gruppierung, Summenzeile/-spalte und Metazeilen.
     *
     * @param string $period 'monthly'|'quarterly'
     */
    public function costCenterByType(int $teamId, string $period = 'monthly'): array
    {
        $factor = $period === 'quarterly' ? 3 : 1;

        $types = AssetCostType::where('team_id', $teamId)
            ->orderBy('sort_order')->orderBy('name')->get();

        $companies = AssetCompany::where('team_id', $teamId)
            ->orderBy('sort_order')->orderBy('name')->get();

        $centers = AssetCostCenter::where('team_id', $teamId)
            ->orderBy('company_id')->orderBy('code')->get();

        // Beträge in matrix[cost_center_id][cost_type_id]
        $matrix = [];
        foreach ($this->normalizedLines($teamId) as $line) {
            $cc = $line['cost_center_id'] ?? 0; // 0 = ohne Kostenstelle
            $tt = $line['cost_type_id'];
            $matrix[$cc][$tt] = ($matrix[$cc][$tt] ?? 0) + $line['amount'];
        }

        // Kostenstellen nach Gesellschaft gruppieren
        $centersByCompany = $centers->groupBy('company_id');

        $colTotals  = [];
        $grandTotal = 0.0;

        $buildRow = function (AssetCostCenter $center) use ($types, $matrix, $factor, &$colTotals, &$grandTotal) {
            $cells = [];
            $rowTotal = 0.0;
            foreach ($types as $t) {
                $val = round(($matrix[$center->id][$t->id] ?? 0) * $factor, 2);
                $cells[$t->id] = $val;
                $rowTotal += $val;
                $colTotals[$t->id] = round(($colTotals[$t->id] ?? 0) + $val, 2);
            }
            $grandTotal += $rowTotal;
            return [
                'cost_center_id' => $center->id,
                'code'           => $center->code,
                'name'           => $center->name,
                'cells'          => $cells,
                'rowTotal'       => round($rowTotal, 2),
            ];
        };

        $companyBlocks = [];
        foreach ($companies as $company) {
            $rows = ($centersByCompany[$company->id] ?? collect())->map($buildRow)->values()->all();
            if (empty($rows)) continue;
            $companyBlocks[] = [
                'key'      => $company->key,
                'name'     => $company->name,
                'rows'     => $rows,
                'subtotal' => round(array_sum(array_column($rows, 'rowTotal')), 2),
            ];
        }

        // Kostenstellen ohne Gesellschaft
        $orphanRows = ($centersByCompany[null] ?? collect())->map($buildRow)->values()->all();
        if (!empty($orphanRows)) {
            $companyBlocks[] = [
                'key'      => null,
                'name'     => 'Ohne Gesellschaft',
                'rows'     => $orphanRows,
                'subtotal' => round(array_sum(array_column($orphanRows, 'rowTotal')), 2),
            ];
        }

        // Posten ganz ohne Kostenstelle (matrix[0])
        if (isset($matrix[0])) {
            $cells = [];
            $rowTotal = 0.0;
            foreach ($types as $t) {
                $val = round(($matrix[0][$t->id] ?? 0) * $factor, 2);
                $cells[$t->id] = $val;
                $rowTotal += $val;
                $colTotals[$t->id] = round(($colTotals[$t->id] ?? 0) + $val, 2);
            }
            $grandTotal += $rowTotal;
            $companyBlocks[] = [
                'key'      => '_none',
                'name'     => 'Ohne Kostenstelle',
                'rows'     => [[
                    'cost_center_id' => null,
                    'code'           => '—',
                    'name'           => null,
                    'cells'          => $cells,
                    'rowTotal'       => round($rowTotal, 2),
                ]],
                'subtotal' => round($rowTotal, 2),
            ];
        }

        $meta = [];
        foreach ($types as $t) {
            $meta[$t->id] = [
                'vendor'    => $t->vendorDefault?->name,
                'system'    => $t->system_default,
                'frequency' => $t->frequency_default,
            ];
        }

        return [
            'period'     => $period,
            'types'      => $types->map(fn($t) => ['id' => $t->id, 'key' => $t->key, 'name' => $t->name])->values()->all(),
            'companies'  => $companyBlocks,
            'colTotals'  => $colTotals,
            'grandTotal' => round($grandTotal, 2),
            'meta'       => $meta,
        ];
    }

    /**
     * Monatskosten je Gesellschaft.
     */
    public function byCompany(int $teamId): Collection
    {
        $pivot = $this->costCenterByType($teamId);

        return collect($pivot['companies'])->map(fn($c) => [
            'label' => $c['name'],
            'total' => $c['subtotal'],
            'count' => count($c['rows']),
        ])->values();
    }

    /**
     * Monatskosten je Kostenart (Spaltensummen der Pivot).
     */
    public function byCostType(int $teamId): Collection
    {
        $pivot = $this->costCenterByType($teamId);
        $totals = $pivot['colTotals'];

        return collect($pivot['types'])
            ->map(fn($t) => [
                'label'   => $t['name'],
                'key'     => $t['key'],
                'monthly' => round($totals[$t['id']] ?? 0, 2),
            ])
            ->filter(fn($r) => abs($r['monthly']) > 0.001)
            ->sortByDesc('monthly')
            ->values();
    }

    /**
     * Monatskosten je Kreditor (nur cost_line-Positionen).
     */
    public function byVendor(int $teamId): Collection
    {
        return AssetCostLine::active()
            ->where('team_id', $teamId)
            ->with('vendor')
            ->get(['vendor_id', 'monthly_amount'])
            ->groupBy(fn($l) => $l->vendor?->name ?: 'Ohne Kreditor')
            ->map(fn($g, $name) => [
                'label'   => $name,
                'monthly' => round($g->sum('monthly_amount'), 2),
                'count'   => $g->count(),
            ])
            ->sortByDesc('monthly')
            ->values();
    }

    /**
     * Einzelne Kostenpositionen einer Kostenstelle (Drilldown).
     */
    public function costLinesByCostCenter(int $teamId, int $costCenterId): Collection
    {
        return AssetCostLine::active()
            ->where('team_id', $teamId)
            ->where('cost_center_id', $costCenterId)
            ->with(['costType', 'vendor', 'assignee'])
            ->get();
    }
}
