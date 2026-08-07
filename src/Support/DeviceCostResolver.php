<?php

namespace Platform\AssetManager\Support;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetDeviceModelCost;
use Platform\AssetManager\Services\TenantContext;

/**
 * Löst Monatskosten und Kostenart eines Geräts auf: Override am Gerät, sonst der **tenant-eigene**
 * Modell-Default.
 *
 * Existiert, weil der Umzug der Modell-Kosten nach `asset_device_model_costs` (docs/adr/0016) sonst
 * an zehn Stellen dieselbe zweistufige Auflösung neu erfunden hätte — Pivot, Inventar-Liste,
 * Modell-Report und sechs MCP-Tools hatten den Katalog bereits jeweils selbst vorgeladen, um das
 * `deviceModel()`-N+1 zu umgehen. Jetzt einmal hier.
 *
 * Lädt Katalog und Tenant-Kosten **einmal** vor und beantwortet danach jede Frage aus dem Speicher —
 * kein Query je Gerät.
 */
class DeviceCostResolver
{
    /** @var array<string, AssetDeviceModel> Modelle je normalisiertem (Hersteller|Modell)-Schlüssel. */
    private array $modelByKey = [];

    /** @var array<int, AssetDeviceModelCost> Tenant-Kostenzeile je device_model_id. */
    private array $costByModelId = [];

    private function __construct(
        public readonly int $teamId,
        public readonly ?int $tenantId,
    ) {
    }

    /**
     * Resolver für ein Team und einen Tenant bauen (Default = aktiver Tenant).
     *
     * Ohne auflösbaren Tenant bleiben die Modell-Defaults leer: Kosten sind tenant-gebunden, und auf
     * „irgendeinen" Tenant zu raten würde fremde Leasingraten in eine Auswertung tragen. Geräte-eigene
     * Overrides greifen weiterhin.
     */
    public static function for(int $teamId, ?int $tenantId = null): self
    {
        $tenantId ??= TenantContext::scopeTenantId();
        $resolver = new self($teamId, $tenantId);

        foreach (AssetDeviceModel::where('team_id', $teamId)->get() as $model) {
            $resolver->modelByKey[AssetDeviceModel::normalizeKey($model->manufacturer, $model->model)] = $model;
        }

        if ($tenantId !== null) {
            $costs = AssetDeviceModelCost::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->get();

            foreach ($costs as $cost) {
                $resolver->costByModelId[(int) $cost->device_model_id] = $cost;
            }
        }

        return $resolver;
    }

    /** Modell eines Geräts (case-/whitespace-tolerant über den normalisierten Schlüssel). */
    public function modelFor(AssetDevice $device): ?AssetDeviceModel
    {
        return $this->modelByKey[AssetDeviceModel::normalizeKey($device->manufacturer, $device->model)] ?? null;
    }

    /** Tenant-Kostenzeile eines Modells. */
    public function costForModel(?AssetDeviceModel $model): ?AssetDeviceModelCost
    {
        return $model ? ($this->costByModelId[(int) $model->id] ?? null) : null;
    }

    /**
     * Monatskosten: Override am Gerät hat Vorrang, sonst der tenant-eigene Modell-Default
     * (Leasingrate vor Kauf/AfA). 0.0 wenn nichts hinterlegt oder bereits abgeschrieben.
     */
    public function monthlyCost(AssetDevice $device): float
    {
        $own = AssetDevice::computeMonthlyFrom(
            $device->monthly_cost,
            $device->purchase_price,
            $device->depreciation_months,
            $device->purchase_date,
        );

        if ($own !== null) {
            return $own;
        }

        return $this->modelMonthlyCost($device) ?? 0.0;
    }

    /** Nur der Modell-Anteil — für Anzeigen, die Override und Default getrennt ausweisen. */
    public function modelMonthlyCost(AssetDevice $device): ?float
    {
        $model = $this->modelFor($device);
        $cost  = $this->costForModel($model);

        if (! $model) {
            return null;
        }

        return AssetDevice::computeMonthlyFrom(
            $cost?->monthly_cost,
            $cost?->purchase_price,
            $model->depreciation_months,
            null,
        );
    }

    /** Kostenart: Override am Gerät, sonst die des tenant-eigenen Modell-Defaults. */
    public function costTypeId(AssetDevice $device): ?int
    {
        if ($device->cost_type_id !== null) {
            return (int) $device->cost_type_id;
        }

        $cost = $this->costForModel($this->modelFor($device));

        return $cost?->cost_type_id !== null ? (int) $cost->cost_type_id : null;
    }

    /** Kreditor des tenant-eigenen Modell-Defaults (Geräte tragen keinen eigenen). */
    public function vendorId(AssetDevice $device): ?int
    {
        $cost = $this->costForModel($this->modelFor($device));

        return $cost?->vendor_id !== null ? (int) $cost->vendor_id : null;
    }

    /** Hat das Modell dieses Geräts überhaupt einen Preis im aktiven Tenant? */
    public function hasModelPrice(AssetDevice $device): bool
    {
        return $this->modelMonthlyCost($device) !== null;
    }

    /** Alle Modelle des Katalogs (für Report-/Listen-Sichten). */
    public function models(): Collection
    {
        return collect($this->modelByKey)->values();
    }

    /** Tenant-Kostenzeile per Modell-ID — für Sichten, die über den Katalog iterieren. */
    public function costForModelId(int $modelId): ?AssetDeviceModelCost
    {
        return $this->costByModelId[$modelId] ?? null;
    }
}
