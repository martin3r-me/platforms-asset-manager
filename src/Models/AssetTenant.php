<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant = vom Team verwalteter Kundenkontext (siehe CONTEXT.md / docs/adr/0003).
 * Microsoft-Anbindung ist optional (0..1 Connector je Tenant); ein Tenant ohne Connector
 * ist ein reiner Manuell-Kunde. Inventar referenziert diesen Tenant per tenant_id.
 */
class AssetTenant extends Model
{
    protected $table = 'asset_tenants';

    protected $fillable = [
        'team_id',
        'name',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Optionale Microsoft-/Intune-Anbindung dieses Tenants (0..1). */
    public function connector()
    {
        return $this->hasOne(AssetConnectorConfig::class, 'tenant_id');
    }
}
