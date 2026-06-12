<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCostType extends Model
{
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

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function vendorDefault()
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_default_id');
    }

    public function costLines()
    {
        return $this->hasMany(AssetCostLine::class, 'cost_type_id');
    }
}
