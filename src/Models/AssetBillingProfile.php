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
        'order_number',
        'due_in_days',
        'commerce_sku',
        'fallback_unit_price_cents',
        'basis',
        'count_external',
        'exclude_domains',
        'exclude_patterns',
        'vat_percent',
        'currency',
        'active',
    ];

    protected $casts = [
        'exclude_domains'           => 'array',
        'exclude_patterns'          => 'array',
        'active'                    => 'boolean',
        'count_external'            => 'boolean',
        'easybill_customer_id'      => 'integer',
        'fallback_unit_price_cents' => 'integer',
        'vat_percent'               => 'integer',
        'due_in_days'               => 'integer',
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

    /**
     * Vergleichsarten der Ausschlussmuster — identisch zu den `holder_type_rules` des
     * {@see \Platform\AssetManager\Services\HolderClassifier}. Zwei verschiedene Mustersprachen
     * in einem Modul wären für den Pflegenden nicht zu merken.
     */
    public const MATCH_MODES = ['prefix', 'suffix', 'contains', 'equals'];

    /** Felder, auf die ein Ausschlussmuster zeigen darf. */
    public const MATCH_FIELDS = ['upn', 'email', 'display_name'];

    /**
     * Die auszuschließenden Namensmuster, validiert und kleingeschrieben (ADR 0024).
     *
     * Validiert und nicht bloß durchgereicht, aus demselben Grund wie beim Classifier: eine kaputt
     * eingetragene Regel darf den Rechnungslauf nicht abstürzen lassen, sondern nur nichts tun. Ein
     * abgestürzter Lauf am Monatsersten kostet mehr als eine wirkungslose Regel.
     *
     * @return array<int, array{field: string, match: string, value: string, label: string|null}>
     */
    public function normalizedExcludePatterns(): array
    {
        return collect($this->exclude_patterns ?? [])
            ->filter(fn ($rule) => is_array($rule)
                && in_array($rule['field'] ?? null, self::MATCH_FIELDS, true)
                && in_array($rule['match'] ?? null, self::MATCH_MODES, true)
                && trim((string) ($rule['value'] ?? '')) !== '')
            ->map(fn (array $rule) => [
                'field' => $rule['field'],
                'match' => $rule['match'],
                'value' => mb_strtolower(trim((string) $rule['value'])),
                'label' => isset($rule['label']) && trim((string) $rule['label']) !== ''
                    ? trim((string) $rule['label'])
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Zählt dieses Profil Externe mit?
     *
     * Default `true` — das ist das Verhalten, das jedes vor ADR 0024 angelegte Profil hatte
     * (`external` steht nicht in {@see AssetHolder::NON_PERSON_TYPES}). Eine Migration darf die Menge
     * einer laufenden Abrechnung nicht ändern.
     */
    public function countsExternal(): bool
    {
        return $this->count_external === null ? true : (bool) $this->count_external;
    }

    /** Ist das Profil vollständig genug, um daraus einen Beleg zu erzeugen? */
    public function isSendable(): bool
    {
        return $this->active
            && $this->easybill_customer_id !== null
            && ($this->commerce_sku !== null || $this->fallback_unit_price_cents !== null);
    }
}
