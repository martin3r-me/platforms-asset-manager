<?php

namespace Platform\AssetManager\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetHolderEvent;
use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetUserLicense;

class HolderService
{
    /**
     * Präfix, das Entra beim Löschen vor die UPN setzt: die objectId ohne Bindestriche, gefolgt von
     * der ursprünglichen UPN (`0b521d5646ee4b158a9c13727799dbd0F.MUSTER@firma.de`). So heißt das
     * Konto, solange es im Papierkorb liegt — standardmäßig 30 Tage.
     *
     * Warum das hier steht: die UPN ist der Identitätsanker (ADR 0017). Ohne Normalisierung ist die
     * umbenannte UPN eine *andere* Person, und jeder Pfad, der sie sieht, legt einen zweiten Träger
     * an. Ein Löschen in Entra hat den Bestand hier dadurch nicht bereinigt, sondern verdoppelt.
     *
     * Der Lookahead verlangt nach dem Präfix einen **nicht leeren** Lokalteil und erst dann das `@`.
     * Ohne das `+` würde eine echte Adresse, deren Lokalteil aus genau 32 Hex-Zeichen besteht
     * (`0b521d…@firma.de` — etwa ein Dienstkonto), auf `@firma.de` zusammengestrichen.
     */
    public const DELETED_UPN_PREFIX = '/^[0-9a-f]{32}(?=[^@]+@)/i';

    /** Papierkorb-Präfix entfernen. Alles andere bleibt unverändert. */
    public static function normalizeUpn(string $upn): string
    {
        return preg_replace(self::DELETED_UPN_PREFIX, '', trim($upn)) ?: trim($upn);
    }

    /** Trägt diese UPN das Papierkorb-Präfix? */
    public static function isDeletedUpn(string $upn): bool
    {
        return preg_match(self::DELETED_UPN_PREFIX, trim($upn)) === 1;
    }

