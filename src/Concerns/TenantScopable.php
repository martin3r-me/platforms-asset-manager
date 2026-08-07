<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Platform\AssetManager\Support\TenantScope;

/**
 * Model-Trait für tenant-gebundene Daten. Bindet den {@see TenantScope} als **Global Scope** ein:
 * jede Query dieses Modells ist von sich aus auf den aktiven Tenant eingeschränkt (siehe
 * docs/adr/0016 — der Tenant ist eine Zugriffsgrenze, kein opt-in-Arbeitsfilter).
 *
 * Zwei Ausstiege, bewusst getrennt benannt:
 *
 *  - `withoutTenantScope()` — hebt die Einschränkung auf. Für Pfade ohne Auth-Kontext (Sync-Jobs,
 *    Console-Commands, Reconcile) und tenant-übergreifende Auswertungen. Immer explizit, damit im
 *    Code-Review sichtbar ist, wo die Grenze absichtlich fällt.
 *  - `forTenant(?int $id)` — filtert auf einen *anderen* Tenant als den aktiven. Nur sinnvoll in
 *    Kombination mit `withoutTenantScope()`; bleibt erhalten, weil Jobs pro Tenant iterieren.
 */
trait TenantScopable
{
    public static function bootTenantScopable(): void
    {
        static::addGlobalScope(TenantScope::IDENTIFIER, new TenantScope());
    }

    /** Global Scope abschalten — der eine, benannte Weg über die Tenant-Grenze. */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::IDENTIFIER);
    }

    /**
     * Auf eine konkrete Tenant-ID filtern (no-op bei null). Für Job-Schleifen über mehrere Tenants:
     * `->withoutTenantScope()->forTenant($id)`.
     */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->when(
            $tenantId,
            fn (Builder $q) => $q->where($this->getTable() . '.tenant_id', $tenantId),
        );
    }
}
