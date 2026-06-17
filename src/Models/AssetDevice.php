<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetDevice extends Model
{
    use SoftDeletes;

    protected $table = 'asset_devices';

    /** Memoisiertes Modell-Resolve je Instanz (deviceModel() wird pro Render mehrfach gerufen). */
    protected ?AssetDeviceModel $resolvedModel = null;
    protected bool $modelResolved = false;

    protected $fillable = [
        'team_id',
        'tenant_id',
        'azure_tenant_id',
        'intune_id',
        'source',
        'device_name',
        'user_display_name',
        'user_principal_name',
        'operating_system',
        'os_version',
        'compliance_state',
        'management_state',
        'device_type',
        'manufacturer',
        'model',
        'serial_number',
        'monthly_cost',
        'purchase_price',
        'depreciation_months',
        'purchase_date',
        'cost_type_id',
        'cost_center_id',
        'notes',
        'lifecycle_status',
        'warranty_until',
        'lease_until',
        'vendor_id',
        'order_no',
        'order_date',
        'location',
        'is_encrypted',
        'enrollment_type',
        'free_storage_bytes',
        'total_storage_bytes',
        'physical_memory_bytes',
        'enrolled_at',
        'last_check_in_at',
        'raw_data',
    ];

    protected $casts = [
        'enrolled_at'      => 'datetime',
        'last_check_in_at' => 'datetime',
        'raw_data'         => 'array',
        'monthly_cost'     => 'decimal:2',
        'purchase_price'   => 'decimal:2',
        'purchase_date'    => 'date',
        'warranty_until'   => 'date',
        'lease_until'      => 'date',
        'order_date'       => 'date',
        'is_encrypted'     => 'boolean',
    ];

    /** Tage-Schwelle, ab der Garantie/Leasing als „läuft bald ab" gilt (inkl. bereits abgelaufen). */
    public const EXPIRY_SOON_DAYS = 90;

    /** Erlaubte Lifecycle-Status (Reihenfolge = UI-Reihenfolge). */
    public const LIFECYCLE_STATUSES = ['in_use', 'spare', 'repair', 'retired', 'lost'];

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), zu dem dieses Gerät gehört. */
    public function tenant()
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function costType()
    {
        return $this->belongsTo(AssetCostType::class, 'cost_type_id');
    }

    public function costCenter()
    {
        return $this->belongsTo(AssetCostCenter::class, 'cost_center_id');
    }

    public function vendor()
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_id');
    }

    /** Mitarbeiter über die UPN (Geräte tragen nur die UPN als String, keine echte FK). */
    public function assignee()
    {
        return $this->belongsTo(AssetEmployee::class, 'user_principal_name', 'user_principal_name');
    }

    /**
     * Passendes Geräte-Modell (Default-Kosten) per (Hersteller, Modell) auflösen — normalisiert
     * (case-/whitespace-tolerant) über AssetDeviceModel::normalizeKey(), damit Detailansicht und
     * Pivot/Aggregation IMMER dasselbe Modell treffen. Memoisiert je Instanz.
     */
    public function deviceModel(): ?AssetDeviceModel
    {
        if ($this->modelResolved) {
            return $this->resolvedModel;
        }
        $this->modelResolved = true;

        $key = AssetDeviceModel::normalizeKey($this->manufacturer, $this->model);
        $this->resolvedModel = AssetDeviceModel::where('team_id', $this->team_id)->get()
            ->first(fn($m) => AssetDeviceModel::normalizeKey($m->manufacturer, $m->model) === $key);

        return $this->resolvedModel;
    }

    /**
     * Monatskosten aus Override (Gerät) oder Modell-Default. Leasing-Rate (monthly_cost) hat Vorrang
     * vor Kauf+AfA (purchase_price/depreciation_months). 0.0 wenn nichts hinterlegt oder abgeschrieben.
     */
    public function resolvedMonthlyCost(): float
    {
        $own = self::computeMonthlyFrom($this->monthly_cost, $this->purchase_price, $this->depreciation_months, $this->purchase_date);
        if ($own !== null) return $own;

        if ($m = $this->deviceModel()) {
            $fromModel = self::computeMonthlyFrom($m->monthly_cost, $m->purchase_price, $m->depreciation_months, null);
            if ($fromModel !== null) return $fromModel;
        }
        return 0.0;
    }

    /** Kostenart: Override am Gerät, sonst vom Modell. */
    public function resolvedCostTypeId(): ?int
    {
        return $this->cost_type_id ?? $this->deviceModel()?->cost_type_id;
    }

    /**
     * Monatsbetrag aus Leasing-Rate ODER Kauf/AfA. null = keine Kostendaten (Caller entscheidet Fallback).
     */
    public static function computeMonthlyFrom($monthly, $price, $depMonths, $purchaseDate): ?float
    {
        // 0 (bzw. "0.00" aus dem decimal-Cast) ist KEIN Override → auf Modell-Default/Kauf-AfA durchfallen
        // lassen, statt das Gerät mit 0 € aus der Kostenbasis zu kippen. Nur ein echter >0-Wert überschreibt.
        if ($monthly !== null && $monthly !== '' && (float) $monthly > 0) {
            return round((float) $monthly, 2);
        }
        // decimal-Casts liefern "0.00" (truthy) — daher explizit auf > 0 prüfen, nicht auf Truthiness.
        if ($price !== null && $price !== '' && (float) $price > 0 && $depMonths) {
            if ($purchaseDate) {
                $pd = $purchaseDate instanceof \Carbon\CarbonInterface ? $purchaseDate : \Carbon\Carbon::parse($purchaseDate);
                if ($pd->diffInMonths(now()) >= (int) $depMonths) return 0.0;
            }
            return round((float) $price / (int) $depMonths, 2);
        }
        return null;
    }

    public function isCompliant(): bool
    {
        return $this->compliance_state === 'compliant';
    }

    public function complianceBadgeColor(): string
    {
        return match($this->compliance_state) {
            'compliant'      => 'emerald',
            'noncompliant'   => 'red',
            'inGracePeriod'  => 'amber',
            'error'          => 'red',
            'conflict'       => 'orange',
            default          => 'gray',
        };
    }

    public function complianceLabel(): string
    {
        return match($this->compliance_state) {
            'compliant'      => 'Konform',
            'noncompliant'   => 'Nicht konform',
            'inGracePeriod'  => 'Karenzzeit',
            'error'          => 'Fehler',
            'conflict'       => 'Konflikt',
            default          => 'Unbekannt',
        };
    }

    public function lifecycleLabel(): string
    {
        return match($this->lifecycle_status) {
            'in_use'  => 'In Betrieb',
            'spare'   => 'Reserve / Lager',
            'repair'  => 'In Reparatur',
            'retired' => 'Ausgemustert',
            'lost'    => 'Verloren / Gestohlen',
            default   => '—',
        };
    }

    /** Nur Farbfamilien verwenden, die im Modul bereits als Klassen vorkommen (Tailwind-Build). */
    public function lifecycleBadgeColor(): string
    {
        return match($this->lifecycle_status) {
            'in_use'  => 'emerald',
            'spare'   => 'indigo',
            'repair'  => 'amber',
            'retired' => 'gray',
            'lost'    => 'red',
            default   => 'gray',
        };
    }

    /** Frühestes Ablaufdatum aus Garantie/Leasing (oder null, wenn beides leer). */
    public function earliestExpiry(): ?\Carbon\CarbonInterface
    {
        $w = $this->warranty_until;
        $l = $this->lease_until;
        if ($w && $l) return $w->lte($l) ? $w : $l;
        return $w ?: $l;
    }

    /** Garantie/Leasing läuft innerhalb der Schwelle ab (oder ist bereits abgelaufen). */
    public function isExpiringSoon(): bool
    {
        $e = $this->earliestExpiry();
        return $e !== null && $e->lte(now()->addDays(self::EXPIRY_SOON_DAYS));
    }

    public function encryptionLabel(): string
    {
        return match (true) {
            $this->is_encrypted === true  => 'Verschlüsselt',
            $this->is_encrypted === false => 'Nicht verschlüsselt',
            default                       => 'Unbekannt',
        };
    }

    public function encryptionBadgeColor(): string
    {
        return match (true) {
            $this->is_encrypted === true  => 'emerald',
            $this->is_encrypted === false => 'red',
            default                       => 'gray',
        };
    }

    /** Bytes menschenlesbar (GB/MB). null/0 → „—". */
    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) return '—';
        $gb = $bytes / 1073741824;
        if ($gb >= 1) return number_format($gb, $gb >= 100 ? 0 : 1, ',', '.') . ' GB';
        return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
    }

    /** „frei / gesamt" Speicher oder „—". */
    public function storageSummary(): string
    {
        if (!$this->free_storage_bytes && !$this->total_storage_bytes) return '—';
        $free = self::formatBytes($this->free_storage_bytes);
        return $this->total_storage_bytes ? $free . ' / ' . self::formatBytes($this->total_storage_bytes) : $free;
    }

    public function memoryLabel(): string
    {
        return self::formatBytes($this->physical_memory_bytes);
    }
}
