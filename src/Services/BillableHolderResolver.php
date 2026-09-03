<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetUserLicense;

/**
 * Wertet die Zählregel eines {@see AssetBillingProfile} gegen den Bestand aus: **wer wird
 * abgerechnet, wer nicht, und warum nicht**.
 *
 * Getrennt vom {@see BillingRunService}, weil das die eine Stelle ist, an der eine falsche Zeile
 * unmittelbar Geld kostet — sie soll ohne easybill, ohne Commerce und ohne Livewire prüfbar sein
 * (`tests/local/billing-run.php`, `tests/local/billing-exclusion.php`).
 *
 * Die Ausgeschlossenen werden mitgeführt statt weggeworfen. Eine Rechnung über 152 statt 290 Köpfe
 * ist erklärungsbedürftig, und die Erklärung muss beim Lauf danebenstehen — nicht in einer Query,
 * die jemand später nachbaut.
 *
 * **Die Kaskade hat drei Stufen** (ADR 0021 + ADR 0024), von der allgemeinsten zur speziellsten:
 *
 *  1. **Regel** — kein UPN, kein Personenkonto, ausgeschlossene Domain, keine Lizenz. Entscheidet
 *     über den Normalfall, ohne dass jemand pro Kopf etwas pflegt.
 *  2. **Regel-Erweiterungen am Profil** — zählen Externe mit, plus Namensmuster für Test- und
 *     technische Konten. Erledigt ganze Fallgruppen mit einem Eintrag.
 *  3. **Einzelausnahme am Träger** — die begründete, datierte Menschenentscheidung für den
 *     Sonderfall, den kein Muster trifft.
 *
 * Die Einzelausnahme wird **zuerst** geprüft, obwohl sie die speziellste Stufe ist: wenn jemand
 * einen Träger bewusst herausgenommen hat, ist das der Grund, der in der Vorschau stehen soll —
 * auch dann, wenn ihn ohnehin schon die fehlende Lizenz herausgehalten hätte. Der zweite Grund
 * wäre wahr, aber er würde die Entscheidung verschweigen.
 */
