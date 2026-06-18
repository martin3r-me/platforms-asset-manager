<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetLicenseSyncLog extends Model
{
    protected $table = 'asset_license_sync_logs';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'status',
        'skus_synced',
        'assignments_synced',
        'assignments_added',
        'assignments_removed',
        'error_message',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** Tenant (Kundenkontext), für den dieser Sync-Lauf erfolgte. */
    public function tenant()
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }
}
