<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Abrechnungsprofil: die Regel, nach der aus dem Bestand eine Rechnung an einen Kunden wird
 * (siehe docs/adr/0021).
 *
 * Es beantwortet drei Fragen und sonst keine: **an wen** (easybill-Kunde), **wofür** (Commerce-Artikel
 * und damit der Stückpreis) und **für wen** (die Zählregel). Was daraus tatsächlich wurde, steht im
 * {@see AssetBillingRun} — das Profil ist die Vorschrift, nicht ihr Ergebnis.
 */
class AssetBillingProfile extends Model
{
    use TenantScopable;

    protected $table = 'asset_billing_profiles';

    /**
     * Zählregeln.
     *
     * `licensed` — Träger mit mindestens einer zugewiesenen Microsoft-Lizenz. Die belastbarere der
     * beiden: eine Lizenz ist ein tatsächlich erbrachter, bezahlter Dienst, und Karteileichen wie
     * Admin- oder Dienstkonten ohne Lizenz fallen von selbst heraus.
     *
     * `all_holders` — jeder aktive Träger. Für Verträge, in denen die Betreuung am Kopf hängt und
     * nicht an der Lizenz. Zählt mehr, verlangt dafür aber gepflegte Trägertypen, sonst rechnet man
     * Funktions- und Administrationskonten mit ab.
     */
    public const BASIS_LICENSED    = 'licensed';
    public const BASIS_ALL_HOLDERS = 'all_holders';

    public const BASES = [
        self::BASIS_LICENSED,
        self::BASIS_ALL_HOLDERS,
    ];

    public const BASIS_LABELS = [
        self::BASIS_LICENSED    => 'Träger mit Microsoft-Lizenz',
        self::BASIS_ALL_HOLDERS => 'Alle aktiven Asset-Träger',
    ];

    protected $fillable = [
        'team_id',
        'tenant_id',
        'name',
        'easybill_customer_id',
        'easybill_customer_name',
        'commerce_sku',
        'fallback_unit_price_cents',
        'basis',
        'exclude_domains',
        'vat_percent',
        'currency',
        'active',
    ];

    protected $casts = [
        'exclude_domains'           => 'array',
        'active'                    => 'boolean',
        'easybill_customer_id'      => 'integer',
        'fallback_unit_price_cents' => 'integer',
        'vat_percent'               => 'integer',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AssetBillingRun::class, 'billing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function basisLabel(): string
    {
        return self::BASIS_LABELS[$this->basis] ?? $this->basis;
    }

    /**
     * Die auszuschließenden Domains, kleingeschrieben und ohne Leerzeichen — so, wie der Vergleich
     * gegen die UPN sie braucht. Die Normalisierung gehört hierher und nicht an jede Aufrufstelle:
     * eingetragen wird von Hand, und „BHGDigital.de " mit Leerzeichen darf nicht stillschweigend
     * dazu führen, dass 17 eigene Leute auf der Kundenrechnung landen.
     */
    public function normalizedExcludeDomains(): array
    {
        return collect($this->exclude_domains ?? [])
            ->map(fn ($d) => strtolower(trim((string) $d)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Ist das Profil vollständig genug, um daraus einen Beleg zu erzeugen? */
    public function isSendable(): bool
    {
        return $this->active
            && $this->easybill_customer_id !== null
            && ($this->commerce_sku !== null || $this->fallback_unit_price_cents !== null);
    }
}
