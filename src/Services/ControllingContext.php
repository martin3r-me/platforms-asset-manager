<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetTeamSetting;

/**
 * Einzige Wahrheitsquelle für „ist die Controlling-/Kosten-Schicht aktiv?" (ADR 0008).
 *
 * Je **Team × Tenant** (tenant-gebunden seit docs/adr/0016): derselbe Betreuer kann für einen Kunden
 * Kosten führen und für den nächsten nur das Inventar. Geteilt von UI (Sidebar, Dashboard, Settings),
 * Routen-Middleware ({@see \Platform\AssetManager\Http\Middleware\EnsureControllingEnabled}) und den
 * Kosten-MCP-Tools. Default ist **aus**: ohne Eintrag (oder vor der Migration) kein Controlling.
 *
 * Fragt die Einstellung bewusst **explizit** per Tenant ab (`withoutTenantScope()->forTenant()`) statt
 * sich auf den Global Scope zu verlassen — so verhält sich der Schalter in Console-Commands und
 * Queue-Jobs genauso wie im Request, wo ein aktiver Tenant auflösbar ist.
 */
class ControllingContext
{
    /** @var array<string,bool> Request-lokaler Memo-Cache je Team × Tenant. */
    protected static array $memo = [];

    public function enabledFor(int $teamId, ?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::scopeTenantId();
        $key = $teamId . ':' . ($tenantId ?? 'all');

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        // Defensive: vor der Migration (oder im Setup-/Console-Boot) existiert die Tabelle evtl. nicht
        // → sicher „aus" statt einer SQL-Exception.
        if (! Schema::hasTable('asset_team_settings')) {
            return self::$memo[$key] = false;
        }

        $query = AssetTeamSetting::query()->withoutTenantScope()->where('team_id', $teamId);

        // Ohne auflösbaren Tenant (Console/Job über alle Tenants): „an", sobald IRGENDEIN Tenant des
        // Teams Controlling nutzt — sonst würden tenant-übergreifende Läufe die Kostenpfade komplett
        // überspringen, obwohl es Kostendaten gibt.
        $enabled = $tenantId === null
            ? $query->where('controlling_enabled', true)->exists()
            : (bool) $query->where('tenant_id', $tenantId)->value('controlling_enabled');

        return self::$memo[$key] = $enabled;
    }

    public function setEnabled(int $teamId, bool $enabled, ?int $tenantId = null): void
    {
        // MUSS denselben Tenant treffen wie enabledFor(): sonst liest die Seite den aktiven Tenant,
        // schreibt aber den Default — der Schalter würde scheinbar nicht reagieren.
        $tenantId ??= TenantContext::scopeTenantId() ?? TenantContext::defaultTenantId($teamId);

        AssetTeamSetting::withoutTenantScope()->updateOrCreate(
            ['team_id' => $teamId, 'tenant_id' => $tenantId],
            ['controlling_enabled' => $enabled],
        );

        self::$memo[$teamId . ':' . $tenantId] = $enabled;
        unset(self::$memo[$teamId . ':all']);
    }

    /** Memo verwerfen — nach einem Tenant-Wechsel innerhalb desselben Requests. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
