<?php

namespace Platform\AssetManager\Support;

use Platform\AssetManager\Models\AssetImportRun;

/**
 * Schreibt einen {@see AssetImportRun} — die dauerhafte Spur eines Import-Laufs.
 *
 * Die drei Importer liefern **verschieden geformte** Ergebnisse: der Excel-Import ein flaches
 * `[Blattname => Anzahl]`, der Vodafone-Import verschachtelte Statistiken je Rufnummer, der
 * Lizenz-Import Vertragszeilen samt Verteilung. Diese Klasse übersetzt jede Form in dieselbe
 * Darstellung — **Kennzahlen** (Label => Wert) und **Befunde** (kurze Sätze) — damit die Anzeige nicht
 * für jede Quelle eine eigene Sonderbehandlung braucht und eine vierte Quelle nur hier andockt.
 *
 * Der Status entsteht dabei aus dem Ergebnis, nicht aus dem Erfolg des Aufrufs: ein Lauf, dessen
 * Rechnungssumme nicht aufgeht oder dessen Positionen keiner Lizenz zugeordnet wurden, ist
 * durchgelaufen und trotzdem nicht in Ordnung — das ist der Zustand `warning`.
 *
 * **Wichtig für den Aufrufer:** immer **nach** dem Import aufrufen, nie aus dem Importer heraus. Die
 * Importer arbeiten in einer Transaktion, die im Probelauf zurückgerollt wird — ein von dort
 * geschriebener Eintrag verschwände mit dem Rollback, und gerade Probeläufe sollen protokolliert sein.
 */
final class ImportRunRecorder
{
    /**
     * Erfolgreichen (oder mit Befund beendeten) Lauf festhalten.
     *
     * @param array<string, mixed> $result Rückgabe des jeweiligen Import-Services
     */
    public function record(
        string $source,
        int $teamId,
        int $tenantId,
        array $result,
        ?string $fileName,
        bool $dryRun,
        ?int $userId,
        ?float $startedAt = null,
    ): AssetImportRun {
        [$metrics, $findings, $headline] = match ($source) {
            AssetImportRun::SOURCE_LICENSE_INVOICE => $this->fromLicenseInvoice($result),
            AssetImportRun::SOURCE_VODAFONE        => $this->fromVodafone($result),
            default                                => $this->fromExcel($result),
        };

        return $this->write(
            source: $source,
            teamId: $teamId,
            tenantId: $tenantId,
            fileName: $fileName,
            dryRun: $dryRun,
            userId: $userId,
            status: $findings === [] ? AssetImportRun::STATUS_OK : AssetImportRun::STATUS_WARNING,
            headline: $headline,
            summary: ['metrics' => $metrics, 'findings' => $findings, 'raw' => $this->trim($result)],
            error: null,
            startedAt: $startedAt,
        );
    }

    /** Abgebrochenen Lauf festhalten — ein Fehlschlag ist die wichtigste Zeile im Protokoll. */
    public function recordFailure(
        string $source,
        int $teamId,
        int $tenantId,
        string $message,
        ?string $fileName,
        bool $dryRun,
        ?int $userId,
        ?float $startedAt = null,
    ): AssetImportRun {
        return $this->write(
            source: $source,
            teamId: $teamId,
            tenantId: $tenantId,
            fileName: $fileName,
            dryRun: $dryRun,
            userId: $userId,
            status: AssetImportRun::STATUS_ERROR,
            headline: $message,
            summary: ['metrics' => [], 'findings' => [], 'raw' => null],
            error: $message,
            startedAt: $startedAt,
        );
    }

    /** @param array<string, mixed> $summary */
    private function write(
        string $source,
        int $teamId,
        int $tenantId,
        ?string $fileName,
        bool $dryRun,
        ?int $userId,
        string $status,
        ?string $headline,
        array $summary,
        ?string $error,
        ?float $startedAt,
    ): AssetImportRun {
        return AssetImportRun::withoutTenantScope()->create([
            'team_id'     => $teamId,
            'tenant_id'   => $tenantId,
            'source'      => $source,
            'file_name'   => $fileName,
            'dry_run'     => $dryRun,
            'status'      => $status,
            'headline'    => $headline !== null ? mb_substr($headline, 0, 250) : null,
            'error'       => $error,
            'summary'     => $summary,
            'user_id'     => $userId,
            'started_at'  => now(),
            'duration_ms' => $startedAt !== null ? (int) round((microtime(true) - $startedAt) * 1000) : null,
        ]);
    }

