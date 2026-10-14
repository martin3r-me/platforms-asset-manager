<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Ein belegter Cloud-Telefonie-Platz (RingCentral), aus der Benutzerliste des Anbieters
 * (Dev-Issue #931). Snapshot je Upload: die Liste wird beim Anbieter gepflegt, hier nur gespiegelt
 * und den Asset-Trägern zugeordnet. Die Vodafone-Rechnung nennt lediglich die **Menge** der Plätze —
 * erst diese Tabelle sagt, wer sie belegt, und damit, auf welche Kostenstelle ein Platz gebucht wird.
 */
class AssetTelephonySeat extends Model
{
    use TenantScopable;

    protected $table = 'asset_telephony_seats';

    public const PROVIDER_RINGCENTRAL = 'ringcentral';

    /** Abgleich über E-Mail-Adresse bzw. UPN — eindeutig. */
    public const MATCH_EXACT = 'exact';

    /** Abgleich über den normalisierten Namen — Rückfall, wenn die Adresse beim Anbieter abweicht. */
    public const MATCH_NAME = 'name';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'provider',
        'external_id',
        'email',
        'display_name',
        'extension',
        'direct_number',
        'device',
        'status',
        'holder_id',
        'match',
        'imported_at',
        'raw_data',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'raw_data'    => 'array',
    ];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(AssetHolder::class, 'holder_id');
    }

    /** RingCentral meldet „Aktiviert"; alles andere ist ein bezahlter, aber ungenutzter Platz. */
    public function isActive(): bool
    {
        return mb_strtolower(trim((string) $this->status)) === 'aktiviert';
    }
}
