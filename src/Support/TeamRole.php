<?php

namespace Platform\AssetManager\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Platform\Core\Enums\StandardRole;

/**
 * Single source of truth for the module's "is this user owner/admin of their current team?" check.
 *
 * Callable both as a Gate ability closure (no $this — used by the `asset-manager.manage` gate that
 * fronts every write path, UI and MCP alike) and from the AuthorizesTeamRole trait (policies +
 * Livewire components). Keeps the module's proven current-team pivot lookup: the role lives on the
 * team_user pivot, and Core's HasRoleAccess::getUserRole reads $relation?->role (not ?->pivot?->role),
 * so reusing that trait would resolve null and deny everyone. Only the admin role SET is reused from
 * Core's enum (StandardRole::getAdminRoles() === ['owner', 'admin']).
 *
 * WARUM MEMOISIERT: Jede @can('asset-manager.manage')-Pruefung in einer Tabellenzeile landet hier,
 * und Laravels Gate cacht Callback-Ergebnisse nicht. Ein Aufruf kostet ZWEI Queries — die
 * Pivot-Abfrage unten und, verdeckt, `$user->currentTeam`: das ist im Core ein Accessor ohne
 * Attribute-Cache, der intern `Module::where('key', …)->first()` ausfuehrt. Auf einer Liste mit 50
 * Zeilen und 17 Gruppenzeilen (Kostenpositionen, gruppiert) waren das ~280 Queries fuer 140 mal
 * dieselbe Antwort. Der Core ist tabu, also wird hier gecacht statt dort.
 */
final class TeamRole
{
    /**
     * Ergebnis je User + aktuellem Team, fuer die Lebensdauer des Requests.
     *
     * @var array<string, bool>
     */
    private static array $resolved = [];

    public static function isOwnerOrAdmin(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Schluessel bewusst aus REINEN ATTRIBUTEN (kein $user->currentTeam): der Accessor ist
        // genau der teure Teil, den wir vermeiden wollen. `current_team_id` ist die Spalte, aus
        // der der Accessor sein Team ableitet — fuer denselben User liefert er innerhalb eines
        // Requests dasselbe Team, damit ist der Schluessel ausreichend fein.
        $key = ($user->getAuthIdentifier() ?? 'guest') . ':current:' . ($user->current_team_id ?? 'none');

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        $teamId = $user->currentTeam?->id;

        return self::$resolved[$key] = $teamId !== null && self::isOwnerOrAdminOf($user, (int) $teamId);
    }

    /**
     * Owner/Admin eines **bestimmten** Teams — für die MCP-Tools (#943).
     *
     * Ein Tool schreibt in das Team seines Kontexts (`core__team__switch` setzt nur das Session-Team,
     * nicht `users.current_team_id`). Geprüft werden muss deshalb die Rolle in GENAU diesem Team: wer
     * im eigenen currentTeam Admin, im gewechselten Team aber nur Member ist, darf dort nicht schreiben.
     */
    public static function isOwnerOrAdminOf(?Authenticatable $user, int $teamId): bool
    {
        if ($user === null) {
            return false;
        }

        $key = ($user->getAuthIdentifier() ?? 'guest') . ':team:' . $teamId;

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        $role = $user->teams()
            ->where('team_id', $teamId)
            ->first()?->pivot?->role;

        return self::$resolved[$key] = $role !== null
            && in_array($role, StandardRole::getAdminRoles(), true);
    }

    /**
     * Memoisierung verwerfen.
     *
     * Fuer Tests und fuer den seltenen Fall, dass sich die Rolle im selben Request aendert
     * (Team-Wechsel, Rollenzuweisung) — danach muss die naechste Pruefung neu aufloesen.
     */
    public static function flushResolved(): void
    {
        self::$resolved = [];
    }
}
