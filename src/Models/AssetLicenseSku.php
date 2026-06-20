<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetLicenseSku extends Model
{
    use TenantScopable;

    protected $table = 'asset_license_skus';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'sku_id',
        'sku_part_number',
        'display_name',
        'purchased_units',
        'consumed_units',
        'available_units',
        'unit_price',
        'synced_at',
        'raw_data',
    ];

    protected $casts = [
        'raw_data'   => 'array',
        'synced_at'  => 'datetime',
        'unit_price' => 'decimal:2',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), zu dem diese SKU gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function monthlyCost(): float
    {
        if ($this->unit_price === null) {
            return 0.0;
        }
        return (float) $this->unit_price * $this->consumed_units;
    }

    public function annualCost(): float
    {
        return $this->monthlyCost() * 12;
    }

    public function utilizationPercent(): int
    {
        if ($this->purchased_units === 0) {
            return 0;
        }
        return (int) round($this->consumed_units / $this->purchased_units * 100);
    }
}
