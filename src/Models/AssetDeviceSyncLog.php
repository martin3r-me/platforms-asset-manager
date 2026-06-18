<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetDeviceSyncLog extends Model
{
    use TenantScopable;

    public $timestamps = false;

    protected $table = 'asset_device_sync_logs';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'status',
        'devices_synced',
        'devices_added',
        'devices_updated',
        'devices_removed',
        'error_message',
        'duration_ms',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), für den dieser Sync-Lauf erfolgte. */
    public function tenant()
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }
}
