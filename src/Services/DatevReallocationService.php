<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Support\DatevReallocationCalculator;

/**
 * DATEV-Umbuchung eines Buchungsmonats aus den Monatsständen der Rechnungen (Baustein 2, Discussion #26).
 *
 * Bildet aus den {@see AssetInvoiceSnapshot} des Monats die Blöcke für den
 * {@see DatevReallocationCalculator} und rechnet sie durch. Schreibt nichts — die Datei und das Protokoll
 * (welche Monatsstände in welchem Stapel stecken) sind Baustein 4/5.
 *
 * ## Regeln
 *
 * - **Auswahl:** Monatsstände mit diesem Buchungsmonat (Leistungsmonat, Hendric 2026-10-16), die noch
 *   in keinem Stapel gebucht sind. Gebuchte werden gelistet, nie erneut verteilt.
 * - **Umstellung:** vor dem ersten umzubuchenden Leistungsmonat (`datev_start_month` am Stapelkopf) hat
 *   die Buchhaltung die Rechnungen selbst je KST verteilt (Hendric 2026-10-16) — alle offenen Stände
 *   dieser Monate sind „vorverteilt" und kommen nie in einen Stapel.
 * - **Direkt gebucht:** eine Rechnung, deren Verteilung vollständig auf genau einer Kostenstelle liegt,
 *   bucht die Buchhaltung direkt dorthin (Max 2026-10-16, Beispiel Internet Mönchengladbach → 2890) —
 *   sie kommt nicht in den Stapel, sonst stünde sie auf der Ziel-KST doppelt und auf 1520 negativ.
 * - **Rechnung × Anteil:** je Rechnung wird ihr Rechnungsnetto nach den Anteilen ihrer *gesamten*
 *   Verteilung aufgeteilt (Largest Remainder) — erst danach fallen Kostenarten ohne Sachkonto heraus.
 *   So bekommt jedes Sachkonto genau seinen Teil der Rechnung, und Rundungs-Cent einer Rechnung wandern
 *   nicht in eine andere.
 * - **Blöcke:** ein Block je Sachkonto × Kreditor (Sachkonto an der Kostenart, nie am Kreditor —
 *   Entscheidung 3). Das Sachkonto wird beim Stapelbau gelesen, nicht im Monatsstand eingefroren.
 * - **Leistung im Buchungstext:** Kreditor + Name der Kostenart mit dem größten Anteil im Block
 *   (z. B. „Vodafone Mobilfunk"). Kreditor je Quelle: alle drei Rechnungsimporte sind Vodafone-Belege —
 *   die Lizenz-Abrechnung ist die SaaS-Rechnung desselben Kontos (Controlling bucht sie als
 *   „Vodafone SaaS" auf 649500).
 */
class DatevReallocationService
{
    /** Kreditor im Buchungstext je Quelle des Monatsstands. */
    public const VENDOR_BY_SOURCE = [
        VodafoneInvoiceImportService::SOURCE  => 'Vodafone',
        VodafoneFixedNetImportService::SOURCE => 'Vodafone',
        LicenseInvoiceImportService::SOURCE   => 'Vodafone',
    ];

