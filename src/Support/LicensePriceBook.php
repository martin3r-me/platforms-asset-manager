<?php

namespace Platform\AssetManager\Support;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetTeamSetting;
use Platform\AssetManager\Models\AssetUserLicense;

/**
 * Die eine Stelle, an der ein Lizenzpreis herkommt.
 *
 * Seit dem Rechnungsimport gibt es zwei Preisquellen, und jede Auswertung muss dieselbe Reihenfolge
 * anwenden, sonst weichen Dashboard, Pivot und Trägerdetail voneinander ab:
 *
 * 1. **Der Seat-Preis** aus der Vertragszeile (`asset_user_licenses.unit_price`) — belegt, tarifgenau.
 * 2. **Der handgepflegte SKU-Preis** — nur für Lizenzen, die auf keiner Rechnung stehen. Deckt die
 *    Rechnung eine Lizenz ab, ist dieses Feld geleert und kann nicht mehr dazwischenfunken.
 *
 * Dazu kommen die Mengen, die **keinem** Seat gehören und trotzdem bezahlt sind: der Überhang an
 * Monatslizenzen und der unbesetzte Pool. Beide gehören in die Kostenaufteilung, jeweils auf die im
 * Tenant konfigurierte Sammel-Kostenstelle — sonst fehlte in der Summe Geld, das die Rechnung sehr
 * wohl in Rechnung stellt.
 *
 * Lädt alles in wenigen Queries und beantwortet danach aus dem Speicher: die Aufrufer sind Schleifen
 * über Träger und Lizenzen, in denen eine Query je Durchlauf sofort zum N+1-Problem würde.
 */
final class LicensePriceBook
{
    /** @var array<string, self> je Team memoisiert (Instanz-Lebensdauer des Aufrufers) */
    private static array $instances = [];

    /** @var Collection<int, AssetUserLicense> */
    private Collection $assignments;

    /** @var Collection<string, float> sku_id => handgepflegter Preis */
    private Collection $manualPrices;

    /** @var Collection<int, AssetLicenseContractLine> Vertragszeilen des jüngsten Abrechnungsmonats */
    private Collection $lines;

    private ?string $period = null;

    private ?int $overflowCostCenterId = null;

    private ?int $poolCostCenterId = null;

    private function __construct(private int $teamId)
    {
        $this->load();
    }

    /**
     * Preisbuch für ein Team. Memoisiert, weil ein Pivot-Render es mehrfach braucht; der Tenant kommt
     * aus dem Global Scope, ein Wechsel innerhalb eines Requests kommt nicht vor.
     */
    public static function for(int $teamId): self
    {
        return self::$instances[$teamId] ??= new self($teamId);
    }

    /** Nur für Tests und lange laufende Prozesse (Jobs), die mehrere Teams hintereinander bearbeiten. */
    public static function flush(): void
    {
        self::$instances = [];
    }

    private function load(): void
    {
        $this->assignments = AssetUserLicense::where('team_id', $this->teamId)
            ->get(['id', 'sku_id', 'user_principal_name', 'unit_price', 'contract_line_id']);

        // Nur Lizenzen ohne Rechnungsdeckung tragen hier noch einen Preis — der Import leert das Feld,
        // sobald eine Vertragszeile die Lizenz abdeckt.
        $this->manualPrices = AssetLicenseSku::where('team_id', $this->teamId)
            ->whereNotNull('unit_price')
            ->pluck('unit_price', 'sku_id')
            ->map(fn ($price) => (float) $price);

        $this->period = AssetLicenseContractLine::where('team_id', $this->teamId)
            ->max('period_label');

        $this->lines = $this->period === null
            ? collect()
            : AssetLicenseContractLine::where('team_id', $this->teamId)
                ->forPeriod($this->period)
                ->matched()
                ->get();

        $settings = AssetTeamSetting::where('team_id', $this->teamId)->first();

        $this->overflowCostCenterId = $settings?->license_overflow_cost_center_id;
        $this->poolCostCenterId     = $settings?->license_pool_cost_center_id;
    }

    /** Der jüngste importierte Abrechnungsmonat („2026-07"), oder null ohne Rechnungsdaten. */
    public function period(): ?string
    {
        return $this->period;
    }

    public function hasContractData(): bool
    {
        return $this->lines->isNotEmpty();
    }

    /**
     * Preis eines Seats: Vertragspreis, sonst handgepflegter SKU-Preis, sonst 0.
     *
     * Die 0 ist ein möglicher, richtiger Wert — eine Gratis-Lizenz (FLOW_FREE, POWER_BI_STANDARD)
     * kostet nichts, und ein Seat, der auf keiner Rechnung auftaucht, darf nicht geschätzt werden.
     */
    public function seatPrice(AssetUserLicense $assignment): float
    {
        if ($assignment->unit_price !== null) {
            return (float) $assignment->unit_price;
        }

        return $this->manualPrices[$assignment->sku_id] ?? 0.0;
    }

