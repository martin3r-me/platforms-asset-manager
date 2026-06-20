<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCompany extends Model
{
    protected $table = 'asset_companies';

    protected $fillable = [
        'team_id',
        'key',
        'name',
        'sort_order',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(AssetCostCenter::class, 'company_id');
    }
}
