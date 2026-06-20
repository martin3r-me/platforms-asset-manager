<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth for resolving the authenticated user's current-team id —
 * previously copy-pasted identically across ~11 Livewire components.
 *
 * Mirrors the established inline form (Auth::user()->currentTeam->id) exactly, so swapping a
 * component's private teamId() helper for this trait is behaviour-neutral. Sits next to
 * AuthorizesTeamRole (the owner/admin check) as the second shared team primitive.
 */
trait ResolvesCurrentTeam
{
    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }
}