    /**
     * Alle bezahlten Seats mit Preis > 0, je Kennung.
     *
     * @return Collection<int, array{upn: ?string, sku_id: string, amount: float}>
     */
    public function seatRows(): Collection
    {
        return $this->assignments
            ->map(fn (AssetUserLicense $a) => [
                'upn'    => $a->user_principal_name,
                'sku_id' => (string) $a->sku_id,
                'amount' => $this->seatPrice($a),
            ])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->values();
    }

    /**
     * Bezahlte Mengen ohne Seat, mit ihrer Sammel-Kostenstelle.
     *
     * `cost_center_id` kann null sein, wenn im Tenant keine Sammelstelle konfiguriert ist. Dann
     * erscheint der Betrag in der Kostenaufteilung unter „ohne Kostenstelle" — sichtbar und
     * korrigierbar, statt aus der Summe zu verschwinden.
     *
     * @return Collection<int, array{kind: string, sku_id: string, cost_center_id: ?int, seats: float, amount: float, edition: string, binding: string}>
     */
    public function unallocatedRows(): Collection
    {
        $rows = collect();

        foreach ($this->lines as $line) {
            foreach ([
                ['kind' => 'overflow', 'seats' => (float) $line->overflow_quantity, 'cc' => $this->overflowCostCenterId],
                ['kind' => 'pool',     'seats' => (float) $line->pool_quantity,     'cc' => $this->poolCostCenterId],
            ] as $bucket) {
                if ($bucket['seats'] <= 0) {
                    continue;
                }

                $rows->push([
                    'kind'           => $bucket['kind'],
                    'sku_id'         => (string) $line->sku_id,
                    'cost_center_id' => $bucket['cc'],
                    'seats'          => $bucket['seats'],
                    'amount'         => round((float) $line->unit_price * $bucket['seats'], 2),
                    'edition'        => (string) $line->edition,
                    'binding'        => (string) $line->binding,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Monatskosten einer Lizenz: bezahlte Seats plus die Mengen, die auf den Sammelstellen liegen.
     * Über alle Lizenzen summiert ergibt das den Rechnungsbetrag des Monats — bis auf die Differenz
     * aus untermonatlichen Zu- und Abgängen, die der Monatserst-Snapshot bewusst nicht abbildet.
     */
    public function monthlyCostForSku(string $skuId): float
    {
        $seats = $this->assignments
            ->where('sku_id', $skuId)
            ->sum(fn (AssetUserLicense $a) => $this->seatPrice($a));

        $unallocated = $this->unallocatedRows()
            ->where('sku_id', $skuId)
            ->sum('amount');

        return round($seats + $unallocated, 2);
    }

    /**
     * Die Tarife einer Lizenz — für die Anzeige, wo bisher ein Stückpreis stand. Eine Lizenz mit zwei
     * Tarifen hat keinen „den" Preis; gezeigt wird die Spanne.
     *
     * @return array{min: float, max: float, count: int}|null
     */
    public function priceRangeForSku(string $skuId): ?array
    {
        $prices = $this->lines->where('sku_id', $skuId)->pluck('unit_price')->map(fn ($p) => (float) $p);

        if ($prices->isEmpty()) {
            return null;
        }

        return ['min' => (float) $prices->min(), 'max' => (float) $prices->max(), 'count' => $prices->count()];
    }

    /**
     * Vertragszeilen einer Lizenz im jüngsten Monat.
     *
     * @return Collection<int, AssetLicenseContractLine>
     */
    public function linesForSku(string $skuId): Collection
    {
        return $this->lines->where('sku_id', $skuId)->values();
    }

    /**
     * Bezahlter Leerstand: der Pool-Anteil aus den Vertragszeilen. Belegt statt geschätzt — vorher
     * wurde dafür `available_units × Stückpreis` gerechnet, was bei mehreren Tarifen je Lizenz nicht
     * mehr bestimmt ist (welcher der beiden Preise stünde leer?).
     */
    public function unusedSavings(): float
    {
        return round($this->unallocatedRows()->where('kind', 'pool')->sum('amount'), 2);
    }

    /** Überhang an Monatslizenzen — die Kennzahl „auf Jahresbindung umstellbar". */
    public function overflowSavingsBase(): float
    {
        return round($this->unallocatedRows()->where('kind', 'overflow')->sum('amount'), 2);
    }
}
