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
        // Bestehende Employees des Tenants EINMAL vorladen — statt je UPN ein exists() PLUS ein
        // firstOrNew (~2 Queries je UPN in einer Schleife, die nach jedem Sync läuft).
        $existing = AssetEmployee::where('tenant_id', $tenantId)
            ->whereNotNull('user_principal_name')
            ->get(['id', 'user_principal_name', 'display_name'])
            ->keyBy('user_principal_name');

        // Kandidaten (UPN → bester Anzeigename) aus Geräten + Lizenz-Zuweisungen dedupliziert sammeln.
        // Erster nicht-leerer Name gewinnt (Geräte zuerst), damit eine UPN nur EINMAL angelegt wird.
        $candidates = [];
        $collect = function ($rows, string $nameField) use (&$candidates) {
            foreach ($rows as $row) {
                $upn = $row->user_principal_name;
                if (!$upn) continue;
                if (!array_key_exists($upn, $candidates) || empty($candidates[$upn])) {
                    $candidates[$upn] = $row->{$nameField} ?: ($candidates[$upn] ?? null);
                }
            }
        };

        $collect(
            AssetDevice::where('tenant_id', $tenantId)->whereNotNull('user_principal_name')
                ->select('user_principal_name', 'user_display_name')->distinct()->get(),
            'user_display_name'
        );
        $collect(
            AssetUserLicense::where('tenant_id', $tenantId)->whereNotNull('user_principal_name')
                ->select('user_principal_name', 'display_name')->distinct()->get(),
            'display_name'
        );

        $created = 0;
        foreach ($candidates as $upn => $displayName) {
            $emp = $existing->get($upn);
            if ($emp === null) {
                // Neu anlegen (verhaltensgleich zu findOrCreateByUpn: source=derived, email=upn, aktiv).
                AssetEmployee::create([
                    'team_id'             => $teamId,
                    'tenant_id'           => $tenantId,
                    'user_principal_name' => $upn,
                    'email'               => $upn,
                    'display_name'        => $displayName,
                    'is_active'           => true,
                    'source'              => 'derived',
                ]);
                $created++;
            } elseif ($displayName && empty($emp->display_name)) {
                // Anzeigename nur ergänzen, wenn bisher leer (wie findOrCreateByUpn).
                $emp->update(['display_name' => $displayName]);
            }
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
