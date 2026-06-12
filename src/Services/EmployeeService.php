<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetUserLicense;

class EmployeeService
{
    /**
     * Findet einen Employee anhand UPN oder legt ihn an (source=derived).
     * Aktualisiert display_name nur, wenn der bestehende leer ist.
     */
    public function findOrCreateByUpn(
        int $teamId,
        string $upn,
        ?string $displayName = null,
        string $source = 'derived'
    ): AssetEmployee {
        $employee = AssetEmployee::firstOrNew([
            'team_id'             => $teamId,
            'user_principal_name' => $upn,
        ]);

        if (!$employee->exists) {
            $employee->source       = $source;
            $employee->display_name = $displayName;
            $employee->email        = $upn;
            $employee->is_active    = true;
            $employee->save();
        } elseif ($displayName && empty($employee->display_name)) {
            $employee->display_name = $displayName;
            $employee->save();
        }

        return $employee;
    }

    /**
     * Backfill: alle UPNs aus asset_devices + asset_user_licenses einsammeln und Employees anlegen.
     * Returnt Anzahl neu angelegter.
     */
    public function backfillForTeam(int $teamId): int
    {
        $created = 0;

        // UPNs + Names aus Devices
        $deviceRows = AssetDevice::where('team_id', $teamId)
            ->whereNotNull('user_principal_name')
            ->select('user_principal_name', 'user_display_name')
            ->distinct()
            ->get();

        foreach ($deviceRows as $row) {
            if (!$row->user_principal_name) continue;
            $existed = AssetEmployee::where('team_id', $teamId)
                ->where('user_principal_name', $row->user_principal_name)
                ->exists();
            $this->findOrCreateByUpn($teamId, $row->user_principal_name, $row->user_display_name);
            if (!$existed) $created++;
        }

        // UPNs + Names aus User-Licenses
        $licenseRows = AssetUserLicense::where('team_id', $teamId)
            ->whereNotNull('user_principal_name')
            ->select('user_principal_name', 'display_name')
            ->distinct()
            ->get();

        foreach ($licenseRows as $row) {
            if (!$row->user_principal_name) continue;
            $existed = AssetEmployee::where('team_id', $teamId)
                ->where('user_principal_name', $row->user_principal_name)
                ->exists();
            $this->findOrCreateByUpn($teamId, $row->user_principal_name, $row->display_name);
            if (!$existed) $created++;
        }

        return $created;
    }
}