class BillableHolderResolver
{
    /**
     * @param  array<int, bool>  $exclusionOverrides  Einzelausnahmen (Stufe 3) für die Dauer dieses
     *   Aufrufs anders annehmen: `[holderId => true]` behandelt den Träger als ausgenommen,
     *   `[holderId => false]` als nicht ausgenommen. Damit lässt sich die Frage „was würde eine
     *   Sammelaktion an der Menge ändern?" **exakt** beantworten, statt sie aus der Schnittmenge zu
     *   schätzen — auch dann, wenn derselbe Mensch zweimal im Verzeichnis steht und nur eine der
     *   beiden Zeilen ausgenommen wird. Ein Rechnungslauf übergibt hier nie etwas.
     * @return array{
     *     principals: string[],
     *     rows: array<int, array{id: int, upn: string, name: string, department: string|null}>,
     *     skipped: array<int, array{upn: string, reason: string}>,
     *     exceptions: array<int, array{id: int, upn: string, name: string, reason: string|null,
     *                                  since: string|null, days: int|null}>
     * }
     */
    public function resolve(AssetBillingProfile $profile, array $exclusionOverrides = []): array
    {
        $holders = $this->holdersForTenant($profile);

        $licensedPrincipals = $profile->basis === AssetBillingProfile::BASIS_LICENSED
            ? $this->licensedPrincipals($profile)
            : null;

        $excludedDomains = $profile->normalizedExcludeDomains();
        $patterns        = $profile->normalizedExcludePatterns();
        $countsExternal  = $profile->countsExternal();

        $rows       = [];
        $skipped    = [];
        $exceptions = [];

        foreach ($holders as $holder) {
            $upn = trim((string) $holder->user_principal_name);

            // Ein Träger ohne UPN ist im Sinne der Abrechnung keine Person, sondern ein Datenrest.
            // Er hat keine Identität, an der Lizenz oder Betreuung hängen könnte.
            if ($upn === '') {
                $skipped[] = ['upn' => '(ohne UPN)', 'reason' => 'keine UPN hinterlegt'];
                continue;
            }

            // Stufe 3: die Einzelausnahme (ADR 0024). Die Liste der Ausnahmen entsteht aus dem
            // gespeicherten Stand, nicht aus dem übersteuerten — sie beschreibt, was am Träger
            // steht, während `skipped` beschreibt, was dieser Lauf daraus macht.
            if ($holder->isBillingExcluded()) {
                $exceptions[] = $this->exceptionEntry($holder, $upn);
            }

            if ($exclusionOverrides[$holder->id] ?? $holder->isBillingExcluded()) {
                $skipped[] = ['upn' => $upn, 'reason' => $this->exceptionReason($holder)];
                continue;
            }

            // Funktions-, Administrations- und Dienstkonten sind keine betreuten Menschen. Sie
            // verbrauchen zwar Lizenzen (deshalb zählen die Kostenauswertungen sie mit, siehe
            // AssetHolder::NON_PERSON_TYPES), aber niemand berechnet sie einem Kunden pro Kopf.
            if (in_array($holder->holder_type, AssetHolder::NON_PERSON_TYPES, true)) {
                $skipped[] = ['upn' => $upn, 'reason' => 'kein Personenkonto (' . $holder->holder_type . ')'];
                continue;
            }

            // Stufe 2: Externe. Sie sind Menschen mit Lizenz, aber nicht zwingend welche, die der
            // Kunde bezahlt — das entscheidet der Vertrag und damit das Profil, nicht der Typ.
            if (! $countsExternal && $holder->holder_type === AssetHolder::TYPE_EXTERNAL) {
                $skipped[] = ['upn' => $upn, 'reason' => 'Externe zählen in diesem Profil nicht mit'];
                continue;
            }

            $domain = strtolower((string) substr(strrchr($upn, '@') ?: '', 1));

            if ($domain !== '' && in_array($domain, $excludedDomains, true)) {
                $skipped[] = ['upn' => $upn, 'reason' => 'ausgeschlossene Domain (' . $domain . ')'];
                continue;
            }

            // Stufe 2: Namensmuster für Test- und technische Konten. Eine Regel für zwölf Konten
            // statt zwölf Einzelausnahmen, die niemand mehr aufräumt.
            if ($pattern = $this->firstMatchingPattern($holder, $patterns)) {
                $skipped[] = ['upn' => $upn, 'reason' => 'Ausschlussmuster (' . $this->patternLabel($pattern) . ')'];
                continue;
            }

            if ($licensedPrincipals !== null && ! isset($licensedPrincipals[strtolower($upn)])) {
                $skipped[] = ['upn' => $upn, 'reason' => 'keine Microsoft-Lizenz'];
                continue;
            }

            $rows[] = [
                // Die Id trägt die Zeile zum Trägerprofil. Stimmt an einer Person etwas nicht, wird
                // sie dort korrigiert (Typ, Status, Ausnahme) — nicht als stille Sonderbehandlung an
                // der Rechnung vorbei, die beim nächsten Lauf niemand mehr erklären kann (ADR 0021).
                'id'         => (int) $holder->id,
                'upn'        => $upn,
                'name'       => (string) ($holder->display_name ?: $upn),
                'department' => $holder->department,
            ];
        }

        // Derselbe Mensch darf nicht zweimal auf der Rechnung stehen, auch wenn er zweimal im
        // Verzeichnis steht. Der Vergleich läuft case-insensitiv, weil die UPNs aus verschiedenen
        // Quellen unterschiedlich geschrieben ankommen (Graph liefert sie teils in Großbuchstaben).
        $rows = collect($rows)
            ->unique(fn (array $row) => strtolower($row['upn']))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        // `principals` bleibt die schlanke UPN-Liste: sie geht in den Snapshot des Laufs und soll
        // dort nicht mit Anzeigedaten aufgebläht werden, die sich ohnehin ändern können.
        return [
            'principals' => array_column($rows, 'upn'),
            'rows'       => $rows,
            'skipped'    => $skipped,
            'exceptions' => $exceptions,
        ];
    }

    /**
     * Eine Einzelausnahme, wie sie am Profil aggregiert erscheint.
     *
     * `days` ist das Gegenmittel gegen das Verrotten von Ausnahmelisten: eine Ausnahme von vor zwei
     * Jahren berechnet dem Kunden seit zwei Jahren zu wenig, und nichts außer dieser Zahl würde
     * daran erinnern.
     *
     * @return array{id: int, upn: string, name: string, reason: string|null, since: string|null,
     *               days: int|null}
     */
    protected function exceptionEntry(AssetHolder $holder, string $upn): array
    {
        $since = $holder->billing_excluded_at;

        return [
            'id'     => (int) $holder->id,
            'upn'    => $upn,
            'name'   => (string) ($holder->display_name ?: $upn),
            'reason' => $holder->billing_excluded_reason,
            'since'  => $since?->format('d.m.Y'),
            'days'   => $since ? (int) $since->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
        ];
    }

