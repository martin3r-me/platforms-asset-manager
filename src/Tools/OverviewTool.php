<?php

namespace Platform\AssetManager\Tools;

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Ein-Call-Einstieg in den Asset-Manager eines Teams: Gesamtkosten, Mengengerüst,
 * Top-Kostentreiber und Anomalien. Gedacht als erstes Tool, das die LLM aufruft.
 */
class OverviewTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.overview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/overview - Kompaktes Dashboard für das aktive Team: monatliche '
            . 'Gesamtkosten (Hardware/Lizenzen/Kostenpositionen/gesamt), Mengengerüst (Asset-Träger, '
            . 'Intune-Geräte, Geräte mit ablaufender Garantie/Leasing, Lizenz-SKUs, Inventar-Items, '
            . 'Kostenpositionen), die teuersten Asset-Träger '
            . 'sowie Anomalien (Pool-Hardware, ungenutzte Lizenzen, Hardware bei inaktiven Asset-Trägern). '
            . 'Idealer Startpunkt, um den Zustand des Asset-Managers zu erfassen. Listet immer die '
            . 'tenants des Teams (id/name) — von hier kommen die tenant_id-Werte der übrigen Tools. '
            . 'Bei tenant_id="all" kommt statt einer Sicht eine Liste by_tenant, je Eintrag mit '
            . 'tenant_id/tenant_name und den Kennzahlen dieses Tenants.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => $this->tenantSchemaPropertyWithAll(),
            'required'   => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId, true);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Tenant-Liste immer mitliefern: die Schema-Beschreibung aller übrigen Tools verweist für
            // die tenant_id-Werte hierher — ohne diese Liste wäre der Verweis eine Sackgasse.
            $tenantNames = $this->tenantNames($teamId);
            $tenants     = array_map(
                fn ($id, $name) => ['id' => (int) $id, 'name' => $name],
                array_keys($tenantNames),
                array_values($tenantNames),
            );

            // Bei tenant_id="all" dieselbe Sicht je Tenant statt einer verschmolzenen: eine
            // Kopfzahl über alle Kunden hinweg ist keine Kopfzahl, und die Top-Kostentreiber
            // verschiedener Kunden gehören nicht in eine gemeinsame Rangliste.
            if ($tenantId === null) {
                $perTenant = $this->rowsPerTenant($teamId, null, fn () => [$this->snapshot($teamId)]);

                return ToolResult::success([
                    'tenant_scope' => 'all',
                    'tenants'      => $tenants,
                    'by_tenant'    => $perTenant,
                    'currency'     => 'EUR',
                ]);
            }

            return ToolResult::success(
                ['tenant_id' => $tenantId, 'tenants' => $tenants] + $this->snapshot($teamId) + ['currency' => 'EUR'],
            );
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Übersicht: ' . $e->getMessage());
        }
    }

    /**
     * Kennzahlen-Sicht für den aktuell gesetzten Tenant-Kontext (der Global Scope filtert).
     *
     * Als eigene Methode, damit „ein Tenant" und „alle Tenants" garantiert dieselbe Rechnung
     * verwenden — zwei Codepfade wären zwei Wahrheiten.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(int $teamId): array
    {
        // Geräte mit in <= EXPIRY_SOON_DAYS ablaufender (oder bereits abgelaufener) Garantie/Leasing —
        // IT-Lifecycle-Kennzahl, daher Teil des immer sichtbaren Mengengerüsts (nicht der controlling-
        // gateten Anomalien). Gleiche Logik wie die Dashboard-Kachel „Garantie/Leasing läuft ab".
        $expiringThreshold = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);
        $expiringDevices = AssetDevice::where('team_id', $teamId)
            ->where(function ($q) use ($expiringThreshold) {
                $q->where(fn ($w) => $w->whereNotNull('warranty_until')->where('warranty_until', '<=', $expiringThreshold))
                  ->orWhere(fn ($w) => $w->whereNotNull('lease_until')->where('lease_until', '<=', $expiringThreshold));
            })->count();

        // Traeger-Zaehler schluesseln Person/Nicht-Person auf (ADR 0017): ohne das liest ein Modell
        // `holders` als Kopfzahl der Belegschaft — sie enthaelt aber auch Funktionskonten,
        // Admin-/Service-Accounts und Externe. Alle zaehlen in Lizenz- und Kostenauswertungen MIT;
        // nur die Personen-Zahl ist eine Kopfzahl.
        $counts = [
            'holders'          => AssetHolder::where('team_id', $teamId)->count(),
            'holders_active'   => AssetHolder::where('team_id', $teamId)->where('is_active', true)->count(),
            'holders_persons'  => AssetHolder::where('team_id', $teamId)->persons()->count(),
            'holders_non_persons' => AssetHolder::where('team_id', $teamId)->nonPersons()->count(),
            // DEPRECATED (ADR 0017): Alias auf holders_active.
            'employees_active' => AssetHolder::where('team_id', $teamId)->where('is_active', true)->count(),
            'devices'          => AssetDevice::where('team_id', $teamId)->count(),
            'expiring_devices' => $expiringDevices,
            'license_skus'     => AssetLicenseSku::where('team_id', $teamId)->count(),
            'items'            => AssetItem::where('team_id', $teamId)->count(),
            'cost_lines'       => AssetCostLine::where('team_id', $teamId)->count(),
        ];

        // Controlling-Schicht aus (ADR 0008): Kosten-Engine NICHT aufrufen — nur das Mengengerüst
        // liefern, Kosten/Top-Asset-Träger/Anomalien ausblenden. controlling_enabled signalisiert der LLM,
        // dass die Kosten-Sicht bewusst fehlt (nicht etwa leer ist).
        if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
            return [
                'controlling_enabled' => false,
                'counts'              => $counts,
                'note'                => 'Controlling/Kosten für dieses Team deaktiviert — Kosten, Top-Asset-Träger und Anomalien sind ausgeblendet (Modul-Einstellungen).',
            ];
        }

        /** @var CostAggregationService $agg */
        $agg = app(CostAggregationService::class);

        $totals    = $agg->totalMonthly($teamId);
        $anomalies = $agg->anomalies($teamId);
        $top       = $agg->topHolders($teamId, 5)->map(fn ($r) => [
            'holder'  => $r['holder']->name,
            'upn'       => $r['holder']->user_principal_name,
            'total'     => $r['total'],
            'hardware'  => $r['hardware'],
            'licenses'  => $r['licenses'],
            'costlines' => $r['costlines'],
        ])->values()->all();

        return [
            'controlling_enabled' => true,
            'monthly_costs' => $totals,
            'counts'        => $counts,
            'top_employees' => $top,
            'anomalies'     => $anomalies,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'tier'      => 'common',
            'read_only' => true,
            'tags'      => ['asset-manager', 'overview', 'costs', 'dashboard'],
        ];
    }
}
