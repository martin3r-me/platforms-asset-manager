<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Support\LicenseSeatCascade;

/**
 * Bindet die {@see LicenseSeatCascade} an die Datenbank: lädt Vertragszeilen, Lizenzzuweisungen und
 * Träger eines Abrechnungsmonats, verteilt die bezahlten Mengen und schreibt das Ergebnis fest.
 *
 * Läuft nach jedem Rechnungsimport **und** immer dann, wenn sich eine Zuordnung ändert — eine neu
 * zugeordnete Rechnungsposition oder ein neu gepflegtes Funktionskonto verschiebt Mengen, und das
 * muss sich sofort in der Kostenaufteilung niederschlagen.
 *
 * Der Lauf ist **idempotent**: er setzt die Preise der betroffenen Lizenzen zunächst zurück und
 * verteilt komplett neu. Ohne das Zurücksetzen bliebe der Preis eines Seats stehen, dessen
 * Vertragszeile es nicht mehr gibt — ein Betrag ohne Beleg, der in der Kostenaufteilung weiterläuft.
 */
class LicenseSeatAllocator
{
    public function __construct(private LicenseSeatCascade $cascade = new LicenseSeatCascade()) {}

    /**
     * Mengen eines Abrechnungsmonats auf die Lizenzträger verteilen.
     *
     * @return array<string, mixed> Statistik je Lizenz + Bilanz + Befunde
     */
    public function allocateForPeriod(int $teamId, int $tenantId, string $periodLabel, bool $dryRun = false): array
    {
        $lines = AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->forPeriod($periodLabel)
            ->matched()
            ->get();

        if ($lines->isEmpty()) {
            return [
                'period'   => $periodLabel,
                'skus'     => [],
                'totals'   => $this->emptyTotals(),
                'balanced' => true,
            ];
        }

        $skuIds = $lines->pluck('sku_id')->unique()->values();

        $assignments = AssetUserLicense::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->whereIn('sku_id', $skuIds)
            ->get();

        $holders      = $this->holderIndex($teamId, $tenantId);
        $previousKeys = $this->previousEditionKeys($assignments, $teamId, $tenantId);

        $result = [
            'period'   => $periodLabel,
            'skus'     => [],
            'totals'   => $this->emptyTotals(),
            'balanced' => true,
        ];

        foreach ($lines->groupBy('sku_id') as $skuId => $skuLines) {
            $result['skus'][] = $this->allocateSku(
                (string) $skuId,
                $skuLines,
                $assignments->where('sku_id', $skuId)->values(),
                $holders,
                $previousKeys,
                $periodLabel,
                $dryRun,
                $result['totals'],
                $result['balanced'],
            );
        }

        return $result;
    }

    /**
     * @param Collection<int, AssetLicenseContractLine> $skuLines
     * @param Collection<int, AssetUserLicense>         $assignments
     * @param array<string, AssetHolder>                $holders
     * @param array<int, string>                        $previousKeys
     * @param array<string, float|int>                  $totals
     */
    private function allocateSku(
        string $skuId,
        Collection $skuLines,
        Collection $assignments,
        array $holders,
        array $previousKeys,
        string $periodLabel,
        bool $dryRun,
        array &$totals,
        bool &$balanced,
    ): array {
        $receivers = $assignments->map(function (AssetUserLicense $assignment) use ($holders, $previousKeys) {
            $holder = $holders[$this->upnKey($assignment->user_principal_name)] ?? null;

            return [
                'id'           => $assignment->id,
                'upn'          => (string) $assignment->user_principal_name,
                'is_function'  => $holder?->holder_type === AssetHolder::TYPE_FUNCTION,
                // Beide Pflichtangaben eines Funktionskontos: ohne sie kein Vorrang (Schritt 1).
                'complete'     => $holder !== null
                    && trim((string) $holder->function_system) !== ''
                    && $holder->cost_center_id !== null,
                'previous_key' => $previousKeys[$assignment->contract_line_id] ?? null,
            ];
        })->all();

        $distribution = $this->cascade->distribute(
            $skuLines->map(fn (AssetLicenseContractLine $line) => [
                'id'          => $line->id,
                'edition_key' => $line->edition_key,
                'binding'     => $line->binding,
                'quantity'    => (float) $line->quantity,
                'unit_price'  => (float) $line->unit_price,
            ])->all(),
            $receivers,
        );

        if (! $dryRun) {
            $this->persist($skuLines, $assignments, $distribution, $periodLabel);
        }

        return $this->summarize($skuId, $skuLines, $assignments, $distribution, $totals, $balanced);
    }

    /**
     * @param Collection<int, AssetLicenseContractLine> $skuLines
     * @param Collection<int, AssetUserLicense>         $assignments
     * @param array<string, mixed>                     $distribution
     */
    private function persist(
        Collection $skuLines,
        Collection $assignments,
        array $distribution,
        string $periodLabel,
    ): void {
        foreach ($assignments as $assignment) {
            $seat = $distribution['seats'][(string) $assignment->id] ?? null;

            // Auch der Nicht-Treffer wird geschrieben: ein Seat, der diesen Monat von keiner
            // Vertragszeile bezahlt wird, muss seinen alten Preis verlieren.
            $assignment->forceFill([
                'unit_price'       => $seat !== null ? round($seat['unit_price'], 4) : null,
                'price_source'     => $seat !== null ? AssetUserLicense::PRICE_SOURCE_CONTRACT : null,
                'price_period'     => $seat !== null ? $periodLabel : null,
                'contract_line_id' => $seat !== null ? $seat['line_id'] : null,
            ])->save();
        }

        foreach ($skuLines as $line) {
            $key      = (string) $line->id;
            $assigned = count(array_filter(
                $distribution['seats'],
                fn ($seat) => (string) $seat['line_id'] === $key,
            ));

            $line->forceFill([
                'assigned_quantity' => (float) $assigned,
                'overflow_quantity' => (float) ($distribution['overflow'][$key] ?? 0),
                'pool_quantity'     => (float) ($distribution['pool'][$key] ?? 0),
            ])->save();
        }
    }

