<?php

namespace Platform\AssetManager\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Platform\AssetManager\Services\TenantContext;

/**
 * Global Scope, der jede Query auf den aktiven Tenant einschränkt (siehe docs/adr/0016).
 *
 * Kehrt das bisherige Modell um: statt `->forTenant($id)` an ~200 Stellen manuell zu rufen (und es an
 * 17 von 27 Livewire-Komponenten zu vergessen), filtert die Datenbank-Ebene von sich aus. Ein
 * vergessener Filter kann keine Fremddaten mehr liefern, weil es keinen Filter mehr zu vergessen gibt.
 *
 * Der Ausstieg ist ausdrücklich und benannt: `Model::query()->withoutTenantScope()`. Das ist der Pfad
 * für alles ohne Auth-Kontext (Sync-Jobs, Console-Commands, Reconcile) und für die tenant-übergreifenden
 * Auswertungen.
 *
 * Spalte qualifiziert (`table.tenant_id`), damit der Scope auch in Joins eindeutig bleibt.
 */
class TenantScope implements Scope
{
    /** Registrierungs-Schlüssel — mit diesem Namen entfernt withoutTenantScope() den Scope wieder. */
    public const IDENTIFIER = 'asset-manager.tenant';

    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::scopeTenantId();

        // null = kein auflösbarer Tenant-Kontext (Queue-Job, Console, Boot) ODER bewusst
        // tenant-übergreifend. Beides darf NICHT auf „irgendeinen" Tenant raten — die Pfade ohne
        // Auth-Kontext arbeiten legitim über alle Tenants und sind zusätzlich explizit ausgenommen.
        if ($tenantId === null) {
            return;
        }

        $builder->where($model->getTable() . '.tenant_id', $tenantId);
    }
}
