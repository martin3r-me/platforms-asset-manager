<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Platform\AssetManager\Support\TeamRole;

/**
 * Convenience wrapper around the "is this user an owner/admin of their current team?" check for
 * policies and Livewire components.
 *
 * The actual logic lives in {@see TeamRole::isOwnerOrAdmin()} so the exact same rule backs the
 * `asset-manager.manage` Gate ability (which has no $this and so cannot use a trait). This trait
 * just keeps the terser, $this-style call site the policies/components already use.
 */
trait AuthorizesTeamRole
{
    protected function isTeamOwnerOrAdmin(Authenticatable $user): bool
    {
        return TeamRole::isOwnerOrAdmin($user);
    }
}
