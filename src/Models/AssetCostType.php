<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCostType extends Model
{
    /**
     * Erlaubte Werte von `aggregation_source` — die Quelle, aus der eine Kostenart ihren Pivot-Wert
     * zieht. Genau EINE Quelle pro Kostenart (Doppelzählungs-Invariante, ADR 0001). Vorher als
     * Magic-Strings über CostAggregationService/MasterData/Tools/CostBootstrap verstreut.
     */
    public const SOURCE_COST_LINE    = 'cost_line';
    public const SOURCE_HARDWARE_AFA = 'hardware_afa';
    public const SOURCE_MS_LICENSE   = 'ms_license';
    public const SOURCE_ASSET_DEVICE = 'asset_device';

    /** Alle gültigen aggregation_source-Werte (für Rule::in(...) / Validierung). */
    public const SOURCES = [
        self::SOURCE_COST_LINE,
        self::SOURCE_HARDWARE_AFA,
        self::SOURCE_MS_LICENSE,
        self::SOURCE_ASSET_DEVICE,
    ];

    protected $table = 'asset_cost_types';

    protected $fillable = [
        'team_id',
        'key',
        'name',
        'sort_order',
        'vendor_default_id',
        'system_default',
        'frequency_default',
        'is_per_employee',
        'aggregation_source',
        'allow_negative',
    ];

    protected $casts = [
        'is_per_employee' => 'boolean',
        'allow_negative'  => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function vendorDefault(): BelongsTo
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_default_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'cost_type_id');
    }
}
