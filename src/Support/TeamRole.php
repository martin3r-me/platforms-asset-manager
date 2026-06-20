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
 */
final class TeamRole
{
    public static function isOwnerOrAdmin(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $role = $user->teams()
            ->where('team_id', $user->currentTeam?->id)
            ->first()?->pivot?->role;

        return $role !== null && in_array($role, StandardRole::getAdminRoles(), true);
    }
}
