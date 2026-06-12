<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCostLine extends Model
{
    use SoftDeletes;

    protected $table = 'asset_cost_lines';

    protected $fillable = [
        'team_id',
        'cost_type_id',
        'vendor_id',
        'cost_center_id',
        'assignee_id',
        'asset_item_id',
        'label',
        'amount',
        'currency',
        'fx_rate',
        'frequency',
        'monthly_amount',
        'gl_account',
        'gl_contra_account',
        'debit_credit',
        'accounting_system',
        'distribution_factor',
        'source',
        'period_label',
        'valid_from',
        'valid_to',
        'active',
        'import_batch_id',
        'import_hash',
        'raw_data',
    ];

    protected $casts = [
        'amount'              => 'decimal:2',
        'fx_rate'             => 'decimal:6',
        'monthly_amount'      => 'decimal:2',
        'distribution_factor' => 'decimal:4',
        'active'              => 'boolean',
        'valid_from'          => 'date',
        'valid_to'            => 'date',
        'raw_data'            => 'array',
    ];

    /** Faktor zur Normalisierung auf einen Monat. */
    public const FREQUENCY_FACTORS = [
        'monthly'   => 1.0,
        'quarterly' => 1 / 3,
        'yearly'    => 1 / 12,
        'once'      => 0.0,
    ];

    protected static function booted(): void
    {
        // monthly_amount immer aus amount/fx_rate/frequency ableiten
        static::saving(function (AssetCostLine $line) {
            $line->monthly_amount = $line->computeMonthlyAmount();
        });
    }

    public function computeMonthlyAmount(): float
    {
        $factor = self::FREQUENCY_FACTORS[$this->frequency] ?? 1.0;
        $fx     = $this->fx_rate !== null ? (float) $this->fx_rate : 1.0;

        return round((float) $this->amount * $fx * $factor, 2);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function costType()
    {
        return $this->belongsTo(AssetCostType::class, 'cost_type_id');
    }

    public function vendor()
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_id');
    }

    public function costCenter()
    {
        return $this->belongsTo(AssetCostCenter::class, 'cost_center_id');
    }

    public function assignee()
    {
        return $this->belongsTo(AssetEmployee::class, 'assignee_id');
    }

    public function assetItem()
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }
}
