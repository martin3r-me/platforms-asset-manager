<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\TenantContext;

/**
 * Setzt die **zwei** Grenzen einer Detail-/Show-Seite durch (docs/adr/0016):
 *
 *  - **Team** → `403`. Die äußere Sicherheitsgrenze. Ein fremdes Team ist eine echte
 *    Rechteverletzung, und dass die Ressource existiert, ist dort keine schützenswerte Information.
 *  - **Tenant** → `404`. Die innere Zugriffsgrenze. Bewusst **nicht** 403: ein 403 bestätigt, dass
 *    der Datensatz existiert — bei getrennten Kundenkontexten ist schon das zu viel.
 *
 * Warum explizit, obwohl der Global Scope beim Route-Model-Binding ohnehin 404 liefert (die Query
 * findet die Zeile nicht und Laravel wirft `ModelNotFoundException`): damit die Grenze **im Code
 * steht** und nicht davon abhängt, dass ein Binding verwendet wird. Pfade, die ein Modell anders
 * laden (Relation, Cache, Snapshot, `withoutTenantScope()` weiter oben), hätten den Schutz sonst
 * stillschweigend nicht.
 */
trait GuardsTenantBoundary
{
    /**
     * Team- und Tenant-Grenze für einen geladenen Datensatz prüfen.
     *
     * @param  Model|null  $model  null → 404 (nicht gefunden ist ununterscheidbar von nicht sichtbar)
     */
    protected function assertTenantBoundary(?Model $model): void
    {
        abort_if($model === null, 404);

        $teamId = Auth::user()?->currentTeam?->id;

        abort_unless($teamId !== null && (int) $model->team_id === (int) $teamId, 403);

        // Kein tenant_id am Modell (z. B. der globale Kategorie-Katalog) → nur Team-Grenze.
        if (! array_key_exists('tenant_id', $model->getAttributes())) {
            return;
        }

        $activeTenantId = TenantContext::scopeTenantId();

        // Kein auflösbarer Tenant: nicht raten. Alle fachlichen Tabellen tragen tenant_id NOT NULL,
        // ein Team ohne Tenants hat also auch keine Daten — es gibt hier nichts zu schützen.
        if ($activeTenantId === null) {
            return;
        }

        abort_unless((int) $model->tenant_id === $activeTenantId, 404);
    }
}
