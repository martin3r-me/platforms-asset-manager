<?php

namespace Platform\AssetManager\Tools\Holders;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\BillingExclusionService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Viele Asset-Träger auf einmal von der Weiterberechnung ausnehmen — oder die Ausnahme aufheben
 * (ADR 0024).
 *
 * Bewusst dünn: die gesamte Fachlogik liegt im {@see BillingExclusionService}, den sich dieses Tool
 * mit der Trägerliste und der Detailseite teilt. Der Grund ist auch hier Pflicht — ein Kanal, über
 * den man Ausnahmen ohne Begründung setzen kann, hebelt genau die Nachvollziehbarkeit aus, um die
 * es bei dieser Entscheidung geht.
 *
 * `dry_run=true` liefert die Wirkung auf jede aktive Kundenrechnung, bevor etwas geschrieben wird:
 * Menge vorher, Menge nachher, Betrag. Das ist der empfohlene erste Aufruf — die Zahl gehört in die
 * Antwort an den Menschen, nicht erst in die Rechnung.
 */
class BulkSetBillingExclusionTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.holders.billing-exclusion.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/holders/billing-exclusion/bulk - Nimmt Asset-Träger von der '
            . 'Weiterberechnung aus (ADR 0024) oder hebt die Ausnahme auf. Adressierung über '
            . 'holder_ids[] und/oder upns[]. excluded=true nimmt aus (reason ist dann PFLICHT und '
            . 'erscheint als Ausschlussgrund in jeder künftigen Rechnungsvorschau), excluded=false '
            . 'hebt auf. dry_run=true liefert nur die Wirkung: je aktivem Abrechnungsprofil die '
            . 'abgerechnete Menge vorher/nachher und den Betrag. Ändert NICHT is_active — der Status '
            . 'kommt aus Entra und würde beim nächsten Sync überschrieben. Für ganze Fallgruppen '
            . '(Externe, Test-/technische Konten) ist die Regel am Abrechnungsprofil der bessere Weg.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'holder_ids' => ['type' => 'array', 'description' => 'Asset-Träger-IDs.', 'items' => ['type' => 'integer']],
                'upns'       => ['type' => 'array', 'description' => 'User Principal Names (alternativ oder zusätzlich zu holder_ids).', 'items' => ['type' => 'string']],
                'excluded'   => ['type' => 'boolean', 'description' => 'true = von der Weiterberechnung ausnehmen, false = Ausnahme aufheben.'],
                'reason'     => ['type' => 'string', 'description' => 'Grund der Ausnahme. Pflicht bei excluded=true, max. ' . BillingExclusionService::REASON_MAX . ' Zeichen.'],
                'dry_run'    => ['type' => 'boolean', 'description' => 'Nur Vorschau samt Mengen-/Geldwirkung, nicht schreiben (Default false).'],
            ], $this->tenantSchemaProperty()),
            'required' => ['excluded'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016) vor jeder Query setzen.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Schreibrechte (ADR 0004): dieselbe Grenze wie im UI, unabhängig vom Kanal.
            if (! Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $ids = array_map('intval', $arguments['holder_ids'] ?? []);

            // UPNs auf IDs auflösen — team- und tenant-gebunden, damit ein fremder Name nicht
            // versehentlich trifft. Unauffindbare UPNs fallen still heraus und tauchen als
            // Differenz in `summary.total` gegen die Eingabe auf.
            if (! empty($arguments['upns'])) {
                $ids = array_merge($ids, AssetHolder::where('team_id', $teamId)
                    ->whereIn('user_principal_name', (array) $arguments['upns'])
                    ->pluck('id')
                    ->all());
            }

            // Urheber der Ausnahme. `ToolContext::$user` ist ein Authenticatable, dem die Kennung
            // erst die konkrete Klasse gibt — deshalb defensiv, statt `->id` blind anzunehmen.
            $userId = $context->user->id ?? null;

            $result = app(BillingExclusionService::class)->setExclusion(
                $teamId,
                $tenantId,
                $ids,
                (bool) $arguments['excluded'],
                $arguments['reason'] ?? null,
                $userId !== null ? (int) $userId : null,
                (bool) ($arguments['dry_run'] ?? false),
            );

            if (! $result['ok']) {
                return ToolResult::error($result['code'] ?? 'VALIDATION_ERROR', $result['message']);
            }

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category'              => 'mutation',
            'tags'                  => ['asset-manager', 'holders', 'billing', 'bulk'],
            'read_only'             => false,
            'requires_auth'         => true,
            'risk_level'            => 'write',
            'confirmation_required' => true,
        ];
    }
}
