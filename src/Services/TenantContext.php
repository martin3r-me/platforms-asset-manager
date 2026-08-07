<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetTenantSelection;

/**
 * Einzige Wahrheit für den „aktiven Tenant" (siehe CONTEXT.md / docs/adr/0016).
 *
 * Der aktive Tenant ist eine **Zugriffsgrenze**: er wird zentral über den {@see \Platform\AssetManager\Support\TenantScope}
 * auf jede Query angewandt, nicht per Aufruf an jeder Stelle. Die ID-basierten Methoden
 * (`current`, `set`, `resolveForWrite`, …) bleiben stateless und aus Livewire, Services und dem
 * Importer gleichermaßen aufrufbar; darüber liegt die Auflösung für den Scope.
 */
class TenantContext
{
    /**
     * Erzwungener Tenant für den Global Scope, unabhängig von der User-Auswahl. `false` = nicht
     * gesetzt, `null` = ausdrücklich tenant-übergreifend (Scope filtert nicht).
     *
     * @var int|null|false
     */
    private static int|null|false $override = false;

    /** Memoisierte Auflösung je (Team × User) — pro Request, in Queue-Workern nie gefüllt. */
    private static array $resolved = [];

    /** Läuft gerade eine Auflösung? Verhindert Rekursion über die eigenen Lookup-Queries. */
    private static bool $resolving = false;

    /**
     * Tenant-ID, auf die der Global Scope filtert — oder null, wenn nicht gefiltert werden soll.
     *
     * Auflösungsreihenfolge:
     *  1. expliziter Override (Job/Command läuft „für Tenant X" bzw. ausdrücklich über alle),
     *  2. Auswahl des angemeldeten Users im aktuellen Team,
     *  3. null — kein Auth-Kontext (Queue-Worker, Console, Boot).
     *
     * Fall 3 filtert bewusst NICHT: dort gibt es keinen User, dessen Grenze zu wahren wäre, und die
     * Sync-Pfade arbeiten legitim über alle Tenants. Sie sind zusätzlich explizit über
     * `withoutTenantScope()` ausgenommen, damit die Absicht im Code steht und nicht nur im Zufall,
     * dass gerade kein User eingeloggt ist.
     *
     * „Alle Tenants" wird hier NICHT berücksichtigt: die Präferenz ist ein Opt-in der jeweiligen
     * Sicht (Dashboard/Auswertungen rufen `withoutTenantScope()`), nicht ein globaler Zustand. So
     * fällt jede andere Sicht automatisch auf den zuletzt gewählten konkreten Tenant zurück.
     */
    public static function scopeTenantId(): ?int
    {
        if (self::$override !== false) {
            return self::$override;
        }

        // Rekursionsbremse: die Auflösung selbst fragt asset_tenants/asset_tenant_selections ab. Beide
        // Modelle tragen bewusst KEIN TenantScopable (sie sind der Tenant bzw. dessen Auswahl, nicht
        // tenant-gebundene Daten) — käme dort je eines hinzu, riefe sein Scope hier erneut herein.
        if (self::$resolving) {
            return null;
        }

        if (! Auth::hasUser()) {
            return null;
        }

        $user   = Auth::user();
        $teamId = $user->currentTeam->id ?? null;

        if (! $teamId) {
            return null;
        }

        $key = $teamId . ':' . $user->getAuthIdentifier();

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        self::$resolving = true;

        try {
            return self::$resolved[$key] = self::current((int) $teamId, (int) $user->getAuthIdentifier());
        } finally {
            self::$resolving = false;
        }
    }

    /**
     * Callback mit erzwungenem Tenant-Kontext ausführen — für Jobs und Commands, die pro Tenant
     * arbeiten. `null` heißt ausdrücklich „über alle Tenants".
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runFor(?int $tenantId, callable $callback): mixed
    {
        $previous       = self::$override;
        self::$override = $tenantId;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    /**
     * Tenant-Kontext für den **Rest des Requests** setzen — ohne Callback.
     *
     * Für die MCP-Tools: ein Tool-Aufruf ist ein Request, und der Tool-Body ist eine lange
     * Methode, die sich nicht sinnvoll in einen Callback wickeln lässt. Jedes Tool setzt den
     * Kontext zu Beginn selbst, deshalb kann ein haftender Override höchstens vom nächsten Tool
     * überschrieben werden — nie unbemerkt weiterwirken.
     *
     * Im Request-/Livewire-Pfad NICHT verwenden: dort löst {@see scopeTenantId()} den Tenant aus der
     * Auswahl des Users auf, und ein Override würde diese Auflösung stumm übersteuern. Für begrenzte
     * Abschnitte {@see runFor()} nehmen.
     */
    public static function forceTenant(?int $tenantId): void
    {
        self::$override = $tenantId;
    }

    /** Override aufheben — zurück zur Auflösung über die User-Auswahl. */
    public static function clearOverride(): void
    {
        self::$override = false;
    }

    /** Memoisierung verwerfen — nach jedem Wechsel der Auswahl nötig. */
    public static function forget(): void
    {
        self::$resolved = [];
    }

    /**
     * Hat der User „Alle Tenants" gewählt? Nur Dashboard und Auswertungen dürfen das befolgen; alle
     * anderen Sichten ignorieren die Präferenz und bleiben auf dem zuletzt gewählten Tenant.
     */
    public static function prefersAllTenants(int $teamId, int $userId): bool
    {
        return (bool) AssetTenantSelection::query()
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->value('all_tenants');
    }

    /** „Alle Tenants" setzen/aufheben. `selected_tenant_id` bleibt unberührt (letzter konkreter Tenant). */
    public static function setAllTenants(int $teamId, int $userId, bool $enabled = true): void
    {
        AssetTenantSelection::updateOrCreate(
            ['user_id' => $userId, 'team_id' => $teamId],
            ['all_tenants' => $enabled],
        );

        self::forget();
    }
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
     *
     * Hebt „Alle Tenants" auf: die Wahl eines konkreten Tenants ist die eindeutigere Absicht.
     */
    public static function set(int $teamId, int $userId, int $tenantId): void
    {
        if (! self::belongsToTeam($tenantId, $teamId)) {
            return;
        }

        AssetTenantSelection::updateOrCreate(
            ['user_id' => $userId, 'team_id' => $teamId],
            ['selected_tenant_id' => $tenantId, 'all_tenants' => false],
        );

        self::forget();
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
