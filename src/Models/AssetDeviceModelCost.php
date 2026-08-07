<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Kosten eines Geräte-Modells **je Tenant** (docs/adr/0016).
 *
 * Der Hardware-Katalog {@see AssetDeviceModel} bleibt team-weit — ein MacBook Air M2 ist bei jedem
 * Kunden dasselbe Gerät. Leasingrate, Kaufpreis, Kostenart und Kreditor sind dagegen pro Kunde
 * verhandelt und leben hier. Genau eine Zeile je (Tenant, Modell).
 */
class AssetDeviceModelCost extends Model
{
    use TenantScopable;

    protected $table = 'asset_device_model_costs';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'device_model_id',
        'monthly_cost',
        'purchase_price',
        'cost_type_id',
        'vendor_id',
    ];

    protected $casts = [
        'monthly_cost'   => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function deviceModel(): BelongsTo
    {
        return $this->belongsTo(AssetDeviceModel::class, 'device_model_id');
    }

    public function costType(): BelongsTo
    {
        return $this->belongsTo(AssetCostType::class, 'cost_type_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_id');
    }

    /**
     * Monatlicher Kostenbeitrag: Leasingrate, sonst lineare AfA aus Kaufpreis ÷ Nutzungsdauer.
     * Die Nutzungsdauer kommt aus dem Modell — sie ist eine Eigenschaft der Hardware, nicht des
     * Vertrags, und bleibt deshalb team-weit.
     */
    public function monthlyCost(): ?float
    {
        if ($this->monthly_cost !== null) {
            return (float) $this->monthly_cost;
        }

        $months = (int) ($this->deviceModel?->depreciation_months ?? 0);

        if ($this->purchase_price !== null && $months > 0) {
            return round((float) $this->purchase_price / $months, 2);
        }

        return null;
    }
}
