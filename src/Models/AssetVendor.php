<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Kreditor (Lieferant/Rechnungssteller) — je Tenant gepflegt (docs/adr/0016). „Vodafone" gibt es bei
 * jedem Kunden, aber mit eigener Kreditorennummer und eigenen Verträgen.
 */
class AssetVendor extends Model
{
    use TenantScopable;

    protected $table = 'asset_vendors';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'name',
        'creditor_no',
        'notes',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'vendor_id');
    }
}