    /**
     * @param ?list<string> $knownCostCenters DATEV-KST-Liste; null = nicht prüfen
     * @param ?\DateTimeInterface $startMonth erster umzubuchender Leistungsmonat (Umstellung); davor
     *        verteilt die Buchhaltung selbst — alle offenen Stände gelten als „vorverteilt", kein Stapel
     * @return array{
     *     month: string,
     *     before_start: bool,
     *     snapshots: list<array{id: int, source: string, account: string, invoice: string, total_net: float}>,
     *     booked: list<array{id: int, source: string, account: string, invoice: string, total_net: float}>,
     *     direct: list<array{id: int, source: string, account: string, invoice: string, total_net: float, cost_center: string}>,
     *     predistributed: list<array{id: int, source: string, account: string, invoice: string, total_net: float}>,
     *     blocks: list<array{account: string, service: string, amount_cents: int, shares: array<string, int>}>,
     *     skipped: list<array{cost_type_key: ?string, invoice: string, amount_cents: int}>,
     *     bookings: list<array<string, mixed>>,
     *     violations: list<string>,
     *     findings: list<string>,
     *     summary: list<array{account: string, service: string, amount_cents: int, lines: int}>
     * }
     */
    public function prepare(
        int $teamId,
        int $tenantId,
        \DateTimeInterface $month,
        string $client,
        ?array $knownCostCenters = null,
        string $sourceCostCenter = '1520',
        ?\DateTimeInterface $startMonth = null,
    ): array {
        $first = \DateTimeImmutable::createFromInterface($month)->modify('first day of this month')->setTime(0, 0);
        $beforeStart = $startMonth !== null && $first->format('Y-m') < $startMonth->format('Y-m');

        $all = AssetInvoiceSnapshot::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->whereDate('booking_month', $first->format('Y-m-d'))
            ->orderBy('source')->orderBy('account')->orderBy('invoice')
            ->get();

        $booked  = $all->filter(fn (AssetInvoiceSnapshot $s) => $s->isBooked())->values();
        $unbooked = $all->reject(fn (AssetInvoiceSnapshot $s) => $s->isBooked())->values();

        // Vor der Umstellung: nichts umbuchen — die Buchhaltung hat diese Rechnungen selbst verteilt.
        $predistributed = $beforeStart ? $unbooked : collect();
        $pending = $beforeStart ? collect() : $unbooked;

        $direct = $pending->filter(fn (AssetInvoiceSnapshot $s) => self::directCostCenter($s) !== null)->values();
        $open   = $pending->filter(fn (AssetInvoiceSnapshot $s) => self::directCostCenter($s) === null)->values();

        $types = AssetCostType::withoutTenantScope()
            ->where('team_id', $teamId)
            ->get(['id', 'key', 'name', 'datev_account'])
            ->keyBy('id');

        $blocks   = [];
        $skipped  = [];
        $findings = [];

        foreach ($open as $snapshot) {
            $rows = array_values((array) $snapshot->distribution);
            $weights = array_map(fn ($r) => (int) round((float) $r['amount'] * 100), $rows);
            $total   = (int) round((float) $snapshot->total_net * 100);

            if (array_sum($weights) === 0) {
                if ($total !== 0) {
                    $findings[] = "Rechnung {$snapshot->invoice}: Verteilung summiert 0 — {$this->euro($total)} nicht umgebucht.";
                }
                continue;
            }

            // Rechnung × Anteil über ALLE Zeilen der Rechnung, Schlüssel = Zeilenindex.
            $cents  = DatevReallocationCalculator::largestRemainder($total, $weights);
            $vendor = self::VENDOR_BY_SOURCE[$snapshot->source] ?? $snapshot->source;

            foreach ($rows as $i => $row) {
                $type    = $types[$row['cost_type_id']] ?? null;
                $account = $type?->datev_account;

                if ($account === null || $account === '') {
                    $skipped[] = ['cost_type_key' => $row['cost_type_key'] ?? $type?->key, 'invoice' => $snapshot->invoice, 'amount_cents' => $cents[$i]];
                    continue;
                }

                $key = $account . '|' . $vendor;
                $blocks[$key] ??= ['account' => $account, 'vendor' => $vendor, 'amount_cents' => 0, 'shares' => [], 'by_type' => []];

                $code = (string) ($row['cost_center_code'] ?? '');
                $blocks[$key]['amount_cents'] += $cents[$i];
                $blocks[$key]['shares'][$code] = ($blocks[$key]['shares'][$code] ?? 0) + $cents[$i];
                $name = $type->name ?? (string) $row['cost_type_key'];
                $blocks[$key]['by_type'][$name] = ($blocks[$key]['by_type'][$name] ?? 0) + abs($cents[$i]);
            }
        }

        ksort($blocks, SORT_STRING);
        $blocks = array_values(array_map(function (array $b) {
            arsort($b['by_type']);
            ksort($b['shares'], SORT_NATURAL);

            return [
                'account'      => $b['account'],
                'service'      => $b['vendor'] . ' ' . array_key_first($b['by_type']),
                'amount_cents' => $b['amount_cents'],
                'shares'       => $b['shares'],
            ];
        }, $blocks));

        $skippedByType = [];
        foreach ($skipped as $s) {
            $skippedByType[(string) $s['cost_type_key']] = ($skippedByType[(string) $s['cost_type_key']] ?? 0) + $s['amount_cents'];
        }
        foreach ($skippedByType as $key => $cents) {
            $name = $types->firstWhere('key', $key)?->name ?? $key;
            $findings[] = "{$name}: {$this->euro($cents)} ohne DATEV-Sachkonto — nicht umgebucht (Stammdaten → Kostenart).";
        }

        $withoutMonth = AssetInvoiceSnapshot::withoutTenantScope()
            ->where('team_id', $teamId)->where('tenant_id', $tenantId)
            ->whereNull('booking_month')->count();
        if ($withoutMonth > 0) {
            $findings[] = "{$withoutMonth} Rechnung(en) ohne Buchungsmonat — sie fehlen in jedem Stapel.";
        }

        $calculation = (new DatevReallocationCalculator($client, $sourceCostCenter, $knownCostCenters))
            ->calculate($first, $blocks);

        $brief = fn (AssetInvoiceSnapshot $s) => [
            'id' => $s->id, 'source' => $s->source, 'account' => $s->account, 'invoice' => $s->invoice, 'total_net' => (float) $s->total_net,
        ];

        return [
            'month'      => $first->format('Y-m'),
            'before_start' => $beforeStart,
            'predistributed' => $predistributed->map($brief)->all(),
            'snapshots'  => $open->map($brief)->all(),
            'booked'     => $booked->map($brief)->all(),
            'direct'     => $direct->map(fn (AssetInvoiceSnapshot $s) => $brief($s) + ['cost_center' => (string) self::directCostCenter($s)])->all(),
            'blocks'     => $blocks,
            'skipped'    => $skipped,
            'bookings'   => $calculation['bookings'],
            'violations' => $calculation['violations'],
            'findings'   => $findings,
            'summary'    => $calculation['blocks'],
        ];
    }

    /**
     * KST einer Rechnung, die vollständig auf genau einer Kostenstelle liegt — die bucht die Buchhaltung
     * direkt dorthin. Zeilen mit 0 € zählen nicht (z. B. Grundpreis + gleich hoher Rabatt).
     * null = verteilt auf mehrere KST (oder ohne KST) → Umbuchung.
     */
    public static function directCostCenter(AssetInvoiceSnapshot $snapshot): ?string
    {
        $codes = [];
        foreach ((array) $snapshot->distribution as $row) {
            if (round((float) $row['amount'], 2) == 0.0) {
                continue;
            }
            $codes[(string) ($row['cost_center_code'] ?? '')] = true;
        }

        if (count($codes) !== 1 || array_key_first($codes) === '') {
            return null;
        }

        return (string) array_key_first($codes);
    }

    private function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }
}
