<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetDeviceEvent extends Model
{
    use TenantScopable;

    protected $table = 'asset_device_events';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'asset_device_id',
        'event_type',
        'description',
        'old_value',
        'new_value',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AssetDevice::class, 'asset_device_id');
    }

    /** Tenant (Kundenkontext), zu dem dieses Geräte-Event gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function eventLabel(): string
    {
        return match ($this->event_type) {
            'created'            => 'Erstmals erfasst',
            'owner_changed'      => 'Besitzer geändert',
            'compliance_changed' => 'Compliance geändert',
            'os_changed'         => 'OS aktualisiert',
            default              => $this->description ?: $this->event_type,
        };
    }

    /** Nur Farbfamilien, die im Modul bereits als Klassen vorkommen (Tailwind-Build). */
    public function eventColor(): string
    {
        return match ($this->event_type) {
            'created'            => 'violet',
            'owner_changed'      => 'indigo',
            'compliance_changed' => 'amber',
            'os_changed'         => 'emerald',
            default              => 'gray',
        };
    }
}
