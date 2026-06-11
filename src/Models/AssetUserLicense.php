<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetUserLicense extends Model
{
    protected $table = 'asset_user_licenses';

    protected $fillable = [
        'team_id',
        'sku_id',
        'sku_part_number',
        'user_principal_name',
        'display_name',
        'assigned_at',
        'raw_data',
    ];

    protected $casts = [
        'raw_data'    => 'array',
        'assigned_at' => 'datetime',
    ];

    public function sku()
    {
        return $this->hasOne(AssetLicenseSku::class, 'sku_id', 'sku_id')
            ->where('team_id', $this->team_id);
    }
}
