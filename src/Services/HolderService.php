<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetUserLicense;

class HolderService
{
    /**
     * Findet einen Asset-Träger anhand UPN (tenant-skopiert) oder legt ihn an (source=derived).
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
    ): AssetHolder {
        // withoutTenantScope() ist hier PFLICHT, nicht Kosmetik: der Tenant kommt als Parameter, und
        // der Global Scope würde zusätzlich auf den *aktiven* Tenant filtern. Weichen beide ab (Job
        // ohne gesetzten Kontext, Aufruf aus einer anderen Sicht), findet firstOrNew den bestehenden
        // Träger nicht und legt einen zweiten an — direkt in den (tenant_id, upn)-Unique-Index.
        $holder = AssetHolder::withoutTenantScope()->firstOrNew([
            'tenant_id'           => $tenantId,
            'user_principal_name' => $upn,
        ]);

        if (!$holder->exists) {
            $holder->team_id      = $teamId;
            $holder->source       = $source;
            $holder->display_name = $displayName;
            $holder->email        = $upn;
            $holder->is_active    = true;
            $holder->save();
        } else {
            $dirty = false;
            if ($displayName && empty($holder->display_name)) {
                $holder->display_name = $displayName;
                $dirty = true;
            }
            // team_id defensiv nachziehen, falls abweichend (N14): tenant_id ist die Identitäts-Achse und
            // ein Tenant gehört genau EINEM Team — bei Drift (z. B. historische Daten) gewinnt das aktuelle
            // team_id, damit team-weite Auswertungen/Kostenmodell konsistent bleiben.
            if ((int) $holder->team_id !== $teamId) {
                $holder->team_id = $teamId;
                $dirty = true;
            }
            if ($dirty) {
                $holder->save();
            }
        }

        return $holder;
    }

    /**
     * Reichert einen Asset-Träger mit Graph-Profildaten an (department/jobTitle/Rufnummern) — die gemeinsame
     * Precedence-Logik für den User-Import UND den regulären Lizenz-Sync (ADR 0014), damit die Regel nicht
     * dupliziert wird:
     *   - department/job_title: Entra ist führend — NICHT-leere Graph-Werte überschreiben, leere lassen den
     *     Bestand (kein Datenverlust durch lückenhaftes Entra).
     *   - Rufnummern (mobile_phone/business_phone): nur solange NICHT manuell übersteuert (phone_overridden);
     *     businessPhones ist ein Array → erste Nummer.
     *
     * Mutiert das übergebene Objekt (dirty), SPEICHERT NICHT — der Aufrufer ruft save().
     */
    public function applyGraphProfile(AssetHolder $holder, array $graphUser): void
    {
        if (! empty($graphUser['department'])) {
            $holder->department = $graphUser['department'];
        }
        if (! empty($graphUser['jobTitle'])) {
            $holder->job_title = $graphUser['jobTitle'];
        }

        if (! $holder->phone_overridden) {
            if (! empty($graphUser['mobilePhone'])) {
                $holder->mobile_phone = self::normalizePhone($graphUser['mobilePhone']);
            }
            if (! empty($graphUser['businessPhones'][0])) {
                $holder->business_phone = self::normalizePhone($graphUser['businessPhones'][0]);
            }
        }
    }

    /**
     * Normalisiert eine (aus Entra gelieferte) Rufnummer auf führendes '+' + Ziffern und entfernt
     * Störzeichen (Leerzeichen, /, |, -, Klammern, Text). Enthält das Feld MEHRERE echte Nummern
     * (>= 2 Segmente mit je >= 7 Ziffern, getrennt durch / | ; , „oder"), wird die ERSTE genommen —
     * ein bloßer Formatierungs-Trenner innerhalb EINER Nummer wird dagegen nur entfernt (Vorwahl bleibt).
     * Gibt null zurück, wenn nichts Nummernartiges übrig bleibt (< 4 Ziffern).
     */
    public static function normalizePhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // An typischen Trennern zwischen zwei Nummern segmentieren.
        $segments = preg_split('~\s*[/|;,]\s*|\s+oder\s+|[\r\n]+~i', $raw);

        // Segmente, die FÜR SICH eine plausible Nummer sind (>= 7 Ziffern).
        $long = [];
        foreach ($segments as $seg) {
            if (strlen(preg_replace('/\D+/', '', $seg)) >= 7) {
                $long[] = $seg;
            }
        }

        // >= 2 echte Nummern → der Trenner trennt zwei Nummern → erste nehmen.
        if (count($long) >= 2) {
            $pick = $long[0];
            $plus = str_starts_with(ltrim($pick), '+') ? '+' : '';
            return $plus . preg_replace('/\D+/', '', $pick);
        }

        // Sonst: EINE Nummer — der Trenner war Formatierung → nur '+' (führend) + Ziffern behalten.
        $plus   = str_starts_with($raw, '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', $raw);

