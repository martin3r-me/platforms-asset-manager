<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-Quelle eines Geräts (ADR 0009): welche Quelle (Intune-MDM, Apple Business Manager …) dieses
 * physische [[Gerät]] kennt, unter welcher external_id und wann zuletzt gesehen. Ein Gerät kann mehrere
 * Quellen haben (z. B. zugleich in Intune verwaltet UND in ABM als Eigentum geführt) — sie werden über
 * die Seriennummer (ADR 0006) zum selben Gerät gemerged.
 */
class AssetDeviceSource extends Model
{
    protected $table = 'asset_device_sources';

    public const PROVIDER_INTUNE = 'intune';
    public const PROVIDER_APPLE_BUSINESS_MANAGER = 'apple_business_manager';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'asset_device_id',
        'provider',
        'external_id',
        'serial_number',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AssetDevice::class, 'asset_device_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }
}