    // ---- Übersetzung je Quelle -----------------------------------------------------------------

    /**
     * Lizenz-Abrechnung (ADR 0019).
     *
     * @param array<string, mixed> $result
     * @return array{0: array<string, string>, 1: list<string>, 2: string}
     */
    private function fromLicenseInvoice(array $result): array
    {
        $checks     = (array) ($result['checks'] ?? []);
        $totals     = (array) ($result['allocation']['totals'] ?? []);
        $unmatched  = (array) ($result['unmatched'] ?? []);
        $priceMoves = (array) ($result['price_moves'] ?? []);

        $metrics = [
            'Abrechnungsmonat'  => (string) ($result['period']['label'] ?? '—'),
            'Positionen'        => $this->number($result['positions'] ?? 0, 0),
            'Zugeordnet'        => $this->number($result['matched'] ?? 0, 0) . ' von ' . $this->number($result['positions'] ?? 0, 0),
            'Rechnungssumme'    => $this->money($checks['positions_sum_net'] ?? 0),
            'Monatswert'        => $this->money($totals['invoice_amount'] ?? 0),
            'Auf Trägern'       => $this->money($totals['seat_amount'] ?? 0) . ' (' . $this->number($totals['seats'] ?? 0, 0) . ' Seats)',
            'IT-Gemeinkosten'   => $this->money($totals['overflow_amount'] ?? 0) . ' (' . $this->number($totals['overflow_seats'] ?? 0, 0) . ' Seats)',
            'Lizenzpool'        => $this->money($totals['pool_amount'] ?? 0) . ' (' . $this->number($totals['pool_seats'] ?? 0, 0) . ' Seats)',
        ];

        if ((int) ($result['pruned'] ?? 0) > 0) {
            $metrics['Entfernte Zeilen'] = $this->number($result['pruned'], 0);
        }

        $findings = [];

        if (($checks['reconciles'] ?? true) === false) {
            $findings[] = sprintf(
                'Die Summe der Positionen weicht um %s von der Rechnungssumme ab — die Datei ist unvollständig oder wurde beim Export gefiltert.',
                $this->money($checks['difference'] ?? 0),
            );
        }

        if (($checks['prices_match'] ?? true) === false) {
            $findings[] = sprintf(
                '%d Position(en): Listenpreis und Rabatt passen nicht zum abgerechneten Betrag.',
                count((array) ($checks['price_mismatches'] ?? [])),
            );
        }

        if (($checks['quantities_balanced'] ?? true) === false) {
            $findings[] = 'Die Mengenbilanz geht nicht auf — es liegen bezahlte Seats ohne Ziel vor. Das ist ein Fehler, keine Toleranz.';
        }

        if ($unmatched !== []) {
            $findings[] = sprintf(
                '%d Position(en) ohne Lizenz (%s/Monat) — diese Beträge fließen in keine Kostenstelle: %s',
                count($unmatched),
                $this->money(array_sum(array_column($unmatched, 'amount'))),
                implode(', ', array_slice(array_column($unmatched, 'edition'), 0, 3)),
            );
        }

        if ((int) ($totals['unpaid_seats'] ?? 0) > 0) {
            $findings[] = sprintf(
                '%d zugewiesene Seat(s) werden von keiner Rechnungsposition bezahlt — entweder gratis oder eine Lücke im Einkauf.',
                (int) $totals['unpaid_seats'],
            );
        }

        if ((int) ($totals['pool_seats'] ?? 0) > 0) {
            $findings[] = sprintf(
                '%d bezahlte Seat(s) sind unbesetzt (%s/Monat) — Kündigungspotenzial.',
                (int) $totals['pool_seats'],
                $this->money($totals['pool_amount'] ?? 0),
            );
        }

        if ((int) ($totals['overflow_seats'] ?? 0) > 0) {
            $findings[] = sprintf(
                '%d Monats-Seat(s) über dem Bedarf an Funktionskonten (%s/Monat) — auf Jahresbindung umstellbar und damit günstiger.',
                (int) $totals['overflow_seats'],
                $this->money($totals['overflow_amount'] ?? 0),
            );
        }

        if ($priceMoves !== []) {
            $findings[] = sprintf(
                '%d handgepflegte(r) Stückpreis(e) abgelöst: %s',
                count($priceMoves),
                implode(', ', array_map(
                    fn ($move) => ($move['display_name'] ?? $move['sku']) . ' (war ' . $this->money($move['previous_price']) . ')',
                    array_slice($priceMoves, 0, 3),
                )),
            );
        }

        $headline = sprintf(
            '%s · %s Positionen · %s/Monat',
            $result['period']['label'] ?? '—',
            $this->number($result['positions'] ?? 0, 0),
            $this->money($totals['invoice_amount'] ?? 0),
        );

        return [$metrics, $findings, $headline];
    }