        return strlen($digits) >= 4 ? $plus . $digits : null;
    }

    /**
     * Gezielte Einzel-Anonymisierung EINES Asset-Trägers (DSGVO Art. 17, Entscheidung E2 / ADR 0005).
     *
     * Pseudonymisiert die PII des Asset-Trägers (Anzeigename, E-Mail, UPN) und leert raw_data; maskiert
     * begleitend die PII der über die UPN verknüpften Geräte UND Lizenz-Zuweisungen (team-/tenant-scoped),
     * wobei die UPN auf denselben stabilen Pseudonym gesetzt wird, damit die Verknüpfung erhalten bleibt.
     *
     * Hinweis: KEINE Löschung des Datensatzes (Tenant-Purge bleibt die Komplett-Löschung). Existiert die
     * Person noch im M365 des Tenants, legt der nächste Sync sie unter ihrer echten UPN neu an — sinnvoll
     * nur für ausgeschiedene Personen. Aufrufer MUSS die Berechtigung (Owner/Admin) bereits geprüft haben.
     */
    public function anonymize(AssetHolder $holder): void
    {
        $teamId   = (int) $holder->team_id;
        $tenantId = $holder->tenant_id;
        $oldUpn   = $holder->user_principal_name;

        // Stabiler, kollisionsfreier Pseudonym je Asset-Träger (id ist eindeutig; .invalid-TLD existiert nie).
        $pseudoUpn  = 'anonymisiert-' . $holder->id . '@anonymized.invalid';
        $pseudoName = 'Anonymisiert #' . $holder->id;

        DB::transaction(function () use ($holder, $teamId, $tenantId, $oldUpn, $pseudoUpn, $pseudoName) {
            if ($oldUpn) {
                // Verknüpfte Geräte (per UPN) PII maskieren — UPN auf den Pseudonym setzen (Link bleibt).
                AssetDevice::withoutTenantScope()->where('team_id', $teamId)
                    ->where('tenant_id', $tenantId)
                    ->where('user_principal_name', $oldUpn)
                    ->update([
                        'user_principal_name' => $pseudoUpn,
                        'user_display_name'   => null,
                        'raw_data'            => null,
                    ]);

                // Verknüpfte Lizenz-Zuweisungen ebenso (tragen dieselbe Personen-PII).
                AssetUserLicense::withoutTenantScope()->where('team_id', $teamId)
                    ->where('tenant_id', $tenantId)
                    ->where('user_principal_name', $oldUpn)
                    ->update([
                        'user_principal_name' => $pseudoUpn,
                        'display_name'        => null,
                        'raw_data'            => null,
                    ]);
            }

            // Geräteausgabe-Protokolle dieses Asset-Trägers: PII der Person pseudonymisieren — signer_name
            // sowie die User-Felder im device_snapshot. Gerätedaten (Name/Seriennr./Modell) bleiben, da
            // keine Personen-PII. Protokoll bleibt als anonymisierte Historie erhalten (kein Löschen).
            $handovers = AssetHandover::where('team_id', $teamId)
                ->where('holder_id', $holder->id)
                ->with('lines')
                ->get();

            foreach ($handovers as $handover) {
                if ($handover->signer_name) {
                    $handover->update(['signer_name' => $pseudoName]);
                }

                foreach ($handover->lines as $line) {
                    $snap = is_array($line->device_snapshot) ? $line->device_snapshot : null;
                    if ($snap && (($snap['user_principal_name'] ?? null) || ($snap['user_display_name'] ?? null))) {
                        $snap['user_principal_name'] = $pseudoUpn;
                        $snap['user_display_name']   = null;
                        $line->update(['device_snapshot' => $snap]);
                    }
                }
            }

            $holder->update([
                'user_principal_name' => $pseudoUpn,
                'display_name'        => $pseudoName,
                'email'               => null,
                'raw_data'            => null,
            ]);
        });
    }

    /**
     * Backfill für genau einen Tenant: alle UPNs aus seinen Geräten + Lizenz-Zuweisungen einsammeln
     * und fehlende Asset-Träger anlegen. Returnt Anzahl neu angelegter.
     */
    public function backfillForTenant(int $teamId, int $tenantId): int
    {
        // Bestehende Asset-Träger des Tenants EINMAL vorladen — statt je UPN ein exists() PLUS ein
        // firstOrNew (~2 Queries je UPN in einer Schleife, die nach jedem Sync läuft).
        $existing = AssetHolder::withoutTenantScope()->where('tenant_id', $tenantId)
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
            AssetDevice::withoutTenantScope()->where('tenant_id', $tenantId)->whereNotNull('user_principal_name')
                ->select('user_principal_name', 'user_display_name')->distinct()->get(),
            'user_display_name'
        );
        $collect(
            AssetUserLicense::withoutTenantScope()->where('tenant_id', $tenantId)->whereNotNull('user_principal_name')
                ->select('user_principal_name', 'display_name')->distinct()->get(),
            'display_name'
        );

        $created = 0;
        foreach ($candidates as $upn => $displayName) {
            $emp = $existing->get($upn);
            if ($emp === null) {
                // Neu anlegen (verhaltensgleich zu findOrCreateByUpn: source=derived, email=upn, aktiv).
                AssetHolder::create([
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
     * (BackfillHoldersCommand/Job) team-orientiert, scoped intern aber sauber pro Tenant.
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
