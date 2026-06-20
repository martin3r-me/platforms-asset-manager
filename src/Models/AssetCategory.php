<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    protected $table = 'asset_categories';

    protected $fillable = [
        'key',
        'name',
        'icon',
        'is_synced',
        'default_depreciation_months',
        'sort_order',
    ];

    protected $casts = [
        'is_synced' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(AssetItem::class, 'category_id');
    }
}
