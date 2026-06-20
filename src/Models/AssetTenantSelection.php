<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gespeicherte Tenant-Auswahl je (User × Team) — die Persistenz hinter dem Tenant-Selektor (M3).
 * Genau eine Zeile je User & Team (unique). selected_tenant_id ist nullable: ist nichts gewählt
 * (oder der Tenant gelöscht → nullOnDelete), löst Services\TenantContext auf den Default-Tenant auf.
 * Auto-increment-IDs (Modul-Konvention, kein UuidV7).
 */
class AssetTenantSelection extends Model
{
    protected $table = 'asset_tenant_selections';

    protected $fillable = [
        'user_id',
        'team_id',
        'selected_tenant_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'selected_tenant_id');
    }
}
