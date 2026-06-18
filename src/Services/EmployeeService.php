<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetUserLicense;

class EmployeeService
{
    /**
     * Findet einen Employee anhand UPN (tenant-skopiert) oder legt ihn an (source=derived).
     * Aktualisiert display_name nur, wenn der bestehende leer ist.
     *
     * tenant_id ist die Identitäts-Achse (Unique (tenant_id, user_principal_name)); team_id wird
     * für Team-weite Auswertungen/Kostenmodell mitgeführt.
     */
    public function findOrCreateByUpn(
        int $teamId,
        int $tenantId,
        string $upn,
        ?string $displayName = null,
        string $source = 'derived'
    ): AssetEmployee {
        $employee = AssetEmployee::firstOrNew([
            'tenant_id'           => $tenantId,
            'user_principal_name' => $upn,
        ]);

        if (!$employee->exists) {
            $employee->team_id      = $teamId;
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
     * Backfill für genau einen Tenant: alle UPNs aus seinen Geräten + Lizenz-Zuweisungen einsammeln
     * und fehlende Employees anlegen. Returnt Anzahl neu angelegter.
     */
    public function backfillForTenant(int $teamId, int $tenantId): int
    {
        $created = 0;

        // UPNs + Names aus Devices
        $deviceRows = AssetDevice::where('tenant_id', $tenantId)
            ->whereNotNull('user_principal_name')
            ->select('user_principal_name', 'user_display_name')
            ->distinct()
            ->get();

        foreach ($deviceRows as $row) {
            if (!$row->user_principal_name) continue;
            $existed = AssetEmployee::where('tenant_id', $tenantId)
                ->where('user_principal_name', $row->user_principal_name)
                ->exists();
            $this->findOrCreateByUpn($teamId, $tenantId, $row->user_principal_name, $row->user_display_name);
            if (!$existed) $created++;
        }

        // UPNs + Names aus User-Licenses
        $licenseRows = AssetUserLicense::where('tenant_id', $tenantId)
            ->whereNotNull('user_principal_name')
            ->select('user_principal_name', 'display_name')
            ->distinct()
            ->get();

        foreach ($licenseRows as $row) {
            if (!$row->user_principal_name) continue;
            $existed = AssetEmployee::where('tenant_id', $tenantId)
                ->where('user_principal_name', $row->user_principal_name)
                ->exists();
            $this->findOrCreateByUpn($teamId, $tenantId, $row->user_principal_name, $row->display_name);
            if (!$existed) $created++;
        }

        return $created;
    }

    /**
     * Backfill für ein ganzes Team = über alle Tenants des Teams. Hält Konsole/Job
     * (BackfillEmployeesCommand/Job) team-orientiert, scoped intern aber sauber pro Tenant.
     */
    public function backfillForTeam(int $teamId): int
    {
        $created = 0;

        foreach (AssetTenant::where('team_id', $teamId)->pluck('id') as $tenantId) {
            $created += $this->backfillForTenant($teamId, $tenantId);
        }

        return $created;
    }
}
