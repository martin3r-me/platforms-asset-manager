<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetVendor extends Model
{
    protected $table = 'asset_vendors';

    protected $fillable = [
        'team_id',
        'name',
        'creditor_no',
        'notes',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function costLines()
    {
        return $this->hasMany(AssetCostLine::class, 'vendor_id');
    }
}
