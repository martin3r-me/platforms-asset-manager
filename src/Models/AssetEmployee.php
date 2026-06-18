<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetEmployee extends Model
{
    protected $table = 'asset_employees';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'user_principal_name',
        'display_name',
        'email',
        'department',
        'cost_center',
        'cost_center_id',
        'job_title',
        'is_active',
        'account_type',
        'source',
        'graph_id',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'raw_data'  => 'array',
        'synced_at' => 'datetime',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), zu dem dieser Mitarbeiter gehört. */
    public function tenant()
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function costCenter()
    {
        return $this->belongsTo(AssetCostCenter::class, 'cost_center_id');
    }

    public function costLines()
    {
        return $this->hasMany(AssetCostLine::class, 'assignee_id');
    }

    /** True wenn Funktionskonto (kein echter Mitarbeiter). */
    public function isFunctionAccount(): bool
    {
        return $this->account_type === 'function';
    }

    public function items()
    {
        return $this->hasMany(AssetItem::class, 'assignee_id')
            ->whereNull('asset_items.deleted_at');
    }

    public function licenses()
    {
        return $this->hasMany(AssetUserLicense::class, 'user_principal_name', 'user_principal_name')
            ->where('asset_user_licenses.team_id', $this->team_id);
    }

    public function devices()
    {
        return $this->hasMany(AssetDevice::class, 'user_principal_name', 'user_principal_name')
            ->where('asset_devices.team_id', $this->team_id);
    }

    /**
     * Anzeigename (Fallback: UPN).
     */
    public function getNameAttribute(): string
    {
        return $this->display_name ?: $this->user_principal_name;
    }

    /**
     * Initialen für Avatar.
     */
    public function initials(): string
    {
        $name = trim($this->display_name ?: $this->user_principal_name);
        if (!$name) return '?';
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }
        return strtoupper(mb_substr($name, 0, 2));
    }
}
