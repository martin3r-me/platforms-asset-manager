<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function items()
    {
        return $this->hasMany(AssetItem::class, 'category_id');
    }
}
