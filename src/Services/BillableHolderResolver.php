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
 * (`tests/local/billing-count.php`).
 *
 * Die Ausgeschlossenen werden mitgeführt statt weggeworfen. Eine Rechnung über 152 statt 290 Köpfe
 * ist erklärungsbedürftig, und die Erklärung muss beim Lauf danebenstehen — nicht in einer Query,
 * die jemand später nachbaut.
 */
class BillableHolderResolver
{
    /**
     * @return array{principals: string[], skipped: array<int, array{upn: string, reason: string}>}
     */
    public function resolve(AssetBillingProfile $profile): array
    {
        $holders = $this->holdersForTenant($profile);

        $licensedPrincipals = $profile->basis === AssetBillingProfile::BASIS_LICENSED
            ? $this->licensedPrincipals($profile)
            : null;

        $excludedDomains = $profile->normalizedExcludeDomains();

        $principals = [];
        $skipped    = [];

        foreach ($holders as $holder) {
            $upn = trim((string) $holder->user_principal_name);

            // Ein Träger ohne UPN ist im Sinne der Abrechnung keine Person, sondern ein Datenrest.
            // Er hat keine Identität, an der Lizenz oder Betreuung hängen könnte.
            if ($upn === '') {
                $skipped[] = ['upn' => '(ohne UPN)', 'reason' => 'keine UPN hinterlegt'];
                continue;
            }

            // Funktions-, Administrations- und Dienstkonten sind keine betreuten Menschen. Sie
            // verbrauchen zwar Lizenzen (deshalb zählen die Kostenauswertungen sie mit, siehe
            // AssetHolder::NON_PERSON_TYPES), aber niemand berechnet sie einem Kunden pro Kopf.
            if (in_array($holder->holder_type, AssetHolder::NON_PERSON_TYPES, true)) {
                $skipped[] = ['upn' => $upn, 'reason' => 'kein Personenkonto (' . $holder->holder_type . ')'];
                continue;
            }

            $domain = strtolower((string) substr(strrchr($upn, '@') ?: '', 1));

            if ($domain !== '' && in_array($domain, $excludedDomains, true)) {
                $skipped[] = ['upn' => $upn, 'reason' => 'ausgeschlossene Domain (' . $domain . ')'];
                continue;
            }

            if ($licensedPrincipals !== null && ! isset($licensedPrincipals[strtolower($upn)])) {
                $skipped[] = ['upn' => $upn, 'reason' => 'keine Microsoft-Lizenz'];
                continue;
            }

            $principals[] = $upn;
        }

        // Derselbe Mensch darf nicht zweimal auf der Rechnung stehen, auch wenn er zweimal im
        // Verzeichnis steht. Der Vergleich läuft case-insensitiv, weil die UPNs aus verschiedenen
        // Quellen unterschiedlich geschrieben ankommen (Graph liefert sie teils in Großbuchstaben).
        $principals = collect($principals)
            ->unique(fn (string $upn) => strtolower($upn))
            ->values()
            ->all();

        return ['principals' => $principals, 'skipped' => $skipped];
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
            ->get(['id', 'user_principal_name', 'display_name', 'holder_type', 'is_active']);
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
