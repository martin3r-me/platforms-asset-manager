<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetUserLicense extends Model
{
    use TenantScopable;

    protected $table = 'asset_user_licenses';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'sku_id',
        'sku_part_number',
        'user_principal_name',
        'display_name',
        'assigned_at',
        // Preis dieses einen Seats — kommt von der zugeordneten Vertragszeile, nicht von der SKU.
        'unit_price',
        'price_source',
        'price_period',
        'contract_line_id',
        'raw_data',
    ];

    protected $casts = [
        'raw_data'    => 'array',
        'assigned_at' => 'datetime',
        'unit_price'  => 'float',
    ];

    /** Preis kommt aus einer Rechnung (nicht handgepflegt an der SKU). */
    public const PRICE_SOURCE_CONTRACT = 'contract';

    public function sku(): HasOne
    {
        return $this->hasOne(AssetLicenseSku::class, 'sku_id', 'sku_id')
            ->where('team_id', $this->team_id);
    }

    /** Tenant (Kundenkontext), zu dem diese Lizenzzuweisung gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    /** Die Vertragszeile, die diesen Seat bezahlt — der Weg zurück zum Beleg. */
    public function contractLine(): BelongsTo
    {
        return $this->belongsTo(AssetLicenseContractLine::class, 'contract_line_id');
    }

    /**
     * Trägt dieser Seat einen belegten Preis? Ein `false` heißt: zugewiesen, aber auf keiner Rechnung
     * — entweder gratis oder eine Lücke, die die Prüfung meldet.
     */
    public function hasContractPrice(): bool
    {
        return $this->unit_price !== null && $this->unit_price > 0;
    }
}
