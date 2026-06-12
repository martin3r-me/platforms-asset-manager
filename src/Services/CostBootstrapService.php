<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetCompany;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Support\CostBootstrap;

/**
 * Legt die Kostenaufteilungs-Stammdaten (Gesellschaften, Kreditoren, Kostenarten) für ein Team an
 * und backfillt Kostenstellen aus den vorhandenen Employee-Strings. Vollständig idempotent.
 */
class CostBootstrapService
{
    /**
     * Stammdaten für ein Team sicherstellen. Idempotent (firstOrCreate).
     */
    public function seedForTeam(int $teamId): void
    {
        // 1. Gesellschaften
        $sort = 0;
        foreach (CostBootstrap::COMPANIES as $key => $name) {
            AssetCompany::firstOrCreate(
                ['team_id' => $teamId, 'key' => $key],
                ['name' => $name, 'sort_order' => ($sort += 10)]
            );
        }

        // 2. Kreditoren
        foreach (CostBootstrap::VENDORS as $vendorName) {
            AssetVendor::firstOrCreate(['team_id' => $teamId, 'name' => $vendorName]);
        }

        // 3. Kostenarten (mit Vendor-Default-Verknüpfung)
        $vendorIds = AssetVendor::where('team_id', $teamId)->pluck('id', 'name');
        $sort = 0;
        foreach (CostBootstrap::COST_TYPES as $type) {
            AssetCostType::updateOrCreate(
                ['team_id' => $teamId, 'key' => $type['key']],
                [
                    'name'               => $type['name'],
                    'sort_order'         => ($sort += 10),
                    'vendor_default_id'  => $type['vendor'] ? ($vendorIds[$type['vendor']] ?? null) : null,
                    'system_default'     => $type['system'],
                    'frequency_default'  => $type['frequency'],
                    'is_per_employee'    => $type['per_employee'],
                    'aggregation_source' => $type['aggregation_source'],
                    'allow_negative'     => $type['allow_negative'],
                ]
            );
        }
    }

    /**
     * Kostenstellen aus den vorhandenen Employee-Strings (cost_center) ableiten,
     * Gesellschaft zuordnen und employee.cost_center_id setzen.
     *
     * @return array{centers_created:int, employees_linked:int, unmapped:array<string>}
     */
    public function backfillCostCenters(int $teamId): array
    {
        $companyIds = AssetCompany::where('team_id', $teamId)->pluck('id', 'key');

        $codes = AssetEmployee::where('team_id', $teamId)
            ->whereNotNull('cost_center')
            ->where('cost_center', '!=', '')
            ->distinct()
            ->pluck('cost_center');

        $centersCreated = 0;
        $employeesLinked = 0;
        $unmapped = [];

        foreach ($codes as $rawCode) {
            $code = trim((string) $rawCode);
            if ($code === '') {
                continue;
            }

            $companySlug = CostBootstrap::companyForCostCenter($code);
            if ($companySlug === null) {
                $unmapped[] = $code;
            }

            $center = AssetCostCenter::firstOrNew([
                'team_id' => $teamId,
                'code'    => $code,
            ]);
            if (!$center->exists) {
                $center->company_id = $companySlug ? ($companyIds[$companySlug] ?? null) : null;
                $center->save();
                $centersCreated++;
            } elseif ($center->company_id === null && $companySlug) {
                $center->company_id = $companyIds[$companySlug] ?? null;
                $center->save();
            }

            $employeesLinked += AssetEmployee::where('team_id', $teamId)
                ->where('cost_center', $rawCode)
                ->whereNull('cost_center_id')
                ->update(['cost_center_id' => $center->id]);
        }

        return [
            'centers_created'  => $centersCreated,
            'employees_linked' => $employeesLinked,
            'unmapped'         => array_values(array_unique($unmapped)),
        ];
    }

    /**
     * Findet/erstellt eine Kostenstelle anhand des Codes (für Import & manuelle Pflege).
     */
    public function resolveCostCenter(int $teamId, ?string $code): ?AssetCostCenter
    {
        $code = $code !== null ? trim($code) : '';
        if ($code === '') {
            return null;
        }

        $center = AssetCostCenter::firstOrNew(['team_id' => $teamId, 'code' => $code]);
        if (!$center->exists) {
            $slug = CostBootstrap::companyForCostCenter($code);
            if ($slug) {
                $center->company_id = AssetCompany::where('team_id', $teamId)->where('key', $slug)->value('id');
            }
            $center->save();
        }

        return $center;
    }
}
