<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gespeicherte Tenant-Auswahl je (User × Team) — die Persistenz hinter dem Tenant-Umschalter.
 * Genau eine Zeile je User & Team (unique). selected_tenant_id ist nullable: ist nichts gewählt
 * (oder der Tenant gelöscht → nullOnDelete), löst Services\TenantContext auf den Default-Tenant auf.
 *
 * `all_tenants` ist die Präferenz „Alle Tenants" (docs/adr/0016) und **unabhängig** von
 * `selected_tenant_id`: letzteres bleibt immer der zuletzt gewählte konkrete Tenant, auf den Listen-
 * und Detailseiten zurückfallen, während nur Dashboard und Auswertungen die Präferenz befolgen.
 *
 * Trägt bewusst KEIN {@see \Platform\AssetManager\Concerns\TenantScopable}: dieses Modell ist die
 * Auswahl des Tenants, nicht tenant-gebundene Daten — ein Global Scope hier wäre eine Rekursion.
 * Auto-increment-IDs (Modul-Konvention, kein UuidV7).
 */
class AssetTenantSelection extends Model
{
    protected $table = 'asset_tenant_selections';

    protected $fillable = [
        'user_id',
        'team_id',
        'selected_tenant_id',
        'all_tenants',
    ];

    protected $casts = [
        'all_tenants' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'selected_tenant_id');
    }
}
