<?php

namespace Platform\AssetManager\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;

class AssetDevicePolicy
{
    use AuthorizesTeamRole;

    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, \Platform\AssetManager\Models\AssetDevice $device): bool
    {
        return $device->team_id === $user->currentTeam?->id;
    }

    public function viewLicenses(Authenticatable $user): bool
    {
        return true;
    }

    public function sync(Authenticatable $user): bool
    {
        return $this->isTeamOwnerOrAdmin($user);
    }

    public function manageConnector(Authenticatable $user): bool
    {
        return $this->isTeamOwnerOrAdmin($user);
    }
}
