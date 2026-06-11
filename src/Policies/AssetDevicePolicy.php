<?php

namespace Platform\AssetManager\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class AssetDevicePolicy
{
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
        return $this->isOwnerOrAdmin($user);
    }

    public function manageConnector(Authenticatable $user): bool
    {
        return $this->isOwnerOrAdmin($user);
    }

    private function isOwnerOrAdmin(Authenticatable $user): bool
    {
        $role = $user->teams()
            ->where('team_id', $user->currentTeam?->id)
            ->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin']);
    }
}
