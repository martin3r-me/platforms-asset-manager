<?php

namespace Platform\AssetManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\ControllingContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Schützt die Controlling-Routen (Auswertungen, Stammdaten, Kosten-Import) gegen Aufruf, wenn das
 * Controlling für das aktive Team deaktiviert ist (ADR 0008). Statt 404 wird freundlich auf das
 * Dashboard umgeleitet — der Nutzer landet nicht auf einer toten Seite, etwa über alte Bookmarks.
 *
 * Wird in routes/web.php direkt per FQCN referenziert (kein Alias nötig, also keine Änderung an der
 * Host-bootstrap/app.php).
 */
class EnsureControllingEnabled
{
    public function __construct(protected ControllingContext $controlling)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $teamId = Auth::user()?->currentTeam?->id;

        if (!$teamId || !$this->controlling->enabledFor($teamId)) {
            return redirect()->route('asset-manager.dashboard');
        }

        return $next($request);
    }
}
