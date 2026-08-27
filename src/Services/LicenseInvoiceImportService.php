<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetLicenseBillingAlias;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Support\LicenseEdition;
use Platform\AssetManager\Support\LicenseInvoiceCsv;
use Platform\AssetManager\Support\LicenseSkuMatcher;

/**
 * Import der Lizenz-Abrechnung des Wiederverkäufers (CSV-Export „Abrechnungsdetails").
 *
 * Der Lauf macht drei Dinge, und die Reihenfolge ist wesentlich:
 *
 * 1. **Vertragszeilen anlegen** — je Rechnungsposition eine Zeile mit Menge, Monatspreis und Bindung
 *    ({@see LicenseInvoiceCsv} rechnet das aus der Datei heraus).
 * 2. **Zuordnen** — jede Zeile bekommt ihre Microsoft-Graph-Lizenz, über einen gelernten Alias oder
 *    über {@see LicenseSkuMatcher}. Was unsicher bleibt, bleibt offen statt geraten.
 * 3. **Verteilen** — {@see LicenseSeatAllocator} legt fest, welcher Seat von welcher Zeile bezahlt
 *    wird. Erst danach stehen Preise an den Lizenzzuweisungen und damit in der Kostenaufteilung.
 *
 * ## Was der Import nicht anfasst
 *
 * Handpflege gewinnt gegen die Datei, wo die Datei nichts zu sagen hat: eine **bestätigte** Bindung
 * und ein gepflegtes `cancellable_at` überschreibt er nie — beides steht in keiner Rechnung. Eine
 * Zuordnung, die ein Mensch gesetzt hat, bleibt ebenfalls stehen.
 *
 * Umgekehrt gewinnt die Rechnung beim **Preis**: ein handgepflegter Stückpreis an einer Lizenz, die
 * jetzt auf der Rechnung steht, wird geleert und der alte Wert gemeldet. Zwei Preisquellen für
 * dieselbe Lizenz wären eine stille Fehlerquelle — welcher gilt, könnte niemand mehr sagen.
 *
 * ## Erneuter Import desselben Monats
 *
 * Die Identität einer Vertragszeile ist Tenant + Abrechnungsmonat + Position (`import_hash`). Ein
 * zweiter Lauf über dieselbe Datei aktualisiert also, statt zu verdoppeln — und Positionen, die aus
 * einer korrigierten Datei verschwunden sind, werden für diesen Monat entfernt.
 */
class LicenseInvoiceImportService
{
    public const SOURCE = 'license_invoice';

    protected int    $teamId   = 0;
    protected int    $tenantId = 0;
    protected string $batchId  = '';
    protected bool   $dryRun   = true;

    /** @var list<string> import_hash jeder in diesem Lauf geschriebenen Zeile — Basis des Prune. */
    protected array $writtenHashes = [];

    public function __construct(
        protected LicenseInvoiceCsv $csv = new LicenseInvoiceCsv(),
        protected ?LicenseSeatAllocator $allocator = null,
    ) {
        $this->allocator ??= new LicenseSeatAllocator();
    }

