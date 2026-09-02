<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Asset-Träger: wer ein Gerät oder eine Lizenz **trägt** (siehe docs/adr/0017).
 *
 * Bildet die Beziehung Person ↔ Asset ab, **kein Arbeitsverhältnis** — mit drin sind Externe,
 * Admin-/Verwaltungsaccounts (keine Personen), Funktionskonten und Nicht-Menschen als Lizenzträger.
 * Identität ist die **UPN**; sie ist der Anker für Sync, Kostenzuordnung und Anonymisierung, weshalb
 * Lizenz- und Geräteträger bewusst NICHT getrennt sind.
 */
class AssetHolder extends Model
{
    use HasFactory;
    use TenantScopable;

    /**
     * Träger-Typen. `person` ist der Default; die übrigen bezeichnen Träger, die **keine** Person
     * sind bzw. nicht zur Organisation gehören:
     *   function — Funktionskonto (CONTROLLING, HELPDESK …), synthetische UPN, trägt Kosten ohne Person
     *   admin    — Administrations-/Verwaltungsaccount einer echten Person oder unpersonalisiert
     *   service  — technischer Account / Nicht-Mensch als Lizenzträger (z. B. ein Bot)
     *   external — Externe mit Firmengerät oder Lizenz (Dienstleister, Aushilfen)
     */
    public const TYPE_PERSON   = 'person';
    public const TYPE_FUNCTION = 'function';
    public const TYPE_ADMIN    = 'admin';
    public const TYPE_SERVICE  = 'service';
    public const TYPE_EXTERNAL = 'external';

    public const TYPES = [
        self::TYPE_PERSON,
        self::TYPE_FUNCTION,
        self::TYPE_ADMIN,
        self::TYPE_SERVICE,
        self::TYPE_EXTERNAL,
    ];

    /**
     * Typen, die **keine** natürliche Person der Organisation sind.
     *
     * Sie werden **gelabelt, nicht gefiltert**: die Default-Sicht blendet sie aus, Lizenz- und
     * Kostenauswertungen zählen sie **mit** — ein Admin-Account verbraucht eine echte Lizenz und
     * kostet echtes Geld. Sie wegzuwerfen würde die Auswertungen falsch machen, nicht sauberer.
     */
    public const NON_PERSON_TYPES = [
        self::TYPE_FUNCTION,
        self::TYPE_ADMIN,
        self::TYPE_SERVICE,
    ];

    protected $table = 'asset_holders';

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Platform\AssetManager\Database\Factories\AssetHolderFactory::new();
    }

    protected $fillable = [
        'team_id',
        'tenant_id',
        'user_principal_name',
        'display_name',
        'email',
        'department',
        'cost_center',
        'cost_center_id',
        'job_title',
        'mobile_phone',
        'business_phone',
        'phone_overridden',
        'sim_number',
        'contract_number',
        'data_volume',
        'is_active',
        'holder_type',
        // Zugehöriges System eines Funktionskontos — zusammen mit der Kostenstelle die zweite
        // Pflichtangabe, ohne die die Lizenz-Verteilungs-Kaskade ein Funktionskonto nicht
        // bevorzugt behandelt. Freitext, weil „das System" je Kunde etwas anderes ist.
        'function_system',
        // DEPRECATED (ADR 0017): durch holder_type ersetzt. Bleibt im fillable, solange die Spalte als
        // Sicherheitsnetz existiert — ein Rollback des Deploys soll die Typinformation nicht verlieren.
        'account_type',
        'source',
        'graph_id',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'phone_overridden' => 'boolean',
        'raw_data'         => 'array',
        'synced_at'        => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), zu dem dieser Asset-Träger gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(AssetCostCenter::class, 'cost_center_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'assignee_id');
    }

    /** Zuordnungs-Verlauf dieser Person über Items UND Geräte (Frage 6 / 2b). */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'holder_id')->orderByDesc('assigned_at');
    }

    /** True wenn Funktionskonto (trägt Kosten ohne echte Person). */
    public function isFunctionAccount(): bool
    {
        return $this->holder_type === self::TYPE_FUNCTION;
    }

    /** True wenn der Träger keine natürliche Person der Organisation ist (function/admin/service). */
    public function isNonPerson(): bool
    {
        return in_array($this->holder_type, self::NON_PERSON_TYPES, true);
    }

    /** Anzeigename des Träger-Typs. */
    public function holderTypeLabel(): string
    {
        return self::holderTypeLabelFor($this->holder_type);
    }

    /**
     * Anzeigename eines Träger-Typs ohne Instanz — für Auswahllisten und Sammelaktionen, die einen
     * Zieltyp beschriften, bevor es einen Träger dazu gibt (Muster: {@see AssetDevice::lifecycleLabelFor()}).
     */
    public static function holderTypeLabelFor(?string $type): string
    {
        return match ($type) {
            self::TYPE_FUNCTION => 'Funktionskonto',
            self::TYPE_ADMIN    => 'Admin-Account',
            self::TYPE_SERVICE  => 'Service-/Technik-Account',
            self::TYPE_EXTERNAL => 'Extern',
            default             => 'Person',
        };
    }

    /**
     * Badge-Farbe des Träger-Typs. Nur Farbfamilien verwenden, die im Tailwind-Build vorkommen —
     * dynamische Klassen aus unbenutzten Familien fehlen sonst zur Laufzeit (siehe ADR 0011).
     */
    public function holderTypeBadgeColor(): string
    {
        return match ($this->holder_type) {
            self::TYPE_FUNCTION => 'violet',
            self::TYPE_ADMIN    => 'amber',
            self::TYPE_SERVICE  => 'slate',
            self::TYPE_EXTERNAL => 'sky',
            default             => 'emerald',
        };
    }

    /** Nur echte Personen (Default-Sicht der Listen). */
    public function scopePersons($query)
    {
        return $query->where('holder_type', self::TYPE_PERSON);
    }

    /** Nur Nicht-Personen (Funktionskonten, Admin-/Service-Accounts). */
    public function scopeNonPersons($query)
    {
        return $query->whereIn('holder_type', self::NON_PERSON_TYPES);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetItem::class, 'assignee_id')
            ->whereNull('asset_items.deleted_at');
    }

    /**
     * NUR lazy nutzen (`$holder->licenses` / `$holder->licenses()->…`), NICHT eager laden:
     * Die Einschränkung greift auf `$this->team_id` zu. Beim Eager-Loading baut Eloquent die
     * Relation auf einer FRISCHEN Instanz — team_id ist dort null, die Bedingung wird zu
     * `team_id = null` und `with('licenses')` liefert stumm eine leere Menge.
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(AssetUserLicense::class, 'user_principal_name', 'user_principal_name')
            ->where('asset_user_licenses.team_id', $this->team_id);
    }

    /** Wie {@see licenses()}: team-gebundene Einschränkung → nur lazy, nie eager laden. */
    public function devices(): HasMany
    {
        return $this->hasMany(AssetDevice::class, 'user_principal_name', 'user_principal_name')
            ->where('asset_devices.team_id', $this->team_id);
    }

    /**
     * Anzeigename (Fallback: UPN).
     */
    public function getNameAttribute(): string
    {
        return $this->display_name ?: $this->user_principal_name;
    }

    /**
     * Initialen für Avatar.
     */
    public function initials(): string
    {
        $name = trim($this->display_name ?: $this->user_principal_name);
        if (!$name) return '?';
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }
        return strtoupper(mb_substr($name, 0, 2));
    }
}
