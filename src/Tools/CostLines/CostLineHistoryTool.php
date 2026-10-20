<?php

namespace Platform\AssetManager\Tools\CostLines;

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostLineHistory;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostLineHistoryService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\PhoneNumber;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Preisverlauf importierter Kostenpositionen (ADR 0029) — „was kostet 0173 3185736 normalerweise?"
 *
 * Sucht im Verlauf selbst (nicht nur in den heutigen Kostenpositionen): auch eine inzwischen
 * gekündigte Nummer hat ihren Verlauf. Gruppiert wird über die Identität der Zeile (import_hash).
 */
class CostLineHistoryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const MAX_LINES = 25;

    public function getName(): string
    {
        return 'asset-manager.cost-lines.history.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/cost-lines/history - Preisverlauf importierter Kostenpositionen (Vodafone '
            . 'Mobilfunk je Rufnummer, Festnetz/Internet, RingCentral-Plätze): Betrag je Rechnungsmonat, '
            . 'Regelpreis (häufigster Betrag), letzter Betrag und ob er abweicht. Suche per phone (Rufnummer in '
            . 'beliebiger Schreibweise), cost_line_id oder search (Text in der Bezeichnung). months = Zeitraum '
            . '(Default 24). Findet auch Nummern, die es heute nicht mehr gibt. Nur mit Controlling.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'phone'        => ['type' => 'string', 'description' => 'Rufnummer, z. B. "0173 3185736" oder "+49 173 3185736".'],
                'cost_line_id' => ['type' => 'integer', 'description' => 'ID einer Kostenposition.'],
                'search'       => ['type' => 'string', 'description' => 'Text in der Bezeichnung (z. B. „RingCentral", „Internet").'],
                'months'       => ['type' => 'integer', 'description' => 'Zeitraum in Monaten (Default 24, max 60).'],
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

            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für diesen Tenant deaktiviert (Modul-Einstellungen).');
            }

            $months = max(1, min(60, (int) ($arguments['months'] ?? 24)));
            $hashes = $this->hashes($teamId, (int) $tenantId, $arguments);

            if ($hashes === null) {
                return ToolResult::error('VALIDATION_ERROR', 'Bitte phone, cost_line_id oder search angeben.');
            }

            $byHash = app(CostLineHistoryService::class)->forHashes((int) $tenantId, array_slice($hashes, 0, self::MAX_LINES), $months);

            $lines = [];
            foreach ($byHash as $hash => $entries) {
                $last    = $entries->last();
                $summary = CostLineHistoryService::summarize($entries);
                $lines[] = [
                    'label'        => $last->label,
                    'cost_line_id' => $last->cost_line_id,
                    'source'       => $last->source,
                    'account'      => $last->account,
                    'regular_price' => $summary['regular'],
                    'latest'       => $summary['latest'],
                    'deviates'     => $summary['deviates'],
                    'months'       => $summary['months'],
                ];
            }

            return ToolResult::success([
                'tenant_id' => $tenantId,
                'count'     => count($lines),
                'truncated' => count($hashes) > self::MAX_LINES,
                'lines'     => $lines,
                'currency'  => 'EUR',
                'note'      => $lines === [] ? 'Kein Verlauf gefunden — Verlauf entsteht mit jedem Rechnungsimport; ältere Rechnungen lassen sich unter Kosten-Import → „Preis-Historie nachtragen" ergänzen.' : null,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Preisverlaufs: ' . $e->getMessage());
        }
    }

    /** @return ?list<string> import_hashes der gesuchten Zeilen, null = kein Suchkriterium */
    private function hashes(int $teamId, int $tenantId, array $arguments): ?array
    {
        if (! empty($arguments['cost_line_id'])) {
            $hash = AssetCostLine::where('team_id', $teamId)->whereKey((int) $arguments['cost_line_id'])->value('import_hash');

            return $hash ? [(string) $hash] : [];
        }

        $needle = null;
        if (! empty($arguments['phone'])) {
            $needle = PhoneNumber::national((string) $arguments['phone']) ?? preg_replace('/\D+/', '', (string) $arguments['phone']);
            $needle = preg_replace('/\D+/', '', (string) $needle);
        } elseif (! empty($arguments['search'])) {
            $needle = trim((string) $arguments['search']);
        }

        if ($needle === null || $needle === '') {
            return null;
        }

        return AssetCostLineHistory::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('label', 'like', '%' . $needle . '%')
            ->distinct()
            ->pluck('import_hash')
            ->all();
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'cost-lines', 'history', 'vodafone']];
    }
}
