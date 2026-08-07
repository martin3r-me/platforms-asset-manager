<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\TenantContext;

/**
 * Für **Auswertungs-Sichten** (Dashboard, Kosten): befolgt die „Alle Tenants"-Präferenz des Users
 * (docs/adr/0016).
 *
 * Nur diese Sichten dürfen tenant-übergreifend zeigen — dort ist die Aggregation über Kunden hinweg
 * die eigentliche Frage („was kostet uns IT insgesamt"). Listen und Detailseiten binden dieses Trait
 * bewusst **nicht** ein und bleiben damit automatisch auf dem aktiven Tenant, selbst wenn der User
 * „Alle Tenants" gewählt hat: eine gemischte Geräteliste ohne Kundenspalte wäre nicht zuordenbar.
 *
 * Der Default bleibt die engere Sicht. Das ist die Umkehrung von vorher (team-weit war der Default)
 * und gewollt.
 */
trait ReportsAcrossTenants
{
    /**
     * Callback im richtigen Auswertungs-Scope ausführen: bei „Alle Tenants" ohne Tenant-Filter
     * (der `team_id`-Filter der Queries bleibt die äußere Grenze), sonst auf dem aktiven Tenant.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inReportScope(callable $callback): mixed
    {
        return TenantContext::runFor($this->reportTenantId(), $callback);
    }

    /** Tenant der Auswertung — null heißt „alle Tenants des Teams". */
    protected function reportTenantId(): ?int
    {
        return $this->showsAllTenants() ? null : TenantContext::scopeTenantId();
    }

    /** Hat der User „Alle Tenants" gewählt? Für Hinweise in der Ansicht („Zahlen über alle Kunden"). */
    protected function showsAllTenants(): bool
    {
        $teamId = Auth::user()?->currentTeam?->id;

        if (! $teamId) {
            return false;
        }

        return TenantContext::prefersAllTenants((int) $teamId, (int) Auth::id());
    }
}
