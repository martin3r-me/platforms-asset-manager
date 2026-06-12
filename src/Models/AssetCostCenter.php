<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCostCenter extends Model
{
    protected $table = 'asset_cost_centers';

    protected $fillable = [
        'team_id',
        'company_id',
        'code',
        'name',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function company()
    {
        return $this->belongsTo(AssetCompany::class, 'company_id');
    }

    public function costLines()
    {
        return $this->hasMany(AssetCostLine::class, 'cost_center_id');
    }

    public function employees()
    {
        return $this->hasMany(AssetEmployee::class, 'cost_center_id');
    }

    /** Anzeige: "2599 — RHEIN RUHR" bzw. nur Code. */
    public function getLabelAttribute(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : (string) $this->code;
    }
}
