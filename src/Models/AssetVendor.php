<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetVendor extends Model
{
    protected $table = 'asset_vendors';

    protected $fillable = [
        'team_id',
        'name',
        'creditor_no',
        'notes',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'vendor_id');
    }
}
