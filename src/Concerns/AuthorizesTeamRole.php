<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Platform\Core\Enums\StandardRole;

/**
 * Single source of truth for the "is this user an owner/admin of their current team?" check
 * that was previously copied inline across policies and Livewire components.
 *
 * Keeps the module's proven current-team pivot lookup: the role lives on the team_user pivot,
 * and Core's HasRoleAccess::getUserRole reads $relation?->role (not ?->pivot?->role), so reusing
 * that trait would resolve null and deny everyone. Only the admin role SET is reused from Core's
 * enum (StandardRole::getAdminRoles() === ['owner', 'admin']) instead of being hardcoded.
 */
trait AuthorizesTeamRole
{
    protected function isTeamOwnerOrAdmin(Authenticatable $user): bool
    {
        $role = $user->teams()
            ->where('team_id', $user->currentTeam?->id)
            ->first()?->pivot?->role;

        return $role !== null && in_array($role, StandardRole::getAdminRoles(), true);
    }
}