    /**
     * Vodafone-Rechnungsanalyse (ADR 0018).
     *
     * @param array<string, mixed> $result
     * @return array{0: array<string, string>, 1: list<string>, 2: string}
     */
    private function fromVodafone(array $result): array
    {
        $stats = (array) ($result['stats'] ?? []);

        $metrics = [
            'Zeitraum'       => trim(($result['period']['from'] ?? '') . '–' . ($result['period']['to'] ?? ''), '–') ?: '—',
            'Rechnung'       => (string) ($result['period']['invoice'] ?? '—'),
            'Rufnummern'     => $this->number($stats['numbers'] ?? 0, 0),
            'Zugeordnet'     => $this->number($stats['matched'] ?? 0, 0),
            'Kontoposten'    => $this->number($stats['account_items'] ?? 0, 0),
            'Summe netto'    => $this->money($result['sum_net'] ?? 0),
            'Rechnungskopf'  => $this->money($result['invoice_total_net'] ?? 0),
        ];

        $findings = [];

        if (($result['reconciles'] ?? true) === false) {
            $findings[] = 'Die Summe der Zeilen rechnet nicht gegen den Rechnungskopf auf — die Zahlen sind unvollständig.';
        }

        if ((int) ($stats['unmatched'] ?? 0) > 0) {
            $findings[] = sprintf(
                '%d Rufnummer(n) ohne Asset-Träger — die Kostenposition entsteht trotzdem, aber ohne Person.',
                (int) $stats['unmatched'],
            );
        }

        if (($stats['unknown_centers'] ?? []) !== []) {
            $findings[] = 'Kostenstellen aus dem Export, die es hier nicht gibt: ' . implode(', ', (array) $stats['unknown_centers']);
        }

        if (($stats['cost_center_diff'] ?? []) !== []) {
            $findings[] = sprintf(
                '%d Abweichung(en) zwischen der Kostenstelle im Export und unserer Zuordnung — unsere gilt.',
                count((array) $stats['cost_center_diff']),
            );
        }

        if ((int) ($result['removed_excel_rows'] ?? 0) > 0) {
            $findings[] = sprintf(
                '%d abgelöste Mobilfunk-Zeile(n) aus dem Excel-Import entfernt.',
                (int) $result['removed_excel_rows'],
            );
        }

        $headline = sprintf(
            '%s Rufnummern · %s netto',
            $this->number($stats['numbers'] ?? 0, 0),
            $this->money($result['sum_net'] ?? 0),
        );

        return [$metrics, $findings, $headline];
    }

    /**
     * Kostenaufteilungs-Excel. Liefert ein flaches `[Blattname => Anzahl]` plus `_mode`.
     *
     * @param array<string, mixed> $result
     * @return array{0: array<string, string>, 1: list<string>, 2: string}
     */
    private function fromExcel(array $result): array
    {
        $metrics = [];
        $total   = 0;

        foreach ($result as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            if (is_numeric($value)) {
                $metrics[ucfirst((string) $key)] = $this->number($value, 0) . ' Zeilen';
                $total += (int) $value;
            }
        }

        $metrics['Gesamt'] = $this->number($total, 0) . ' Zeilen';

        return [
            $metrics,
            [], // Der Excel-Import meldet selbst keine Befunde; seine Zahlen sind reine Zeilenzähler.
            sprintf('%s Zeilen über %d Blatt/Blätter', $this->number($total, 0), max(count($metrics) - 1, 0)),
        ];
    }

    // ---- Formatierung -------------------------------------------------------------------------

    /**
     * Rohergebnis für die Detailansicht mitschreiben, aber ohne die großen Listen: `lines` einer
     * Lizenz-Abrechnung kann hundert Einträge haben, und die stehen ohnehin als Vertragszeilen in der
     * Datenbank. Ein Protokoll, das die Nutzdaten dupliziert, wird groß und veraltet sofort.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function trim(array $result): array
    {
        unset($result['lines'], $result['allocation']['skus']);

        return $result;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.') . ' €';
    }

    private function number(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }
}
