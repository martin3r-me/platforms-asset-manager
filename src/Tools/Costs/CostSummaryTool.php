<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Monatliche Gesamtkosten des Teams (Hardware inkl. Intune-Geräte, Lizenzen, Kostenpositionen).
 */
class CostSummaryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.costs.summary.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/costs/summary - Monatliche Gesamtkosten des aktiven Teams: '
            . 'hardware (AfA ZUGEWIESENER Inventar-Items + Intune-Geräte), licenses (zugewiesene '
            . 'Microsoft-Lizenzen), costlines (sonstige Kostenpositionen) und total. total ist per '
            . 'Konstruktion identisch mit dem grandTotal der Kostenstellen×Kostenart-Pivot '
            . '(asset-manager.costs.by). Zusätzlich capacity = Bestand, der dem Pivot NICHT zugeteilt '
            . 'wird (hardware_unassigned: AfA von Lager-/Pool-Items ohne Mitarbeiter; licenses_catalog: '
            . 'SKU-Katalogkosten) — bewusst NICHT Teil von total.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            $totals = app(CostAggregationService::class)->totalMonthly($teamId);

            return ToolResult::success(['monthly_costs' => $totals, 'currency' => 'EUR']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Berechnen der Gesamtkosten: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'costs', 'summary']];
    }
}
