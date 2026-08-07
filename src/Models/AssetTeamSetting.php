<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modul-Einstellungen je **Team × Tenant** (ADR 0008, tenant-gebunden seit docs/adr/0016).
 *
 * Ursprünglich team-weit — konsistent damit, dass das Kostenmodell team-weit war. Mit dem
 * tenant-gebundenen Kostenmodell ist auch der Controlling-Schalter tenant-eigen: derselbe Betreuer
 * kann für einen Kunden Kosten führen und für den nächsten nur das Inventar. Der Bestand wurde je
 * Tenant kopiert, also ändert sich für vorhandene Teams nichts.
 *
 * Zugriff bevorzugt über {@see \Platform\AssetManager\Services\ControllingContext}.
 */
class AssetTeamSetting extends Model
{
    use \Platform\AssetManager\Concerns\TenantScopable;

    protected $table = 'asset_team_settings';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'controlling_enabled',
        // Klassifizierungsregeln für Asset-Träger je Tenant (ADR 0017): Präfix-/Muster-Listen,
        // damit keine Kundenkonvention im Code landet.
        'holder_type_rules',
    ];

    protected $casts = [
        'controlling_enabled' => 'boolean',
        'holder_type_rules'   => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }
}
