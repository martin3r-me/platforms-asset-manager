<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AssetCompany::class, 'company_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'cost_center_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(AssetEmployee::class, 'cost_center_id');
    }

    /** Anzeige: "2599 — RHEIN RUHR" bzw. nur Code. */
    public function getLabelAttribute(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : (string) $this->code;
    }
}
