<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostReallocationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Umbuchungsbasis je importierter Rechnung — Betrag je Kostenstelle und Kostenart
 * ({@see CostReallocationService}, Discussion #26).
 */
class CostReallocationTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.costs.reallocation.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/reallocation - Umbuchungsbasis je importierter Rechnung (Vodafone '
            . 'Mobilfunk/Festnetz): welcher Betrag auf welcher Kostenstelle landet, getrennt nach Kostenart, '
            . 'mit Rechnungskonto, Rechnungsnummer, Zeitraum und Gesamtsumme. Grundlage für die Umbuchung von '
            . 'der Sammel-Kostenstelle auf die nutzenden Kostenstellen (DATEV). Filter: source '
            . '(vodafone_invoice | vodafone_fixednet), account (Rechnungskonto). Rein lesend.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => $this->tenantSchemaProperty() + [
                'source'  => ['type' => 'string', 'enum' => CostReallocationService::SOURCES, 'description' => 'Nur eine Quelle.'],
                'account' => ['type' => 'string', 'description' => 'Nur ein Rechnungskonto, z. B. 86839593.'],
            ],
            'required'   => [],
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
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen).');
            }

            $source = $arguments['source'] ?? null;
            if ($source !== null && ! in_array($source, CostReallocationService::SOURCES, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'source muss eines von: ' . implode(', ', CostReallocationService::SOURCES));
            }

            $documents = app(CostReallocationService::class)->documents(
                $teamId,
                $source,
                isset($arguments['account']) ? (string) $arguments['account'] : null,
            );

            return ToolResult::success([
                'tenant_id' => $tenantId,
                'documents' => $documents,
                'currency'  => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei der Umbuchungsbasis: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'datev', 'reallocation']];
    }
}
