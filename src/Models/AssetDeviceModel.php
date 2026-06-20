<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Default-Kosten je Geräte-Modell (Hersteller + Modell). Der Intune-Sync legt die real
 * existierenden Modelle automatisch an; Preise werden im UI gepflegt. Geräte erben diese
 * Defaults, ein einzelnes Gerät kann sie überschreiben (Felder direkt an AssetDevice).
 */
class AssetDeviceModel extends Model
{
    protected $table = 'asset_device_models';

    protected $fillable = [
        'team_id',
        'manufacturer',
        'model',
        'monthly_cost',
        'purchase_price',
        'depreciation_months',
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

    public function costType(): BelongsTo
    {
        return $this->belongsTo(AssetCostType::class, 'cost_type_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_id');
    }

    /**
     * Einheitlicher Abgleich-Schlüssel (Hersteller|Modell), case-/whitespace-tolerant.
     * EINE Quelle der Wahrheit für Gerät ↔ Modell — von AssetDevice::deviceModel(),
     * CostAggregationService und der Geräte-Zählung genutzt, damit alle dieselbe Zuordnung sehen.
     */
    public static function normalizeKey(?string $manufacturer, ?string $model): string
    {
        return mb_strtolower(trim((string) $manufacturer)) . '|' . mb_strtolower(trim((string) $model));
    }
}
