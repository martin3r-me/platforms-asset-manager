<?php

namespace Platform\AssetManager\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;

/**
 * Einheitliche Team-Auflösung für alle Asset-Manager-Tools.
 *
 * Der MCP-Server setzt den aktiven Team-Kontext (core__context__GET / core__team__switch);
 * fällt das aus, greifen wir auf das currentTeam des Users zurück. Jedes Tool ist strikt
 * team-scoped — ohne Team gibt es kein Ergebnis (kein versehentliches Cross-Team-Leck).
 */
trait ResolvesTeam
{
    /**
     * Aktive Team-ID aus dem Kontext (oder null, wenn keins gesetzt ist).
     */
    protected function teamId(ToolContext $context): ?int
    {
        $teamId = $context->team?->id ?? null;
        if ($teamId) {
            return (int) $teamId;
        }

        $user = $context->user ?? null;
        $current = $user && method_exists($user, 'currentTeam') ? $user->currentTeam : null;

        return $current?->id ? (int) $current->id : null;
    }
}
