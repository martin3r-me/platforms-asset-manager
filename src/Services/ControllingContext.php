<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetTeamSetting;

/**
 * Einzige Wahrheitsquelle für „ist die Controlling-/Kosten-Schicht für dieses Team aktiv?" (ADR 0008).
 *
 * Team-weit (nicht tenant-scoped, vgl. ADR 0003). Geteilt von UI (Sidebar, Dashboard, Settings),
 * Routen-Middleware ({@see \Platform\AssetManager\Http\Middleware\EnsureControllingEnabled}) und
 * — als Folgeschritt — den Kosten-MCP-Tools. Default ist **aus**: ein Team ohne Eintrag (oder vor der
 * Migration) hat kein Controlling. Request-lokal memoisiert.
 */
class ControllingContext
{
    /** @var array<int,bool> Request-lokaler Memo-Cache je Team. */
    protected static array $memo = [];

    public function enabledFor(int $teamId): bool
    {
        if (isset(self::$memo[$teamId])) {
            return self::$memo[$teamId];
        }

        // Defensive: vor der Migration (oder im Setup-/Console-Boot) existiert die Tabelle evtl. nicht
        // → sicher „aus" statt einer SQL-Exception.
        if (!Schema::hasTable('asset_team_settings')) {
            return self::$memo[$teamId] = false;
        }

        $enabled = (bool) AssetTeamSetting::where('team_id', $teamId)->value('controlling_enabled');

        return self::$memo[$teamId] = $enabled;
    }

    public function setEnabled(int $teamId, bool $enabled): void
    {
        AssetTeamSetting::updateOrCreate(
            ['team_id' => $teamId],
            ['controlling_enabled' => $enabled],
        );

        self::$memo[$teamId] = $enabled;
    }
}
