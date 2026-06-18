<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetTenantSelection;

/**
 * Einzige Wahrheit für den „aktiven Tenant" (M3, siehe CONTEXT.md / docs/adr/0003).
 * Stateless; rein ID-basiert (kein Auth/Request-Zugriff) → aus Livewire, Services und dem Importer
 * gleichermaßen aufrufbar.
 *
 * Der aktive Tenant ist ein Arbeitsfilter, KEINE Zugriffsgrenze: er filtert ausschließlich die
 * Inventar-Sichten. Dashboard und Kosten-/Stammdaten ignorieren ihn (team-weit).
 */
class TenantContext
{
    /**
     * Aktiver Tenant für (User × Team) — gespeicherte Auswahl, sonst Default → erster → null.
     * Validiert, dass eine gespeicherte Auswahl noch zum Team gehört (sonst Fallback). Legt NICHTS
     * an: liefert null, wenn das Team gar keine Tenants hat (Lesepfad bleibt nebenwirkungsfrei).
     */
    public static function current(int $teamId, int $userId): ?int
    {
        $stored = AssetTenantSelection::query()
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->value('selected_tenant_id');

        if ($stored !== null && self::belongsToTeam((int) $stored, $teamId)) {
            return (int) $stored;
        }

        return self::fallbackTenantId($teamId);
    }

    /**
     * Auswahl dauerhaft setzen (updateOrCreate je User & Team). Schreibt nur, wenn der Tenant zum
     * Team gehört — ein veralteter Dropdown-Wert (z. B. nach Tenant-Löschen) wird still ignoriert.
     */
    public static function set(int $teamId, int $userId, int $tenantId): void
    {
        if (! self::belongsToTeam($tenantId, $teamId)) {
            return;
        }

        AssetTenantSelection::updateOrCreate(
            ['user_id' => $userId, 'team_id' => $teamId],
            ['selected_tenant_id' => $tenantId],
        );
    }

    /** Auswahl-Optionen fürs Dropdown (Default zuerst, dann alphabetisch). */
    public static function tenantsFor(int $teamId): Collection
    {
        return AssetTenant::query()
            ->where('team_id', $teamId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
    }

    /**
     * Garantierte, non-null Tenant-ID für Schreibpfade. Default-Tenant bevorzugt; sonst der erste
     * vorhandene; nur wenn das Team noch GAR KEINEN Tenant hat, wird ein „Standard"-Default angelegt
     * (kein Duplikat, wenn bereits Tenants existieren).
     */
    public static function defaultTenantId(int $teamId): int
    {
        $existing = self::fallbackTenantId($teamId);
        if ($existing !== null) {
            return $existing;
        }

        return (int) AssetTenant::create([
            'team_id'    => $teamId,
            'name'       => 'Standard',
            'is_default' => true,
        ])->id;
    }

    /**
     * Tenant für einen Schreibpfad auflösen: gespeicherte/abgeleitete Auswahl, sonst Default
     * (legt bei Bedarf an). Liefert garantiert eine non-null Tenant-ID.
     */
    public static function resolveForWrite(int $teamId, int $userId): int
    {
        return self::current($teamId, $userId) ?? self::defaultTenantId($teamId);
    }

    /** Default-Tenant des Teams, sonst erster vorhandener, sonst null (ohne anzulegen). */
    private static function fallbackTenantId(int $teamId): ?int
    {
        $default = AssetTenant::query()
            ->where('team_id', $teamId)
            ->where('is_default', true)
            ->value('id');

        if ($default !== null) {
            return (int) $default;
        }

        $first = AssetTenant::query()
            ->where('team_id', $teamId)
            ->orderBy('id')
            ->value('id');

        return $first !== null ? (int) $first : null;
    }

    private static function belongsToTeam(int $tenantId, int $teamId): bool
    {
        return AssetTenant::query()
            ->whereKey($tenantId)
            ->where('team_id', $teamId)
            ->exists();
    }
}
