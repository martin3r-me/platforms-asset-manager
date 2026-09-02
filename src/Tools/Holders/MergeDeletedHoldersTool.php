<?php

namespace Platform\AssetManager\Tools\Holders;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Services\HolderMergeService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Räumt Träger-Dubletten auf, die durch gelöschte Entra-Konten entstanden sind (ADR 0023).
 *
 * Es gibt den gleichnamigen Console-Befehl `asset-manager:merge-deleted-holders`; dieses Tool ist
 * derselbe Service über den Connector — für Umgebungen, in denen niemand eine Shell auf dem Server
 * hat. Beide teilen sich den {@see HolderMergeService}, es gibt also keine zweite Wahrheit.
 *
 * `dry_run` ist der Normalfall und der empfohlene erste Aufruf: der scharfe Lauf hängt Zuordnungen
 * um und löscht Datensätze.
 */
class MergeDeletedHoldersTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.holders.merge-deleted.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/holders/merge-deleted - Führt Asset-Träger zusammen, die durch '
            . 'ein gelöschtes Entra-Konto doppelt entstanden sind. Entra stellt beim Löschen die '
            . 'objectId vor die UPN; weil die UPN der Identitätsanker ist (ADR 0017), entstand daraus '
            . 'ein zweiter Träger. Der Lauf hängt alle Verweise (Inventar, Kostenzeilen, Zuordnungen, '
            . 'Ausgaben, Geräte und Lizenzen) auf den bestehenden Träger um, füllt dort leere Felder '
            . 'aus der Dublette und entfernt erst dann den leeren Datensatz. Gibt es kein Gegenstück, '
            . 'wird nichts gelöscht — die UPN wird nur bereinigt und der Träger stillgelegt. '
            . 'dry_run=true (Default) zeigt nur, was passieren würde.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Nur Vorschau, nichts ändern. Default true — der scharfe Lauf muss ausdrücklich angefordert werden.',
                ],
            ], $this->tenantSchemaProperty()),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            if (! Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            // Default true: dieser Lauf löscht Datensätze. Wer ihn scharf will, sagt es ausdrücklich.
            $dryRun = ! array_key_exists('dry_run', $arguments) || (bool) $arguments['dry_run'];

            $result = app(HolderMergeService::class)->mergeDeletedUpnHolders($teamId, $tenantId, $dryRun);

            $result['message'] = $result['results'] === []
                ? 'Keine Papierkorb-Dubletten gefunden.'
                : ($dryRun
                    ? "Vorschau: {$result['merged']} würden zusammengeführt, {$result['renamed']} nur umbenannt. Nichts geändert."
                    : "{$result['merged']} zusammengeführt, {$result['renamed']} umbenannt und stillgelegt.");

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category'              => 'mutation',
            'tags'                  => ['asset-manager', 'holders', 'merge', 'cleanup'],
            'read_only'             => false,
            'requires_auth'         => true,
            'risk_level'            => 'write',
            'confirmation_required' => true,
        ];
    }
}