    /**
     * Der Ausschlussgrund einer Einzelausnahme, wie er in Vorschau und Snapshot steht.
     *
     * Grund **und** Datum in einer Zeile, weil beides einzeln nichts erklärt: „Geschäftsführung"
     * ohne Datum sagt nicht, ob die Absprache noch gilt; ein Datum ohne Grund sagt gar nichts.
     */
    protected function exceptionReason(AssetHolder $holder): string
    {
        $reason = trim((string) $holder->billing_excluded_reason);
        $since  = $holder->billing_excluded_at?->format('d.m.Y');

        return 'Einzelausnahme'
            . ($reason !== '' ? ': ' . $reason : '')
            . ($since ? ' (seit ' . $since . ')' : '');
    }

    /**
     * Erstes greifendes Ausschlussmuster — oder null.
     *
     * @param  array<int, array{field: string, match: string, value: string, label: string|null}>  $patterns
     * @return array{field: string, match: string, value: string, label: string|null}|null
     */
    protected function firstMatchingPattern(AssetHolder $holder, array $patterns): ?array
    {
        foreach ($patterns as $pattern) {
            $subject = mb_strtolower(trim((string) match ($pattern['field']) {
                'email'        => $holder->email,
                'display_name' => $holder->display_name,
                default        => $holder->user_principal_name,
            }));

            if ($subject === '') {
                continue;
            }

            $hit = match ($pattern['match']) {
                'prefix'   => str_starts_with($subject, $pattern['value']),
                'suffix'   => str_ends_with($subject, $pattern['value']),
                'equals'   => $subject === $pattern['value'],
                'contains' => str_contains($subject, $pattern['value']),
                default    => false,
            };

            if ($hit) {
                return $pattern;
            }
        }

        return null;
    }

    /** Beschriftung eines Musters für die Vorschau — die eigene, sonst die technische Form. */
    protected function patternLabel(array $pattern): string
    {
        return $pattern['label'] ?? ($pattern['field'] . ' ' . $pattern['match'] . ' "' . $pattern['value'] . '"');
    }

    /**
     * Die Träger des Tenants, auf den das Profil zeigt.
     *
     * Bewusst `withoutTenantScope()->forTenant(...)`: der Lauf darf nicht davon abhängen, welchen
     * Tenant der Bediener gerade in der Sidebar stehen hat. Eine Rechnung gehört zum Profil, nicht
     * zur Bildschirmansicht.
     */
    protected function holdersForTenant(AssetBillingProfile $profile): Collection
    {
        return AssetHolder::query()
            ->withoutTenantScope()
            ->forTenant($profile->tenant_id)
            ->where('team_id', $profile->team_id)
            ->where('is_active', true)
            ->orderBy('user_principal_name')
            ->get([
                'id', 'user_principal_name', 'display_name', 'email', 'department',
                'holder_type', 'is_active',
                'billing_excluded_at', 'billing_excluded_reason', 'billing_excluded_by',
            ]);
    }

    /**
     * UPNs mit mindestens einer Lizenzzuweisung, kleingeschrieben als Nachschlage-Menge.
     *
     * Als Map und nicht als `whereIn` in der Trägerabfrage, weil der Abgleich sonst der
     * Datenbank-Collation ausgeliefert wäre: MySQL vergleicht standardmäßig ohne Rücksicht auf
     * Groß-/Kleinschreibung, SQLite nicht. Derselbe Lauf käme lokal und live auf verschiedene Zahlen.
     *
     * @return array<string, true>
     */
    protected function licensedPrincipals(AssetBillingProfile $profile): array
    {
        return AssetUserLicense::query()
            ->withoutTenantScope()
            ->forTenant($profile->tenant_id)
            ->where('team_id', $profile->team_id)
            ->whereNotNull('user_principal_name')
            ->distinct()
            ->pluck('user_principal_name')
            ->mapWithKeys(fn ($upn) => [strtolower(trim((string) $upn)) => true])
            ->all();
    }
}
