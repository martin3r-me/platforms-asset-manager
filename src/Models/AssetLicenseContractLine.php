<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * **Vertragszeile** — eine Lizenzmenge zu einem Preis mit einer Bindung.
 *
 * Träger des Lizenzpreises (nicht die SKU): eine Rechnung enthält regelmäßig mehrere Tarif-Varianten
 * derselben SKU, und jede behält ihren echten Monatspreis. Verteilt werden die **Mengen** auf die
 * Lizenzträger — siehe {@see \Platform\AssetManager\Services\LicenseSeatAllocator}.
 *
 * Entsteht aus dem Rechnungsimport, wird danach fachlich gepflegt: `binding_confirmed` und
 * `cancellable_at` sind Handpflege, alles andere kommt aus der Rechnung.
 */
class AssetLicenseContractLine extends Model
{
    use TenantScopable;

    /** Monatsbindung — flexibel, in der Regel teurer. */
    public const BINDING_MONTHLY = 'P1M';

    /** Jahresbindung — günstiger, dafür bis `cancellable_at` gebunden. */
    public const BINDING_YEARLY = 'P1Y';

    /** @var list<string> */
    public const BINDINGS = [self::BINDING_MONTHLY, self::BINDING_YEARLY];

    /** Zuordnung zu einer Graph-SKU: automatisch erkannt. */
    public const MATCH_AUTO = 'auto';

    /** Zuordnung über einen gelernten Alias. */
    public const MATCH_ALIAS = 'alias';

    /** Zuordnung von Hand gesetzt. */
    public const MATCH_MANUAL = 'manual';

    /** Keine Lizenz gefunden — wartet auf eine Entscheidung. */
    public const MATCH_UNMATCHED = 'unmatched';

    /** Bewusst keiner Lizenz zugeordnet (etwa ein kontobezogener Posten). */
    public const MATCH_IGNORED = 'ignored';

    protected $table = 'asset_license_contract_lines';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'period_from',
        'period_to',
        'period_label',
        'period_days',
        'product',
        'edition',
        'edition_key',
        'quantity',
        'list_price',
        'discount_percent',
        'unit_price',
        'amount_net',
        'currency',
        'binding',
        'binding_confirmed',
        'cancellable_at',
        'sku_id',
        'match_state',
        'match_note',
        'assigned_quantity',
        'overflow_quantity',
        'pool_quantity',
        'import_batch_id',
        'import_hash',
        'raw_data',
    ];

    protected $casts = [
        'period_from'       => 'date',
        'period_to'         => 'date',
        'cancellable_at'    => 'date',
        'quantity'          => 'float',
        'list_price'        => 'float',
        'discount_percent'  => 'float',
        'unit_price'        => 'float',
        'amount_net'        => 'float',
        'assigned_quantity' => 'float',
        'overflow_quantity' => 'float',
        'pool_quantity'     => 'float',
        'binding_confirmed' => 'boolean',
        'raw_data'          => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    /** Die Seats, die diese Zeile bezahlt. */
    public function userLicenses(): HasMany
    {
        return $this->hasMany(AssetUserLicense::class, 'contract_line_id');
    }

    /** Die Graph-SKU, sofern zugeordnet — über die Graph-GUID, nicht die lokale ID. */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(AssetLicenseSku::class, 'sku_id', 'sku_id');
    }

    public function isYearly(): bool
    {
        return $this->binding === self::BINDING_YEARLY;
    }

    public function isMonthly(): bool
    {
        return $this->binding === self::BINDING_MONTHLY;
    }

    public function isMatched(): bool
    {
        return $this->sku_id !== null
            && in_array($this->match_state, [self::MATCH_AUTO, self::MATCH_ALIAS, self::MATCH_MANUAL], true);
    }

    public function bindingLabel(): string
    {
        return $this->isYearly() ? 'Jahresbindung' : 'Monatsbindung';
    }

    /** Rechnungsbetrag, den diese Zeile bei voller Monatsmenge ausmacht. */
    public function monthlyAmount(): float
    {
        return round($this->unit_price * $this->quantity, 2);
    }

    /**
     * Mengen, die keinem Träger zugeordnet wurden. Muss zusammen mit `assigned_quantity` genau
     * `quantity` ergeben — die Kaskade darf keine bezahlte Menge verlieren.
     */
    public function unallocatedQuantity(): float
    {
        return round($this->overflow_quantity + $this->pool_quantity, 2);
    }

    /** Hält die Mengenbilanz der Kaskade? Ein `false` ist ein Fehler, keine Toleranz. */
    public function quantityBalances(): bool
    {
        return abs(($this->assigned_quantity + $this->unallocatedQuantity()) - $this->quantity) < 0.005;
    }

    public function scopeForPeriod($query, string $periodLabel)
    {
        return $query->where('period_label', $periodLabel);
    }

    public function scopeMatched($query)
    {
        return $query->whereNotNull('sku_id')
            ->whereIn('match_state', [self::MATCH_AUTO, self::MATCH_ALIAS, self::MATCH_MANUAL]);
    }
}
