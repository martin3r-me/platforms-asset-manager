<?php

namespace Platform\AssetManager\Tools\Holders;

use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\DeviceCostResolver;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Vollständiges 360°-Profil eines Asset-Trägers: Stammdaten, Intune-Geräte, Inventar-Items,
 * Microsoft-Lizenzen, Kostenpositionen, Kostenstelle und Monatskosten-Aufschlüsselung.
 * Beantwortet u.a. "mit welchen Geräten ist X unterwegs" und "was kostet X im Monat".
 */
class GetHolderTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.holder.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/employee - Voll-Profil EINES Asset-Trägers per id ODER '
            . 'user_principal_name (UPN). Liefert Stammdaten, alle Intune-Geräte (Modell, Seriennummer, '
            . 'Compliance, last_check_in, aufgelöste Monatskosten), Inventar-Items, Microsoft-Lizenzen, '
            . 'Kostenpositionen, die Kostenstelle und die monatliche Kostenaufschlüsselung '
            . '(hardware/licenses/costlines/total). Genau eines von id oder user_principal_name angeben.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id' => [
                    'type'        => 'integer',
                    'description' => 'Asset-Träger-ID (alternativ zu user_principal_name).',
                ],
                'user_principal_name' => [
                    'type'        => 'string',
                    'description' => 'UPN des Asset-Trägers (alternativ zu id), z.B. "max.muster@firma.de".',
                ],
            ], $this->tenantSchemaProperty()),
            'required' => [],
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
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            $query = AssetHolder::where('team_id', $teamId)->with('costCenter');
            if (!empty($arguments['id'])) {
                $query->where('id', (int) $arguments['id']);
            } elseif (!empty($arguments['user_principal_name'])) {
                $query->where('user_principal_name', (string) $arguments['user_principal_name']);
            } else {
                return ToolResult::error('VALIDATION_ERROR', 'Bitte id ODER user_principal_name angeben.');
            }

            /** @var AssetHolder|null $emp */
            $emp = $query->first();
            if (!$emp) {
                return ToolResult::error('NOT_FOUND', 'Asset-Träger nicht gefunden. Nutze asset-manager.holders.GET zum Suchen.');
            }

            /** @var CostAggregationService $agg */
            $agg = app(CostAggregationService::class);

            // Geräte (Intune)
            $deviceRows = $emp->devices()->get();

            // Kosten-Resolver je Tenant EINMAL bauen statt je Gerät: $d->resolvedMonthlyCost()
            // memoisiert nur pro Instanz und lädt darum je Zeile Katalog + Modell-Kosten neu
            // (2 Queries/Gerät). Schlüssel ist der Tenant des GERÄTS und nicht der Kontext-Tenant:
            // ohne auflösbaren Tenant greift der TenantScope nicht, die Liste kann dann
            // tenant-gemischt sein, und ein gemeinsamer Resolver trüge fremde Modell-Preise ein.
            // Kein auflösbarer Tenant am Gerät → Schlüssel 0, wie in AssetDevice::costResolver().
            $resolvers = [];
            foreach ($deviceRows as $d) {
                $resolvers[(int) $d->tenant_id] ??= DeviceCostResolver::for(
                    $teamId,
                    $d->tenant_id !== null ? (int) $d->tenant_id : null,
                );
            }

            $devices = $deviceRows->map(fn ($d) => [
                'id'               => $d->id,
                'device_name'      => $d->device_name,
                'manufacturer'     => $d->manufacturer,
                'model'            => $d->model,
                'serial_number'    => $d->serial_number,
                'operating_system' => $d->operating_system,
                'os_version'       => $d->os_version,
                'compliance'       => $d->complianceLabel(),
                'monthly_cost'     => $resolvers[(int) $d->tenant_id]->monthlyCost($d),
                'last_check_in_at' => $d->last_check_in_at?->toIso8601String(),
                'enrolled_at'      => $d->enrolled_at?->toIso8601String(),
            ])->values()->all();

            // Items + Lizenzen je EINMAL laden (mit Eager-Loading) und für Anzeige UND Kostensumme
            // wiederverwenden — vorher 2× items()/licenses() geladen + sku-N+1 je Lizenz.
            $itemModels    = $emp->items()->with('category')->get();
            $licenseModels = $emp->licenses()->with('sku')->get();

            // Inventar-Items (Drucker, Internet, Laptops …)
            $items = $itemModels->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->name,
                'category'     => $i->category?->name,
                'status'       => $i->statusLabel(),
                'serial_number' => $i->serial_number,
                'monthly_cost' => $i->monthlyCost(),
            ])->values()->all();

            // Lizenzen
            $licenses = $licenseModels->map(fn ($l) => [
                'sku_part_number' => $l->sku_part_number,
                // Lizenz-Anzeigename kommt aus dem SKU-Katalog (asset_license_skus). NICHT
                // $l->display_name nehmen — das ist der NUTZER-Name (Graph displayName, SyncLicensesJob).
                'display_name'    => $l->sku?->display_name ?: $l->sku_part_number,
                'unit_price'      => $l->sku?->unit_price !== null ? (float) $l->sku->unit_price : null,
                'assigned_at'     => $l->assigned_at?->toIso8601String(),
            ])->values()->all();

            // Kostenpositionen
            $costLines = $emp->costLines()->active()->validOn(now())->with(['costType', 'vendor'])->get()->map(fn ($c) => [
                'id'             => $c->id,
                'label'          => $c->label,
                'cost_type'      => $c->costType?->name,
                'vendor'         => $c->vendor?->name,
                'amount'         => (float) $c->amount,
                'frequency'      => $c->frequency,
                'monthly_amount' => (float) $c->monthly_amount,
            ])->values()->all();

            // Hardware-/Geräte-/Lizenz-Kosten aus der zentralen Quelle der Wahrheit (holderCost) statt
            // inline neu zu rechnen — verhindert stilles Divergieren bei Kostenmodell-Änderungen (M8).
            // holderCost trennt Items (hardware) und Geräte (device); hier wie bisher zu „hardware"
            // zusammengefasst. Die costline-Sicht ist NICHT Teil von holderCost und wird ergänzt.
            $cost     = $agg->holderCost($teamId, $emp);
            $hardware = round($cost['hardware'] + $cost['device'], 2);
            $licCost  = $cost['license'];
            $clCost   = (float) AssetCostLine::active()->validOn(now())->where('team_id', $teamId)
                ->where('assignee_id', $emp->id)
                ->whereHas('costType', fn ($q) => $q->where('aggregation_source', AssetCostType::SOURCE_COST_LINE))
                ->sum('monthly_amount');

            return ToolResult::success([
                'holder' => [
                    'id'                  => $emp->id,
                    'name'                => $emp->name,
                    'user_principal_name' => $emp->user_principal_name,
                    'email'               => $emp->email,
                    'department'          => $emp->department,
                    'job_title'           => $emp->job_title,
                    'is_active'           => (bool) $emp->is_active,
                    'holder_type'         => $emp->holder_type,
                    'holder_type_label'   => $emp->holderTypeLabel(),
                    // Explizit, damit ein Modell nicht aus dem Typ-String raten muss, ob es eine
                    // Person vor sich hat (ADR 0017).
                    'is_person'           => ! $emp->isNonPerson(),
                    'account_type'        => $emp->holder_type, // DEPRECATED — Alias, siehe ADR 0017
                    'cost_center'         => $emp->cost_center,
                    'cost_center_id'      => $emp->cost_center_id,
                    'cost_center_label'   => $emp->costCenter?->label,
                    // „Gesellschaft" ist der oberste Knoten über dieser Kostenstelle (ADR 0016).
                    'cost_center_root'    => $emp->costCenter?->rootLabel(),
                ],
                'devices'        => $devices,
                'items'          => $items,
                'licenses'       => $licenses,
                'cost_lines'     => $costLines,
                'monthly_costs'  => [
                    'hardware'  => $hardware,
                    'licenses'  => round($licCost, 2),
                    'costlines' => round($clCost, 2),
                    'total'     => round($hardware + $licCost + $clCost, 2),
                ],
                'currency'       => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Asset-Trägers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'holder', 'devices', 'costs']];
    }
}
