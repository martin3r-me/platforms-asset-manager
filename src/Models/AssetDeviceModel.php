<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Platform\AssetManager\Services\TenantContext;

/**
 * Hardware-Katalog: ein Geräte-Modell (Hersteller + Modell). Der Intune-Sync legt die real
 * existierenden Modelle automatisch an.
 *
 * **Team-weit** und bewusst NICHT tenant-scoped (docs/adr/0016): ein MacBook Air M2 ist bei jedem
 * Kunden dasselbe Gerät, Hersteller und Modell sind keine Kundendaten. Die **Kosten** dazu sind pro
 * Kunde verhandelt und leben in {@see AssetDeviceModelCost} (Tenant × Modell). Geräte erben den
 * tenant-eigenen Default; ein einzelnes Gerät kann ihn überschreiben (Felder direkt an AssetDevice).
 *
 * `depreciation_months` bleibt hier: die Nutzungsdauer ist eine Eigenschaft der Hardware, nicht des
 * Vertrags.
 */
class AssetDeviceModel extends Model
{
    protected $table = 'asset_device_models';

    protected $fillable = [
        'team_id',
        'manufacturer',
        'model',
        'depreciation_months',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Alle tenant-spezifischen Kostenzeilen zu diesem Modell. */
    public function costs(): HasMany
    {
        return $this->hasMany(AssetDeviceModelCost::class, 'device_model_id');
    }

    /**
     * Kostenzeile des **aktiven** Tenants. Der Global Scope auf AssetDeviceModelCost filtert
     * automatisch — ohne auflösbaren Tenant (Job/Console) liefert die Relation die erste Zeile, was
     * für Anzeigepfade genügt; rechnende Pfade laufen über {@see costFor()}.
     */
    public function cost(): HasOne
    {
        return $this->hasOne(AssetDeviceModelCost::class, 'device_model_id');
    }

    /** Kostenzeile eines bestimmten Tenants — der explizite Weg für Jobs und Auswertungen. */
    public function costFor(?int $tenantId): ?AssetDeviceModelCost
    {
        $tenantId ??= TenantContext::scopeTenantId();

        if ($tenantId === null) {
            return null;
        }

        return AssetDeviceModelCost::query()
            ->withoutTenantScope()
            ->where('device_model_id', $this->id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Einheitlicher Abgleich-Schlüssel (Hersteller|Modell), case-/whitespace-tolerant.
     * EINE Quelle der Wahrheit für Gerät ↔ Modell — von AssetDevice::deviceModel(),
     * CostAggregationService und der Geräte-Zählung genutzt, damit alle dieselbe Zuordnung sehen.
     */
    public static function normalizeKey(?string $manufacturer, ?string $model): string
    {
        return mb_strtolower(trim((string) $manufacturer)) . '|' . mb_strtolower(trim((string) $model));
    }
}
