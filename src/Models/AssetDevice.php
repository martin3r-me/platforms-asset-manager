<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDevice extends Model
{
    protected $table = 'asset_devices';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'intune_id',
        'source',
        'device_name',
        'user_display_name',
        'user_principal_name',
        'operating_system',
        'os_version',
        'compliance_state',
        'management_state',
        'device_type',
        'manufacturer',
        'model',
        'serial_number',
        'enrolled_at',
        'last_check_in_at',
        'raw_data',
    ];

    protected $casts = [
        'enrolled_at'      => 'datetime',
        'last_check_in_at' => 'datetime',
        'raw_data'         => 'array',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function isCompliant(): bool
    {
        return $this->compliance_state === 'compliant';
    }

    public function complianceBadgeColor(): string
    {
        return match($this->compliance_state) {
            'compliant'      => 'emerald',
            'noncompliant'   => 'red',
            'inGracePeriod'  => 'amber',
            'error'          => 'red',
            'conflict'       => 'orange',
            default          => 'gray',
        };
    }

    public function complianceLabel(): string
    {
        return match($this->compliance_state) {
            'compliant'      => 'Konform',
            'noncompliant'   => 'Nicht konform',
            'inGracePeriod'  => 'Karenzzeit',
            'error'          => 'Fehler',
            'conflict'       => 'Konflikt',
            default          => 'Unbekannt',
        };
    }
}