    /**
     * @param Collection<int, AssetLicenseContractLine> $skuLines
     * @param Collection<int, AssetUserLicense>         $assignments
     * @param array<string, mixed>                      $distribution
     * @param array<string, float|int>                  $totals
     */
    private function summarize(
        string $skuId,
        Collection $skuLines,
        Collection $assignments,
        array $distribution,
        array &$totals,
        bool &$balanced,
    ): array {
        $invoiceQuantity = round($skuLines->sum(fn ($l) => (float) $l->quantity), 2);
        $invoiceAmount   = round($skuLines->sum(fn ($l) => (float) $l->unit_price * (float) $l->quantity), 2);

        $seatAmount = round(array_sum(array_map(
            fn ($seat) => $seat['unit_price'],
            $distribution['seats'],
        )), 2);

        $overflowAmount = $this->amountFor($skuLines, $distribution['overflow']);
        $poolAmount     = $this->amountFor($skuLines, $distribution['pool']);

        $placed = round(count($distribution['seats'])
            + array_sum($distribution['overflow'])
            + array_sum($distribution['pool']), 2);

        // Die harte Bilanz: jede bezahlte Menge liegt an einem Seat oder auf einer Sammelstelle.
        $lineBalanced = abs($placed - $invoiceQuantity) < 0.005;

        if (! $lineBalanced) {
            $balanced = false;
        }

        $totals['invoice_quantity'] += $invoiceQuantity;
        $totals['invoice_amount']   += $invoiceAmount;
        $totals['seat_amount']      += $seatAmount;
        $totals['overflow_amount']  += $overflowAmount;
        $totals['pool_amount']      += $poolAmount;
        $totals['seats']            += count($distribution['seats']);
        $totals['overflow_seats']   += (int) round(array_sum($distribution['overflow']));
        $totals['pool_seats']       += (int) round(array_sum($distribution['pool']));
        $totals['unpaid_seats']     += count($distribution['unpaid']);

        return [
            'sku_id'           => $skuId,
            'sku_part_number'  => $skuLines->first()->sku?->sku_part_number,
            'lines'            => $skuLines->count(),
            'invoice_quantity' => $invoiceQuantity,
            'invoice_amount'   => $invoiceAmount,
            'assigned_seats'   => count($distribution['seats']),
            'assigned_amount'  => $seatAmount,
            'overflow_seats'   => (int) round(array_sum($distribution['overflow'])),
            'overflow_amount'  => $overflowAmount,
            'pool_seats'       => (int) round(array_sum($distribution['pool'])),
            'pool_amount'      => $poolAmount,
            // Zugewiesen, aber von keiner Position bezahlt: mehr Seats im Tenant als auf der Rechnung.
            'unpaid_seats'     => count($distribution['unpaid']),
            'unpaid_upns'      => $assignments
                ->whereIn('id', $distribution['unpaid'])
                ->pluck('user_principal_name')
                ->take(20)
                ->values()
                ->all(),
            'incomplete_function_accounts' => count($distribution['incomplete_functions']),
            'balanced'         => $lineBalanced,
        ];
    }

    /**
     * @param Collection<int, AssetLicenseContractLine> $lines
     * @param array<string, float>                      $quantities
     */
    private function amountFor(Collection $lines, array $quantities): float
    {
        $sum = 0.0;

        foreach ($quantities as $lineId => $quantity) {
            $line = $lines->firstWhere('id', (int) $lineId);

            if ($line !== null) {
                $sum += (float) $line->unit_price * $quantity;
            }
        }

        return round($sum, 2);
    }

    /**
     * Träger je normalisierter UPN. Kleinschreibung, weil Entra die Kennung in gemischter Schreibweise
     * liefert und ein Vergleich der Rohwerte je nach Datensatz trifft oder nicht.
     *
     * @return array<string, AssetHolder>
     */
    private function holderIndex(int $teamId, int $tenantId): array
    {
        return AssetHolder::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->get(['id', 'user_principal_name', 'holder_type', 'function_system', 'cost_center_id'])
            ->keyBy(fn (AssetHolder $holder) => $this->upnKey($holder->user_principal_name))
            ->all();
    }

    /**
     * Die Vertragszeile, auf der ein Seat zuletzt saß — als `edition_key`, weil die Zeilen monatlich
     * neu entstehen und ihre IDs damit wechseln. Grundlage der stabilen Zuordnung.
     *
     * @param Collection<int, AssetUserLicense> $assignments
     * @return array<int, string>
     */
    private function previousEditionKeys(Collection $assignments, int $teamId, int $tenantId): array
    {
        $ids = $assignments->pluck('contract_line_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->pluck('edition_key', 'id')
            ->all();
    }

    private function upnKey(?string $upn): string
    {
        return mb_strtolower(trim((string) $upn), 'UTF-8');
    }

    /** @return array<string, float|int> */
    private function emptyTotals(): array
    {
        return [
            'invoice_quantity' => 0.0,
            'invoice_amount'   => 0.0,
            'seat_amount'      => 0.0,
            'overflow_amount'  => 0.0,
            'pool_amount'      => 0.0,
            'seats'            => 0,
            'overflow_seats'   => 0,
            'pool_seats'       => 0,
            'unpaid_seats'     => 0,
        ];
    }
}