    /**
     * Findet einen Asset-Träger anhand UPN (tenant-skopiert) oder legt ihn an (source=derived).
     * Aktualisiert display_name nur, wenn der bestehende leer ist.
     *
     * tenant_id ist die Identitäts-Achse (Unique (tenant_id, user_principal_name)); team_id wird
     * für Team-weite Auswertungen/Kostenmodell mitgeführt.
     *
     * Die UPN wird vorher normalisiert: ein Konto im Entra-Papierkorb soll auf denselben Träger
     * zeigen wie vor der Löschung, nicht auf einen zweiten.
     */
    public function findOrCreateByUpn(
        int $teamId,
        int $tenantId,
        string $upn,
        ?string $displayName = null,
        string $source = 'derived'
    ): AssetHolder {
        $upn = self::normalizeUpn($upn);
        // withoutTenantScope() ist hier PFLICHT, nicht Kosmetik: der Tenant kommt als Parameter, und
        // der Global Scope würde zusätzlich auf den *aktiven* Tenant filtern. Weichen beide ab (Job
        // ohne gesetzten Kontext, Aufruf aus einer anderen Sicht), findet firstOrNew den bestehenden
        // Träger nicht und legt einen zweiten an — direkt in den (tenant_id, upn)-Unique-Index.
        $holder = AssetHolder::withoutTenantScope()->firstOrNew([
            'tenant_id'           => $tenantId,
            'user_principal_name' => $upn,
        ]);

        // Rückfall ohne Rücksicht auf Groß-/Kleinschreibung (#881): ein von Hand angelegter Träger
        // („max.muster@firma.de") muss vom Sync gefunden werden, auch wenn Entra die UPN gemischt
        // schreibt. MySQL vergleicht mit der Standard-Kollation ohnehin so, SQLite/Postgres nicht —
        // ohne den Rückfall hinge das Verhalten an der Datenbank. Zweite Abfrage nur bei Fehltreffer.
        if (! $holder->exists) {
            $match = $this->findByUpnInsensitive($tenantId, $upn);
            if ($match !== null) {
                $holder = $match;
            }
        }

        if (!$holder->exists) {
            $holder->team_id      = $teamId;
            $holder->source       = $source;
            $holder->display_name = $displayName;
            $holder->email        = $upn;
            $holder->is_active    = true;
            // Traeger-Typ schon bei der Anlage bestimmen (ADR 0017): sonst waere jeder neue Datensatz
            // bis zum naechsten applyGraphProfile() eine `person` — und Funktionskonten, die ueber
            // diesen Pfad entstehen, wuerden zwischenzeitlich als Personen gezaehlt.
            $holder->holder_type  = app(HolderClassifier::class)->classify($holder, $tenantId);

            try {
                $holder->save();
            } catch (UniqueConstraintViolationException) {
                // Geräte- und Lizenz-Sync laufen parallel und können denselben neuen Träger im selben
                // Moment anlegen. Der Verlierer liest den Gewinner, statt den ganzen Sync scheitern zu
                // lassen (#964).
                return AssetHolder::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('user_principal_name', $upn)
                    ->firstOrFail();
            }
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
     * Träger eines Tenants per UPN finden — ohne Rücksicht auf Groß-/Kleinschreibung.
     *
     * Verglichen wird die normalisierte UPN (Papierkorb-Präfix ab, ADR 0023) in Kleinschreibung.
     */
    public function findByUpnInsensitive(int $tenantId, string $upn): ?AssetHolder
    {
        $needle = mb_strtolower(self::normalizeUpn($upn));

        if ($needle === '') {
            return null;
        }

        return AssetHolder::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(user_principal_name) = ?', [$needle])
            ->orderBy('id')
            ->first();
    }

    /**
     * Asset-Träger von Hand anlegen (#881) — der Weg für einen Tenant ohne Microsoft-Anbindung, für
     * Externe und für Träger, die das Verzeichnis (noch) nicht kennt.
     *
     * Läuft über dieselben Regeln wie der Sync: UPN normalisiert (Papierkorb-Präfix, ADR 0023),
     * eindeutig je Tenant ohne Rücksicht auf Schreibweise, Typ aus den Tenant-Regeln — es sei denn,
     * der Mensch gibt ihn an; dann ist er festgehalten (#896). `source = 'manual'`: der Reconcile aus
     * ADR 0023 fasst nur `graph` an, ein manueller Träger wird also nie stillgelegt. Findet der Sync
     * später dieselbe UPN, reichert er diesen Träger an, statt einen zweiten anzulegen
     * ({@see findOrCreateByUpn()}).
     *
     * Rückgabe wie im übrigen Service-Layer: `ok = false` heißt bewusst blockiert mit Begründung.
     * **Autorisierung ist Aufruferpflicht.**
     *
     * @param  array{user_principal_name?: ?string, display_name?: ?string, email?: ?string,
     *               holder_type?: ?string, cost_center_id?: int|string|null, cost_center?: ?string,
     *               department?: ?string, job_title?: ?string}  $data
     * @return array{ok: bool, code: ?string, message: string, holder: ?AssetHolder, existing_id?: int}
     */
    public function createManual(int $teamId, int $tenantId, array $data): array
    {
        $fail = fn (string $code, string $message, array $extra = []) => [
            'ok' => false, 'code' => $code, 'message' => $message, 'holder' => null,
        ] + $extra;

        $upn = self::normalizeUpn(trim((string) ($data['user_principal_name'] ?? '')));

        if ($upn === '') {
            return $fail('VALIDATION_ERROR', 'Die UPN ist Pflicht — sie ist der Identitätsanker des Trägers.');
        }

        // Bewusst kein FILTER_VALIDATE_EMAIL: Gast-UPNs (`name_firma.de#EXT#@kunde.onmicrosoft.com`)
        // sind gültige Anmeldenamen, aber keine RFC-Adressen.
        if (mb_strlen($upn) > 255 || ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $upn)) {
            return $fail('VALIDATION_ERROR', 'Die UPN muss wie eine Anmeldeadresse aussehen (name@firma.de).');
        }

        $tenantOk = AssetTenant::query()->whereKey($tenantId)->where('team_id', $teamId)->exists();
        if (! $tenantOk) {
            return $fail('NOT_FOUND', 'Tenant nicht gefunden.');
        }

        $existing = $this->findByUpnInsensitive($tenantId, $upn);
        if ($existing !== null) {
            return $fail(
                'DUPLICATE',
                "Es gibt bereits einen Träger mit dieser UPN ({$existing->user_principal_name}).",
                ['existing_id' => (int) $existing->id],
            );
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $fail('VALIDATION_ERROR', 'Die E-Mail-Adresse ist ungültig.');
        }

        $type = trim((string) ($data['holder_type'] ?? ''));
        if ($type !== '' && ! in_array($type, AssetHolder::TYPES, true)) {
            return $fail('VALIDATION_ERROR', 'Unbekannter Träger-Typ. Erlaubt: ' . implode(', ', AssetHolder::TYPES) . '.');
        }

        // Kostenstelle team- UND tenant-gebunden auflösen (Cross-Team-FK-Muster): eine fremde ID darf
        // nicht still übernommen werden. Code und FK werden immer gemeinsam gesetzt.
        $center   = null;
        $centerId = $data['cost_center_id'] ?? null;
        $code     = trim((string) ($data['cost_center'] ?? ''));

        if ($centerId !== null && $centerId !== '') {
            $center = AssetCostCenter::withoutTenantScope()->forTenant($tenantId)
                ->where('team_id', $teamId)->whereKey((int) $centerId)->first();
            if ($center === null) {
                return $fail('COST_CENTER_NOT_FOUND', 'Kostenstelle nicht gefunden.');
            }
        } elseif ($code !== '') {
            $center = AssetCostCenter::withoutTenantScope()->forTenant($tenantId)
                ->where('team_id', $teamId)->where('code', $code)->first();
            if ($center === null) {
                return $fail('COST_CENTER_NOT_FOUND', "Kostenstelle '{$code}' existiert nicht.");
            }
        }

        $holder = new AssetHolder([
            'team_id'             => $teamId,
            'tenant_id'           => $tenantId,
            'user_principal_name' => $upn,
            'display_name'        => self::blankToNull($data['display_name'] ?? null),
            'email'               => $email !== '' ? $email : $upn,
            'department'          => self::blankToNull($data['department'] ?? null),
            'job_title'           => self::blankToNull($data['job_title'] ?? null),
            'cost_center'         => $center?->code,
            'cost_center_id'      => $center?->id,
            'is_active'           => true,
            'source'              => 'manual',
        ]);

        // Typ angegeben = ein Mensch hat entschieden → festhalten (#896). Sonst entscheiden die Regeln,
        // wie bei jedem Sync-Träger.
        if ($type !== '') {
            $holder->holder_type        = $type;
            $holder->holder_type_pinned = true;
        } else {
            $holder->holder_type        = app(HolderClassifier::class)->classify($holder, $tenantId);
            $holder->holder_type_pinned = false;
        }

        try {
            $holder->save();
        } catch (UniqueConstraintViolationException) {
            // Zeitgleich vom Sync angelegt — dann gibt es ihn jetzt, und das ist die richtige Antwort.
            return $fail('DUPLICATE', 'Es gibt bereits einen Träger mit dieser UPN.');
        }

        return [
            'ok'      => true,
            'code'    => null,
            'message' => "Asset-Träger '" . ($holder->display_name ?: $holder->user_principal_name) . "' angelegt.",
            'holder'  => $holder,
        ];
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
        // Gastkonto laut Entra (#969): als extern führen, solange der Typ noch der Default ist —
        // ein bewusst gesetzter Typ bleibt unangetastet. Ein von Hand festgehaltenes `person` (#896)
        // ist ein solcher bewusst gesetzter Typ, auch wenn es wie der Default aussieht.
        if (($graphUser['userType'] ?? null) === 'Guest'
            && ! $holder->holder_type_pinned
            && ($holder->holder_type === null || $holder->holder_type === AssetHolder::TYPE_PERSON)) {
            $holder->holder_type = AssetHolder::TYPE_EXTERNAL;
        }

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

        // Träger-Typ aus den tenant-eigenen Regeln bestimmen (ADR 0017). Läuft NACH den Feldern, weil
        // die Regeln auf E-Mail und Anzeigename greifen können und sonst auf veraltete Werte sähen.
        // Ein bereits gesetzter Nicht-Default-Typ (manuelle Korrektur) bleibt unangetastet — das
        // entscheidet der Classifier.
        $holder->holder_type = app(HolderClassifier::class)->classify($holder);
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

            // Der Verlauf (#897) trägt den Ausnahmegrund als Freitext — derselbe Personenbezug wie am
            // Träger selbst. Werte leeren, die Tatsache (wann, wer, welche Art) bleibt. Das Leeren am
            // Träger darf seinerseits keinen neuen Eintrag mit dem alten Grund erzeugen.
            AssetHolderEvent::withoutTenantScope()
                ->where('holder_id', $holder->id)
                ->whereIn('event_type', AssetHolderEvent::BILLING_TYPES)
                ->update(['old_value' => null, 'new_value' => null]);

            AssetHolderEvent::withoutRecording(fn () => $holder->update([
                'user_principal_name' => $pseudoUpn,
                'display_name'        => $pseudoName,
                'email'               => null,
                'raw_data'            => null,
                // Der Grund einer Abrechnungs-Ausnahme ist Freitext und trägt regelmäßig Personen-
                // bezug („Elternzeit", „ausgeschieden 09/2026"). Er fällt damit unter dieselbe
                // Löschung wie Name und E-Mail (ADR 0005). Der **Zeitstempel bleibt**: dass dieser
                // Träger nicht berechnet wird, ist eine kaufmännische Tatsache und darf durch eine
                // Anonymisierung nicht stillschweigend auf eine Rechnung zurückkehren (ADR 0024).
                'billing_excluded_reason' => null,
            ]));
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
        // Schlüssel = normalisierte UPN in Kleinschreibung (#939): Entra liefert dieselbe UPN auf Gerät
        // und Lizenz in unterschiedlicher Schreibweise, und eine Papierkorb-UPN (32-Hex-Präfix) gehört
        // zum selben Träger wie vor der Löschung. Ein case-sensitiver Schlüssel ohne Normalisierung hat
        // hier nach jedem Sync einen zweiten, aktiven Träger angelegt.
        $key = fn (string $upn): string => mb_strtolower(self::normalizeUpn($upn));

        $existing = AssetHolder::withoutTenantScope()->where('tenant_id', $tenantId)
            ->whereNotNull('user_principal_name')
            ->get(['id', 'user_principal_name', 'display_name'])
            ->keyBy(fn (AssetHolder $h) => $key((string) $h->user_principal_name));

        // Kandidaten (Schlüssel → [UPN, bester Anzeigename]) aus Geräten + Lizenz-Zuweisungen dedupliziert
        // sammeln. Erster nicht-leerer Name gewinnt (Geräte zuerst), damit eine UPN nur EINMAL angelegt wird.
        $candidates = [];
        $collect = function ($rows, string $nameField) use (&$candidates, $key) {
            foreach ($rows as $row) {
                $upn = trim((string) $row->user_principal_name);
                if ($upn === '') continue;
                $k = $key($upn);
                if (!isset($candidates[$k])) {
                    $candidates[$k] = ['upn' => self::normalizeUpn($upn), 'name' => $row->{$nameField} ?: null];
                } elseif (empty($candidates[$k]['name']) && $row->{$nameField}) {
                    $candidates[$k]['name'] = $row->{$nameField};
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
        foreach ($candidates as $k => $candidate) {
            $emp = $existing->get($k);
            if ($emp === null) {
                // Über findOrCreateByUpn: derselbe Weg wie der Inline-Pfad der Syncs — normalisiert UND
                // klassifiziert (vorher blieb jeder hier angelegte Träger `person`, #939).
                $this->findOrCreateByUpn($teamId, $tenantId, $candidate['upn'], $candidate['name']);
                $created++;
            } elseif ($candidate['name'] && empty($emp->display_name)) {
                // Anzeigename nur ergänzen, wenn bisher leer (wie findOrCreateByUpn).
                $emp->update(['display_name' => $candidate['name']]);
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
