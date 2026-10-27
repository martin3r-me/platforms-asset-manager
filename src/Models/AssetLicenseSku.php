<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetLicenseSku extends Model
{
    use TenantScopable;

    protected $table = 'asset_license_skus';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'sku_id',
        'sku_part_number',
        'display_name',
        'purchased_units',
        'consumed_units',
        'available_units',
        'unit_price',
        'price_source',
        'price_period',
        'price_updated_at',
        'synced_at',
        'raw_data',
    ];

    protected $casts = [
        'raw_data'         => 'array',
        'synced_at'        => 'datetime',
        'price_updated_at' => 'datetime',
        'unit_price'       => 'decimal:2',
    ];

    /** Preis von Hand gepflegt — der einzige Fall, in dem `unit_price` hier gilt. */
    public const PRICE_SOURCE_MANUAL = 'manual';

    /**
     * Preis kommt aus Vertragszeilen. `unit_price` ist dann leer und **nicht** maßgeblich: die SKU
     * hat mehrere Tarife, der Preis hängt je Seat an der Vertragszeile.
     */
    public const PRICE_SOURCE_CONTRACT = 'contract';

    /**
     * Kostenlose Microsoft-SKUs (Self-Service, Testversionen, Entwicklerpläne). Sie kosten nichts und brauchen
     * deshalb keinen Preis — ohne diese Liste meldete jede davon „Preis fehlt" (Discussion #27). Bewusst eine
     * feste Liste von Microsoft-Part-Numbers statt „Preis 0 pflegen": die SKUs sind in jedem Tenant dieselben.
     */
    public const FREE_PART_NUMBERS = [
        'FLOW_FREE',
        'POWER_BI_STANDARD',
        'POWERAPPS_DEV',
        'POWERAPPS_VIRAL',
        'TEAMS_EXPLORATORY',
        'RIGHTSMANAGEMENT_ADHOC',
        'CCIBOTS_PRIVPREV_VIRAL',
        'WINDOWS_STORE',
    ];

    /** Kostenlose SKU laut {@see FREE_PART_NUMBERS}? */
    public function isFree(): bool
    {
        return in_array(strtoupper((string) $this->sku_part_number), self::FREE_PART_NUMBERS, true);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), zu dem diese SKU gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    /** Die Vertragszeilen, die diese Lizenz bezahlen — über die Graph-GUID, nicht die lokale ID. */
    public function contractLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetLicenseContractLine::class, 'sku_id', 'sku_id');
    }

    /** Steht der Preis dieser Lizenz auf einer Rechnung? Dann gilt `unit_price` hier nicht. */
    public function isContractPriced(): bool
    {
        return $this->price_source === self::PRICE_SOURCE_CONTRACT;
    }

    /**
     * Monatskosten aus dem **handgepflegten** Stückpreis.
     *
     * Gilt nur für Lizenzen ohne Vertragszeilen (Self-Service- und Gratis-SKUs). Ist die Lizenz
     * rechnungsgedeckt, ist `unit_price` leer und diese Rechnung ergibt 0 — die Kosten stehen dann an
     * den Vertragszeilen und werden über
     * {@see \Platform\AssetManager\Support\LicensePriceBook} summiert, das die Zeilen aller SKUs
     * einmalig lädt (eine Query je Aufruf statt einer je Lizenz).
     */
    public function monthlyCost(): float
    {
        if ($this->unit_price === null) {
            return 0.0;
        }
        return (float) $this->unit_price * $this->consumed_units;
    }

    public function annualCost(): float
    {
        return $this->monthlyCost() * 12;
    }

    public function utilizationPercent(): int
    {
        if ($this->purchased_units === 0) {
            return 0;
        }
        return (int) round($this->consumed_units / $this->purchased_units * 100);
    }
}
