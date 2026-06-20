<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
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
        } else {
            $dirty = false;
            if ($displayName && empty($employee->display_name)) {
                $employee->display_name = $displayName;
                $dirty = true;
            }
            // team_id defensiv nachziehen, falls abweichend (N14): tenant_id ist die Identitäts-Achse und
            // ein Tenant gehört genau EINEM Team — bei Drift (z. B. historische Daten) gewinnt das aktuelle
            // team_id, damit team-weite Auswertungen/Kostenmodell konsistent bleiben.
            if ((int) $employee->team_id !== $teamId) {
                $employee->team_id = $teamId;
                $dirty = true;
            }
            if ($dirty) {
                $employee->save();
            }
        }

        return $employee;
    }

    /**
     * Gezielte Einzel-Anonymisierung EINES Mitarbeiters (DSGVO Art. 17, Entscheidung E2 / ADR 0005).
     *
     * Pseudonymisiert die PII des Mitarbeiters (Anzeigename, E-Mail, UPN) und leert raw_data; maskiert
     * begleitend die PII der über die UPN verknüpften Geräte UND Lizenz-Zuweisungen (team-/tenant-scoped),
     * wobei die UPN auf denselben stabilen Pseudonym gesetzt wird, damit die Verknüpfung erhalten bleibt.
     *
     * Hinweis: KEINE Löschung des Datensatzes (Tenant-Purge bleibt die Komplett-Löschung). Existiert die
     * Person noch im M365 des Tenants, legt der nächste Sync sie unter ihrer echten UPN neu an — sinnvoll
     * nur für ausgeschiedene Personen. Aufrufer MUSS die Berechtigung (Owner/Admin) bereits geprüft haben.
     */
    public function anonymize(AssetEmployee $employee): void
    {
        $teamId   = (int) $employee->team_id;
        $tenantId = $employee->tenant_id;
        $oldUpn   = $employee->user_principal_name;

        // Stabiler, kollisionsfreier Pseudonym je Mitarbeiter (id ist eindeutig; .invalid-TLD existiert nie).
        $pseudoUpn  = 'anonymisiert-' . $employee->id . '@anonymized.invalid';
        $pseudoName = 'Anonymisiert #' . $employee->id;

        DB::transaction(function () use ($employee, $teamId, $tenantId, $oldUpn, $pseudoUpn, $pseudoName) {
            if ($oldUpn) {
                // Verknüpfte Geräte (per UPN) PII maskieren — UPN auf den Pseudonym setzen (Link bleibt).
                AssetDevice::where('team_id', $teamId)
                    ->where('tenant_id', $tenantId)
                    ->where('user_principal_name', $oldUpn)
                    ->update([
                        'user_principal_name' => $pseudoUpn,
                        'user_display_name'   => null,
                        'raw_data'            => null,
                    ]);

                // Verknüpfte Lizenz-Zuweisungen ebenso (tragen dieselbe Personen-PII).
                AssetUserLicense::where('team_id', $teamId)
                    ->where('tenant_id', $tenantId)
                    ->where('user_principal_name', $oldUpn)
                    ->update([
                        'user_principal_name' => $pseudoUpn,
                        'display_name'        => null,
                        'raw_data'            => null,
                    ]);
            }

            $employee->update([
                'user_principal_name' => $pseudoUpn,
                'display_name'        => $pseudoName,
                'email'               => null,
                'raw_data'            => null,
            ]);
        });
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
