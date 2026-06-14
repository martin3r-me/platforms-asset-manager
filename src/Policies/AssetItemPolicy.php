<?php

namespace Platform\AssetManager\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Models\AssetItem;

class AssetItemPolicy
{
    use AuthorizesTeamRole;

    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, AssetItem $item): bool
    {
        return $item->team_id === $user->currentTeam?->id;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, AssetItem $item): bool
    {
        return $item->team_id === $user->currentTeam?->id;
    }

    public function delete(Authenticatable $user, AssetItem $item): bool
    {
        if ($item->team_id !== $user->currentTeam?->id) return false;
        // Intune-Items dürfen nicht gelöscht werden (kommen automatisch zurück)
        if ($item->source === 'intune') return false;
        return $this->isTeamOwnerOrAdmin($user);
    }
}
