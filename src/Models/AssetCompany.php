<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCompany extends Model
{
    protected $table = 'asset_companies';

    protected $fillable = [
        'team_id',
        'key',
        'name',
        'sort_order',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function costCenters()
    {
        return $this->hasMany(AssetCostCenter::class, 'company_id');
    }
}
