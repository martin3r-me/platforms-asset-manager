<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Model-Trait für tenant-gebundenes Inventar (M3). Liefert einen einheitlichen `forTenant`-Scope,
 * der NUR filtert, wenn eine Tenant-ID gesetzt ist — bei null bleibt die Query unverändert
 * (team-weit). So ist derselbe Aufruf in jeder Inventar-Query sicher, auch bevor ein Tenant
 * gewählt wurde.
 *
 * Spalte qualifiziert (table.tenant_id), damit der Scope auch in Joins eindeutig bleibt.
 */
trait TenantScopable
{
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->when(
            $tenantId,
            fn (Builder $q) => $q->where($this->getTable() . '.tenant_id', $tenantId),
        );
    }
}