    /**
     * Rechnung einlesen, Vertragszeilen ausrichten und die Mengen verteilen.
     *
     * @return array<string, mixed> Statistik, Prüfsummen und Befunde
     */
    public function import(
        int $teamId,
        string $path,
        string $batchId = 'license-upload',
        bool $dryRun = true,
        ?int $tenantId = null,
    ): array {
        $this->teamId        = $teamId;
        $this->tenantId      = $tenantId ?? TenantContext::defaultTenantId($teamId);
        $this->batchId       = $batchId;
        $this->dryRun        = $dryRun;
        $this->writtenHashes = [];

        // Der Importer läuft ohne UI-Kontext (Upload/Console) — Tenant für den Global Scope setzen.
        TenantContext::forceTenant($this->tenantId);

        $parsed = $this->csv->parse($path);
        $period = $parsed['period'];

        DB::beginTransaction();

        try {
            $skus    = $this->skuCatalog();
            $matcher = new LicenseSkuMatcher($skus);
            $aliases = $this->aliases();

            $lines    = [];
            $unmatched = [];

            foreach ($parsed['positions'] as $position) {
                $line = $this->upsertLine($position, $period, $matcher, $aliases, $skus);
                $lines[] = $line;

                if (! $line['matched']) {
                    $unmatched[] = [
                        'edition'     => $position['edition'],
                        'edition_key' => $position['edition_key'],
                        'quantity'    => $position['quantity'],
                        'unit_price'  => $position['unit_price'],
                        'amount'      => round((float) $position['unit_price'] * (float) $position['quantity'], 2),
                        'note'        => $line['note'],
                    ];
                }
            }

            $pruned      = $this->pruneStaleLines($period['label']);
            $priceMoves  = $this->markContractPricedSkus();
            $allocation  = $this->allocator->allocateForPeriod(
                $this->teamId,
                $this->tenantId,
                $period['label'],
                $this->dryRun,
            );

            $result = [
                'dry_run'    => $this->dryRun,
                'period'     => [
                    'label' => $period['label'],
                    'from'  => $period['from']->format('d.m.Y'),
                    'to'    => $period['to']->format('d.m.Y'),
                    'days'  => $period['days'],
                ],
                'currency'   => $parsed['currency'],
                'positions'  => count($parsed['positions']),
                'matched'    => count($lines) - count($unmatched),
                'unmatched'  => $unmatched,
                'lines'      => $lines,
                'pruned'     => $pruned,
                'price_moves' => $priceMoves,
                'allocation' => $allocation,
                'checks'     => $this->checks($parsed, $allocation),
            ];

            $this->dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Eine Vertragszeile anlegen oder aktualisieren.
     *
     * @param array<string, mixed>                                     $position
     * @param array{from: \Illuminate\Support\Carbon, to: \Illuminate\Support\Carbon, days: int, label: string} $period
     * @param array<string, ?string>                                   $aliases
     * @param list<array<string, mixed>>                               $skus
     * @return array<string, mixed>
     */
    protected function upsertLine(
        array $position,
        array $period,
        LicenseSkuMatcher $matcher,
        array $aliases,
        array $skus,
    ): array {
        $hash = sha1(implode('|', [
            self::SOURCE,
            $this->teamId,
            $this->tenantId,
            $period['label'],
            $position['edition_key'],
        ]));

        $existing = AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $this->teamId)
            ->where('import_hash', $hash)
            ->first();

        [$skuId, $matchState, $matchNote] = $this->resolveSku($position, $matcher, $aliases, $existing);

        $values = [
            'period_from'      => $period['from'],
            'period_to'        => $period['to'],
            'period_label'     => $period['label'],
            'period_days'      => $period['days'],
            'product'          => $position['product'],
            'edition'          => $position['edition'],
            'edition_key'      => $position['edition_key'],
            'quantity'         => $position['quantity'],
            'list_price'       => $position['list_price'],
            'discount_percent' => $position['discount_percent'],
            'unit_price'       => $position['unit_price'] ?? 0,
            'amount_net'       => $position['amount_net'],
            'sku_id'           => $skuId,
            'match_state'      => $matchState,
            'match_note'       => $matchNote,
            'import_batch_id'  => $this->batchId,
            'raw_data'         => [
                'price_basis'            => $position['price_basis'],
                'price_check_difference' => $position['price_check_difference'],
                'discount_rates'         => $position['discount_rates'],
                'discount_labels'        => $position['discount_labels'],
                'periods'                => $position['periods'],
                'quantity_weighted'      => $position['quantity_weighted'],
                'item_rows'              => $position['item_rows'],
                'discount_rows'          => $position['discount_rows'],
            ],
        ];

        // Bindung und Kündigungsdatum sind Handpflege, sobald sie bestätigt sind — die Rechnung nennt
        // beides nicht als Feld, sie liefert nur ein Signal für die Vorbelegung.
        if ($existing === null) {
            $values['binding']           = $position['binding'];
            $values['binding_confirmed'] = false;
        } elseif (! $existing->binding_confirmed) {
            $values['binding'] = $position['binding'];
        }

        if ($existing !== null) {
            $existing->update($values);
            $line = $existing;
        } else {
            $line = AssetLicenseContractLine::create($values + [
                'team_id'     => $this->teamId,
                'tenant_id'   => $this->tenantId,
                'currency'    => 'EUR',
                'import_hash' => $hash,
            ]);
        }

        $this->writtenHashes[] = $hash;

        return [
            'id'          => $line->id,
            'edition'     => $line->edition,
            'edition_key' => $line->edition_key,
            'quantity'    => (float) $line->quantity,
            'unit_price'  => (float) $line->unit_price,
            'list_price'  => $line->list_price !== null ? (float) $line->list_price : null,
            'discount'    => (float) $line->discount_percent,
            'binding'     => $line->binding,
            'amount'      => $line->monthlyAmount(),
            'sku_id'      => $line->sku_id,
            'sku'         => $this->partNumberFor($line->sku_id, $skus),
            'match_state' => $line->match_state,
            'matched'     => $line->isMatched(),
            'note'        => $line->match_note,
        ];
    }

    /**
     * Lizenz für eine Position bestimmen. Reihenfolge und Begründung:
     *
     * 1. **Handzuordnung der bestehenden Zeile** — eine menschliche Entscheidung überlebt jeden Import.
     * 2. **Gelernter Alias** — die einmal getroffene Entscheidung für diesen Positionsnamen.
     * 3. **Automatische Erkennung**, deren Treffer sofort als Alias festgeschrieben wird. Das ist der
     *    Grund, warum ältere Rechnungen später ebenfalls zuordnen: die Mengenbestätigung des Matchers
     *    greift nur beim aktuellen Bestand — im Mai hat Exchange Online Archiving 6 Seats, heute 7.
     *    Ohne das Gedächtnis würde dieselbe Position je Monat anders behandelt, und die Verteilung
     *    wäre nicht stabil.
     *
     * @param array<string, mixed>   $position
     * @param array<string, ?string> $aliases
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    protected function resolveSku(
        array $position,
        LicenseSkuMatcher $matcher,
        array &$aliases,
        ?AssetLicenseContractLine $existing,
    ): array {
        if ($existing !== null && $existing->match_state === AssetLicenseContractLine::MATCH_MANUAL) {
            return [$existing->sku_id, AssetLicenseContractLine::MATCH_MANUAL, $existing->match_note];
        }

        $key = $position['edition_key'];

        if (array_key_exists($key, $aliases)) {
            $skuId = $aliases[$key];

            return $skuId === null
                ? [null, AssetLicenseContractLine::MATCH_IGNORED, 'Bewusst keiner Lizenz zugeordnet.']
                : [$skuId, AssetLicenseContractLine::MATCH_ALIAS, 'Über gelernte Zuordnung.'];
        }

        $match = $matcher->match($position['edition'], (float) $position['quantity']);

        if ($match['sku_id'] !== null) {
            $this->learnAlias($key, $match['sku_id'], $match['note']);
            $aliases[$key] = $match['sku_id'];
        }

        return [$match['sku_id'], $match['state'], $match['note']];
    }

    /** Automatisch erkannte Zuordnung festschreiben, damit sie über Monate hinweg gleich bleibt. */
    protected function learnAlias(string $editionKey, string $skuId, ?string $note): void
    {
        AssetLicenseBillingAlias::withoutTenantScope()->updateOrCreate(
            ['team_id' => $this->teamId, 'tenant_id' => $this->tenantId, 'edition_key' => $editionKey],
            ['sku_id' => $skuId, 'note' => 'Automatisch erkannt: ' . $note],
        );
    }

    /**
     * Lizenzen, die jetzt auf der Rechnung stehen, auf die Vertragszeilen-Preisquelle umstellen.
     *
     * Ein handgepflegter Stückpreis wird dabei geleert und gemeldet — die Rechnung ist die belegte
     * Wahrheit, und zwei Preisquellen für dieselbe Lizenz wären nicht auflösbar. Der alte Wert
     * verschwindet nicht stillschweigend, sondern erscheint im Ergebnis des Laufs.
     *
     * @return list<array<string, mixed>>
     */
    protected function markContractPricedSkus(): array
    {
        $skuIds = AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->matched()
            ->pluck('sku_id')
            ->unique()
            ->filter()
            ->values();

        if ($skuIds->isEmpty()) {
            return [];
        }

        $moves = [];

        $skus = AssetLicenseSku::withoutTenantScope()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->whereIn('sku_id', $skuIds)
            ->get();

        foreach ($skus as $sku) {
            $previous = $sku->unit_price !== null ? (float) $sku->unit_price : null;

            if ($previous !== null) {
                $moves[] = [
                    'sku'           => $sku->sku_part_number,
                    'display_name'  => $sku->display_name,
                    'previous_price' => $previous,
                ];
            }

            $sku->forceFill([
                'unit_price'       => null,
                'price_source'     => AssetLicenseSku::PRICE_SOURCE_CONTRACT,
                'price_updated_at' => now(),
            ])->save();
        }

        return $moves;
    }

    /**
     * Zeilen dieses Abrechnungsmonats, die in der Datei nicht mehr vorkommen (korrigierte Rechnung).
     * Andere Monate bleiben unberührt — ein Import ist immer nur für seinen Monat zuständig.
     */
    protected function pruneStaleLines(string $periodLabel): int
    {
        if ($this->writtenHashes === []) {
            return 0; // leere/falsche Datei — nichts löschen (Guard wie beim Vodafone-Import)
        }

        return AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->forPeriod($periodLabel)
            ->whereNotIn('import_hash', array_values(array_unique($this->writtenHashes)))
            ->delete();
    }

    /**
     * Die Prüfungen des Laufs.
     *
     * `reconciles` und `prices_match` betreffen die **Datei**: rechnet die Summe der Positionen gegen
     * `Gesamtnetto` auf, und passt jeder Monatspreis zum abgerechneten Betrag? `quantities_balanced`
     * betrifft die **Verteilung**: liegt jede bezahlte Menge an einem Seat oder auf einer Sammelstelle?
     *
     * Die Betragsdifferenz aus untermonatlichen Zu- und Abgängen ist **kein** Fehler, sondern die
     * bewusste Folge des Monatserst-Snapshots: ein Seat kostet den Monatspreis, auch wenn die Rechnung
     * ihn nur für 24 Tage berechnet hat. Sie wird beziffert, damit der Unterschied erklärbar bleibt.
     *
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $allocation
     * @return array<string, mixed>
     */
    protected function checks(array $parsed, array $allocation): array
    {
        $priceMismatches = [];

        foreach ($parsed['positions'] as $position) {
            $difference = $position['price_check_difference'];

            if ($difference === null || abs($difference) > 0.02) {
                $priceMismatches[] = [
                    'edition'    => $position['edition'],
                    'difference' => $difference,
                ];
            }
        }

        $snapshotDifference = round(
            (float) $allocation['totals']['invoice_amount'] - (float) $parsed['sum_net'],
            2,
        );

        return [
            'reconciles'           => $parsed['reconciles'],
            'invoice_total_net'    => $parsed['total_net'],
            'positions_sum_net'    => $parsed['sum_net'],
            'difference'           => $parsed['difference'],
            'prices_match'         => $priceMismatches === [],
            'price_mismatches'     => $priceMismatches,
            'unassigned_discounts' => $parsed['unassigned_discounts'],
            'quantities_balanced'  => $allocation['balanced'],
            // Monatsvolle Seats gegen zeitanteilig abgerechnete Datei — erwartete Differenz.
            'snapshot_difference'  => $snapshotDifference,
        ];
    }

    /**
     * Lizenzkatalog des Tenants als flaches Array für den Matcher.
     *
     * @return list<array<string, mixed>>
     */
    protected function skuCatalog(): array
    {
        return AssetLicenseSku::withoutTenantScope()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->get(['sku_id', 'sku_part_number', 'display_name', 'purchased_units', 'consumed_units'])
            ->map(fn (AssetLicenseSku $sku) => [
                'sku_id'          => (string) $sku->sku_id,
                'sku_part_number' => (string) $sku->sku_part_number,
                'display_name'    => $sku->display_name,
                'purchased_units' => (int) $sku->purchased_units,
                'consumed_units'  => (int) $sku->consumed_units,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, ?string> edition_key => sku_id (null = bewusst nicht zugeordnet) */
    protected function aliases(): array
    {
        return AssetLicenseBillingAlias::withoutTenantScope()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->get(['edition_key', 'sku_id'])
            ->mapWithKeys(fn ($alias) => [$alias->edition_key => $alias->sku_id])
            ->all();
    }

    /** @param list<array<string, mixed>> $skus */
    protected function partNumberFor(?string $skuId, array $skus): ?string
    {
        if ($skuId === null) {
            return null;
        }

        foreach ($skus as $sku) {
            if ($sku['sku_id'] === $skuId) {
                return $sku['sku_part_number'];
            }
        }

        return null;
    }

    /**
     * Zuordnung von Hand setzen und als Alias lernen, dann neu verteilen.
     *
     * Zweiter Einstieg neben dem Import: die offen gebliebenen Positionen bekommen hier ihre Lizenz.
     * `$skuId === null` heißt „gehört zu keiner Lizenz" — eine Entscheidung, nach der die Position
     * nicht mehr als ungeklärt erscheint.
     *
     * @return array<string, mixed> Ergebnis der neuen Verteilung
     */
    public function assignSku(int $teamId, int $lineId, ?string $skuId, ?int $tenantId = null): array
    {
        $tenantId ??= TenantContext::defaultTenantId($teamId);
        TenantContext::forceTenant($tenantId);

        $line = AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->findOrFail($lineId);

        DB::transaction(function () use ($line, $skuId, $teamId, $tenantId) {
            $line->update([
                'sku_id'      => $skuId,
                'match_state' => $skuId === null
                    ? AssetLicenseContractLine::MATCH_IGNORED
                    : AssetLicenseContractLine::MATCH_MANUAL,
                'match_note'  => $skuId === null ? 'Von Hand ausgeschlossen.' : 'Von Hand zugeordnet.',
            ]);

            AssetLicenseBillingAlias::withoutTenantScope()->updateOrCreate(
                ['team_id' => $teamId, 'tenant_id' => $tenantId, 'edition_key' => $line->edition_key],
                ['sku_id' => $skuId, 'note' => 'Von Hand zugeordnet.', 'created_by' => Auth::id()],
            );
        });

        // Eine geänderte Zuordnung verschiebt Mengen — die Verteilung muss sofort nachziehen, sonst
        // stünde in der Kostenaufteilung ein Stand, den die Zuordnung nicht mehr hergibt.
        return $this->allocator->allocateForPeriod($teamId, $tenantId, $line->period_label, false);
    }
}
