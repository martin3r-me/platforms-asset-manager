<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modul-Einstellungen je Team (siehe ADR 0008).
 *
 * Aktuell nur controlling_enabled (Kosten-/Controlling-Schicht an/aus). Team-weit, nicht
 * tenant-scoped — konsistent damit, dass das Kostenmodell team-weit ist (ADR 0003).
 * Zugriff bevorzugt über {@see \Platform\AssetManager\Services\ControllingContext}.
 */
class AssetTeamSetting extends Model
{
    protected $table = 'asset_team_settings';

    protected $fillable = [
        'team_id',
        'controlling_enabled',
    ];

    protected $casts = [
        'controlling_enabled' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }
}
