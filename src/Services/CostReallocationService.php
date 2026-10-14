<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetCostLine;

/**
 * Umbuchungsbasis je Rechnung (Discussion #26, DATEV): welcher Betrag einer importierten Rechnung
 * landet auf welcher Kostenstelle, getrennt nach Kostenart.
 *
 * Das ist die fachliche Hälfte einer IT-Kostenumbuchung: die Buchhaltung bucht die Rechnung auf eine
 * Sammel-Kostenstelle und verteilt sie anschließend auf die nutzenden Kostenstellen. Die Verteilung
 * steht nach dem Rechnungsimport bereits in den Kostenpositionen — hier wird sie je Beleg verdichtet.
 * Wie daraus Buchungszeilen werden (Sachkonto, Gegenkonto, Buchungstext, Dateiformat), ist die zweite
 * Hälfte und hängt an den Vorgaben der Buchhaltung; dieser Service weiß davon bewusst nichts.
 *
 * Grundlage sind die Zeilen der Rechnungsimporte (`source` vodafone_invoice / vodafone_fixednet); sie
 * tragen Rechnungskonto und Rechnungsnummer in `raw_data`. Da jeder Import den Bestand seines Kontos auf
 * die zuletzt importierte Rechnung zieht, ist das immer die **aktuelle** Rechnung je Konto.
 */
class CostReallocationService
{
    /** Quellen, deren Zeilen einen Rechnungsbezug tragen. */
    public const SOURCES = [
        VodafoneInvoiceImportService::SOURCE,
        VodafoneFixedNetImportService::SOURCE,
    ];

    /**
     * @return list<array{
     *     source: string, account: string, invoice: string, period: ?string,
     *     total: float, lines: int, without_cost_center: float,
     *     rows: list<array{cost_center_id: ?int, cost_center: ?string, cost_type: string, amount: float, lines: int}>
     * }>
     */
    public function documents(int $teamId, ?string $source = null, ?string $account = null): array
    {
        $lines = AssetCostLine::with(['costCenter:id,code,name', 'costType:id,key,name'])
            ->where('team_id', $teamId)
            ->whereIn('source', $source !== null ? [$source] : self::SOURCES)
            ->active()
            ->get(['id', 'source', 'cost_center_id', 'cost_type_id', 'amount', 'period_label', 'raw_data']);

        $documents = [];

        foreach ($lines as $line) {
            $raw     = (array) $line->raw_data;
            $acct    = (string) ($raw['rechnungskonto'] ?? '');
            if ($account !== null && $acct !== $account) {
                continue;
            }

            $key = $line->source . '|' . $acct . '|' . ($raw['rechnung'] ?? '');
            $documents[$key] ??= [
                'source'              => $line->source,
                'account'             => $acct,
                'invoice'             => (string) ($raw['rechnung'] ?? ''),
                'period'              => $line->period_label,
                'total'               => 0.0,
                'lines'               => 0,
                'without_cost_center' => 0.0,
                'rows'                => [],
            ];

            $doc   = &$documents[$key];
            $type  = $line->costType?->name ?? '—';
            $rowKey = ($line->cost_center_id ?? 0) . '|' . $type;

            $doc['rows'][$rowKey] ??= [
                'cost_center_id' => $line->cost_center_id,
                'cost_center'    => $line->costCenter?->code,
                'cost_type'      => $type,
                'amount'         => 0.0,
                'lines'          => 0,
            ];

            $amount = (float) $line->amount;
            $doc['rows'][$rowKey]['amount'] += $amount;
            $doc['rows'][$rowKey]['lines']++;
            $doc['total'] += $amount;
            $doc['lines']++;
            if ($line->cost_center_id === null) {
                $doc['without_cost_center'] += $amount;
            }
            unset($doc);
        }

        foreach ($documents as &$doc) {
            $doc['total']               = round($doc['total'], 2);
            $doc['without_cost_center'] = round($doc['without_cost_center'], 2);
            $doc['rows'] = array_values(array_map(fn ($r) => ['amount' => round($r['amount'], 2)] + $r, $doc['rows']));
            usort($doc['rows'], fn ($a, $b) => [(string) $a['cost_center'], $a['cost_type']] <=> [(string) $b['cost_center'], $b['cost_type']]);
        }
        unset($doc);

        return array_values($documents);
    }
}
