<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetCostType extends Model
{
    use HasFactory;
    use TenantScopable;

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Platform\AssetManager\Database\Factories\AssetCostTypeFactory::new();
    }

    /**
     * Erlaubte Werte von `aggregation_source` — die Quelle, aus der eine Kostenart ihren Pivot-Wert
     * zieht. Genau EINE Quelle pro Kostenart (Doppelzählungs-Invariante, ADR 0001). Vorher als
     * Magic-Strings über CostAggregationService/MasterData/Tools/CostBootstrap verstreut.
     */
    public const SOURCE_COST_LINE    = 'cost_line';
    public const SOURCE_HARDWARE_AFA = 'hardware_afa';
    public const SOURCE_MS_LICENSE   = 'ms_license';
    public const SOURCE_ASSET_DEVICE = 'asset_device';

    /** Alle gültigen aggregation_source-Werte (für Rule::in(...) / Validierung). */
    public const SOURCES = [
        self::SOURCE_COST_LINE,
        self::SOURCE_HARDWARE_AFA,
        self::SOURCE_MS_LICENSE,
        self::SOURCE_ASSET_DEVICE,
    ];

    /**
     * Bezugsebene: an WAS hängen die Kosten dieser Kostenart?
     *
     * `person` — die Position gehört einem Asset-Träger (Mobilfunk, Lap+Dock, Lizenz je Seat).
     * `cost_center` — die Position gehört nur einer Kostenstelle, eine Person gibt es nicht
     *                 (BPEvent, HGK, necta, Internet, Drucker, team-weite Abos wie ChatGPT).
     *
     * Ersetzt das frühere `is_per_employee` — ein Häkchen, das nichts erzwang und im Bestand
     * entsprechend falsch gesetzt war. Die Ebene ist die Grundlage der Plausibilitätsprüfung:
     * `person` ohne Träger und `cost_center` MIT Träger sind beides Fehler.
     */
    public const LEVEL_PERSON      = 'person';
    public const LEVEL_COST_CENTER = 'cost_center';

    /** @var list<string> */
    public const LEVELS = [
        self::LEVEL_PERSON,
        self::LEVEL_COST_CENTER,
    ];

    protected $table = 'asset_cost_types';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'key',
        'name',
        'sort_order',
        'vendor_default_id',
        'system_default',
        'frequency_default',
        'allocation_level',
        'aggregation_source',
        'allow_negative',
    ];

    protected $casts = [
        'allow_negative' => 'boolean',
    ];

    /** Bezugsebene ist Person? Ersetzt die frühere Spalte `is_per_employee` als abgeleiteter Wert. */
    public function isPerPerson(): bool
    {
        return $this->allocation_level === self::LEVEL_PERSON;
    }

    /**
     * Rückwärtskompatibler Zugriff: `$type->is_per_employee` liefert weiter einen Boolean, damit
     * bestehende MCP-Antworten und Views ihr Feld behalten. Schreiben geht nur noch über
     * `allocation_level` — sonst gäbe es wieder zwei Wahrheiten.
     */
    public function getIsPerEmployeeAttribute(): bool
    {
        return $this->isPerPerson();
    }

    public function levelLabel(): string
    {
        return $this->isPerPerson() ? 'Person' : 'Kostenstelle';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function vendorDefault(): BelongsTo
    {
        return $this->belongsTo(AssetVendor::class, 'vendor_default_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'cost_type_id');
    }
}
