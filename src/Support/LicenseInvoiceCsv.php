<?php

namespace Platform\AssetManager\Support;

use Illuminate\Support\Carbon;

/**
 * Liest den CSV-Export „Abrechnungsdetails" des Lizenz-Wiederverkäufers und verdichtet ihn zu
 * **einem effektiven Stückpreis je Rechnungsposition**. Reines Parsen und Rechnen, kein DB-Zugriff —
 * die Zuordnung zu Lizenzen macht {@see \Platform\AssetManager\Services\LicenseInvoiceImportService}.
 *
 * ## Dateiformat (verifiziert an drei echten Rechnungen, 05–07/2026)
 *
 * Semikolon-getrennt, deutsches Zahlenformat, UTF-8. Kopfzeile, dann Datenzeilen, dann ein
 * Summenblock (`Gesamtnetto`, `MwSt. 19%`, `Gesamtbetrag`) ohne Produktangabe.
 *
 *   Produkt | Typ | Abrechnungszeitraum | Edition | Anzahl | Betrag | Listenpreis/Rabatt | Einheit
 *
 * Die Spalte **Einheit** trennt zwei Zeilenarten, und das ist der Dreh- und Angelpunkt:
 * `EUR` = Positionszeile, `%` = **Rabattzeile** mit negativem Betrag und `Anzahl` 1.
 *
 * ## Warum der Listenpreis nicht der Preis ist
 *
 * Spalte „Listenpreis/Rabatt" trägt den **Listenpreis vor Rabatt**. Business Premium steht dort mit
 * 21,63 € — bezahlt werden 15,14 €, weil eine eigene Zeile 30 % gegenrechnet. Wer den Listenpreis
 * übernimmt, überschätzt die Lizenzkosten um fünfstellige Beträge im Jahr.
 *
 * ## Warum nicht Betrag ÷ Anzahl
 *
 * Ändert sich die Menge mitten im Monat, splittet der Anbieter die Position in Teilperioden mit
 * **zeitanteiligen** Beträgen — und rechnet Korrekturen als Zeilen mit negativer Anzahl gegen. Im
 * Juli 2026 besteht „Exchange Online (Plan 2)" aus sechs Zeilen, darunter −1, −1, +1, +2 Stück für
 * die letzten 15 Tage. Betrag ÷ Anzahl liefert für jede dieser Zeilen etwas anderes, und keines
 * davon ist der Monatspreis.
 *
 * ## Der Preis
 *
 *     Monatspreis = Listenpreis × (1 − Rabattsatz)
 *
 * Beides steht unmittelbar in der Rechnung, und das Ergebnis gilt unabhängig von der Zerlegung in
 * Teilperioden — ein Seat kostet den Monatspreis, nicht seine Tage. Über alle drei geprüften
 * Rechnungen trifft diese Formel jede Position exakt: 21,63 × 0,70 = 15,141 für Business Premium,
 * 21,84 × 0,92 = 20,0928 für das Copilot-Add-on, 3,12 × 0,82 = 2,5584 für Exchange Online Archiving.
 *
 * ## Die Gegenprobe
 *
 * Der zeitanteilige Weg bleibt als Kontrolle: jede Zeile mit ihrem Anteil am Monat gewichtet,
 *
 *     Menge(gewichtet) = Σ  Anzahl × (Tage der Zeile ÷ Tage des Monats)
 *
 * muss `Monatspreis × Menge(gewichtet)` den Rechnungsbetrag der Position ergeben — und die Summe
 * über alle Positionen den Wert `Gesamtnetto`. Beides stimmt in allen drei Monaten auf den Cent.
 * Schlägt es an, passen Listenpreis und Rabattsatz nicht zum abgerechneten Betrag; dann ist eine der
 * Angaben unbrauchbar, und das muss auffallen, statt einen falschen Preis über alle Seats zu
 * multiplizieren.
 *
 * ## Die Menge
 *
 * `quantity` ist der Bestand zum **Ende** des Abrechnungszeitraums, also zum Monatsersten des
 * Folgemonats: die Summe der Mengen aller Zeilen, deren Zeitraum dort endet. Bewusst dieses Ende und
 * nicht der Beginn — Graph liefert immer den *aktuellen* Bestand, und nur dagegen ist ein harter
 * Mengenabgleich erfüllbar. `quantity_weighted` ist dagegen keine Seat-Zahl (bei Teams Premium im
 * Juni steht dort 1,533, weil der Tarif am 08.06. begann) und dient ausschließlich der Gegenprobe.
 */
final class LicenseInvoiceCsv
{
    /** Erwartete Spaltenüberschriften — Erkennungsmerkmal der richtigen Datei. */
    private const REQUIRED_HEADERS = ['Produkt', 'Abrechnungszeitraum', 'Edition', 'Anzahl', 'Betrag'];

    /**
     * Datei einlesen und verdichten.
     *
     * @return array{
     *     period: array{from: Carbon, to: Carbon, days: int, label: string},
     *     positions: array<string, array<string, mixed>>,
     *     currency: string,
     *     sum_net: float,
     *     total_net: ?float,
     *     reconciles: bool,
     *     difference: float,
     *     unassigned_discounts: list<array<string, mixed>>,
     *     row_count: int
     * }
     */
    public function parse(string $path): array
    {
        [$rows, $totalNet, $currency] = $this->readRows($path);

        if ($rows === []) {
            throw new \RuntimeException(
                'Keine Abrechnungszeilen gefunden — ist das der CSV-Export „Abrechnungsdetails"? '
                . 'Erwartet werden die Spalten Produkt, Abrechnungszeitraum, Edition, Anzahl, Betrag.'
            );
        }

        $period = $this->resolvePeriod($rows);

        $items     = array_values(array_filter($rows, fn ($r) => ! $r['is_discount']));
        $discounts = array_values(array_filter($rows, fn ($r) => $r['is_discount']));

        if ($items === []) {
            throw new \RuntimeException('Die Datei enthält nur Rabattzeilen — es fehlen die Positionen, auf die sie sich beziehen.');
        }

        $positions = [];

        foreach ($items as $row) {
            $this->addItem($positions, $row, $period);
        }

        $unassigned = [];

        foreach ($discounts as $row) {
            $key = $this->matchDiscount($row, array_keys($positions));

            if ($key === null) {
                // Ein Rabatt ohne Position ist kein Rundungsfehler, sondern ein Loch in der Rechnung:
                // der Betrag fehlt dann in jedem Stückpreis. Deshalb wird er gemeldet, nicht verteilt
                // — und die Prüfsumme unten schlägt zusätzlich an.
                $unassigned[] = ['edition' => $row['edition'], 'amount' => $row['amount']];
                continue;
            }

            $this->addDiscount($positions, $key, $row);
        }

        foreach ($positions as $key => $position) {
            $positions[$key] = $this->finalize($position);
        }

        $sumNet = round(array_sum(array_map(fn ($p) => $p['amount_net'], $positions))
            + array_sum(array_map(fn ($d) => $d['amount'], $unassigned)), 2);

        return [
            'period'               => $period,
            'positions'            => $positions,
            'currency'             => $currency,
            'sum_net'              => $sumNet,
            'total_net'            => $totalNet,
            // Der Export rechnet auf den Cent auf. Weicht das ab, wurde beim Export gefiltert oder
            // eine Zeilenart übersehen — dann sind die Preise unvollständig, und das muss sichtbar
            // sein statt im Import unterzugehen (gleiche Haltung wie beim Vodafone-Import, ADR 0018).
            'reconciles'           => $totalNet === null || abs($sumNet - $totalNet) < 0.01,
            'difference'           => $totalNet === null ? 0.0 : round($sumNet - $totalNet, 2),
            'unassigned_discounts' => $unassigned,
            'row_count'            => count($rows),
        ];
    }

    // ---- Lesen ---------------------------------------------------------------------------------

    /**
     * @return array{0: list<array<string, mixed>>, 1: ?float, 2: string} Datenzeilen, Gesamtnetto, Währung
     */
    private function readRows(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Die Datei konnte nicht gelesen werden.');
        }

        $rows     = [];
        $totalNet = null;
        $currency = 'EUR';
        $headerSeen = false;

        try {
            while (($fields = fgetcsv($handle, 0, ';')) !== false) {
                if ($fields === [null] || $fields === []) {
                    continue;
                }

                $fields = array_map(fn ($f) => $this->toUtf8((string) ($f ?? '')), $fields);

                if (! $headerSeen) {
                    $headerSeen = true;
                    $this->assertHeader($fields);
                    continue;
                }

                // Summenblock: keine Produktangabe, die Bezeichnung steht in der Anzahl-Spalte.
                if (trim($fields[0] ?? '') === '') {
                    if (str_contains($fields[4] ?? '', 'Gesamtnetto')) {
                        $totalNet = $this->number($fields[5] ?? '');
                        $currency = $this->currency($fields[7] ?? '') ?? $currency;
                    }
                    continue;
                }

                $period = $this->period($fields[2] ?? '');

                if ($period === null) {
                    continue; // Zeile ohne verwertbaren Abrechnungszeitraum
                }

                $unit = trim($fields[7] ?? '');

                $rows[] = [
                    'product'     => trim($fields[0] ?? ''),
                    'edition'     => trim($fields[3] ?? ''),
                    'quantity'    => $this->number($fields[4] ?? ''),
                    'amount'      => round($this->number($fields[5] ?? ''), 2),
                    'list_price'  => $this->number($fields[6] ?? ''),
                    'unit'        => $unit,
                    'period'      => $period,
                    'is_discount' => LicenseEdition::isDiscountUnit($unit),
                ];

                if (! LicenseEdition::isDiscountUnit($unit)) {
                    $currency = $this->currency($unit) ?? $currency;
                }
            }
        } finally {
            fclose($handle);
        }

        return [$rows, $totalNet, $currency];
    }

    /** @param list<string> $fields */
    private function assertHeader(array $fields): void
    {
        $joined = mb_strtolower(implode(';', $fields), 'UTF-8');

        foreach (self::REQUIRED_HEADERS as $required) {
            if (! str_contains($joined, mb_strtolower($required, 'UTF-8'))) {
                throw new \RuntimeException(
                    'Die Kopfzeile passt nicht zum Export „Abrechnungsdetails" — Spalte „' . $required . '" fehlt.'
                );
            }
        }
    }

    // ---- Verdichten ----------------------------------------------------------------------------

    /**
     * Abrechnungsmonat: früheste Anfangs- bis späteste Enddatum aller Zeilen. Bewusst nicht aus dem
     * Dateinamen — der ist umbenennbar, die Zeiträume sind die Abrechnung selbst.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{from: Carbon, to: Carbon, days: int, label: string}
     */
    private function resolvePeriod(array $rows): array
    {
        // Bewusst über die Carbon-Instanzen selbst, nicht über Unix-Timestamps: `createFromFormat`
        // legt die Daten in der App-Zeitzone an, `createFromTimestamp` liest sie in UTC zurück. Bei
        // Europe/Berlin verschiebt das den Monatsanfang um zwei Stunden auf den Vortag — aus
        // 01.05.–01.06. wird 30.04.–31.05., und der Vergleich „endet am Monatsende" trifft nie mehr.
        $fromDate = clone $rows[0]['period']['from'];
        $toDate   = clone $rows[0]['period']['to'];

        foreach ($rows as $row) {
            if ($row['period']['from']->lt($fromDate)) {
                $fromDate = clone $row['period']['from'];
            }
            if ($row['period']['to']->gt($toDate)) {
                $toDate = clone $row['period']['to'];
            }
        }

        $days = (int) $fromDate->diffInDays($toDate);

        if ($days < 1) {
            throw new \RuntimeException('Der Abrechnungszeitraum der Datei ist kürzer als ein Tag — die Zeiträume sind unbrauchbar.');
        }

        return [
            'from'  => $fromDate,
            'to'    => $toDate,
            'days'  => $days,
            'label' => $fromDate->format('Y-m'),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $positions
     * @param array<string, mixed>                $row
     * @param array{from: Carbon, to: Carbon, days: int, label: string} $period
     */
    private function addItem(array &$positions, array $row, array $period): void
    {
        $key = LicenseEdition::key($row['edition']);

        $positions[$key] ??= $this->emptyPosition($key, $row);

        $weight = $row['period']['days'] / $period['days'];

        $positions[$key]['quantity_weighted']      += $row['quantity'] * $weight;
        $positions[$key]['amount_before_discount'] += $row['amount'];
        $positions[$key]['item_rows']++;

        // Bestand am Monatsende — nur Zeilen, deren Zeitraum mit dem Monat endet, beschreiben ihn.
        if ($row['period']['to']->isSameDay($period['to'])) {
            $positions[$key]['quantity_nominal'] += $row['quantity'];
        }

        // Der Listenpreis ist über alle Teilperioden derselbe; die Vollmonatszeile ist die
        // verlässlichste Quelle, deshalb gewinnt die längste Periode.
        if ($row['list_price'] > 0 && $row['period']['days'] >= ($positions[$key]['list_price_days'] ?? 0)) {
            $positions[$key]['list_price']      = $row['list_price'];
            $positions[$key]['list_price_days'] = $row['period']['days'];
        }

        $positions[$key]['periods'][] = $this->periodLabel($row['period']);
    }

    /**
     * @param array<string, array<string, mixed>> $positions
     * @param array<string, mixed>                $row
     */
    private function addDiscount(array &$positions, string $key, array $row): void
    {
        $positions[$key]['discount_amount'] += $row['amount'];
        $positions[$key]['discount_rows']++;

        $rate = round(abs($row['list_price']), 2);

        if ($rate > 0 && ! in_array($rate, $positions[$key]['discount_rates'], true)) {
            $positions[$key]['discount_rates'][] = $rate;
        }

        // Die Bezeichnung der Rabattzeile trägt die Vertragsbindung: „12 M 30%" steht für eine
        // 12-Monats-Bindung, „Prozentualer Rabatt 8%" für einen Konditionsrabatt ohne Bindung.
        if (! in_array($row['edition'], $positions[$key]['discount_labels'], true)) {
            $positions[$key]['discount_labels'][] = $row['edition'];
        }
    }

    /**
     * Rabattzeile ihrer Position zuordnen. Der Basisname der Rabattzeile ist der Positionsname; ein
     * **exakter** Treffer gewinnt vor einem Präfix-Treffer, weil beides gleichzeitig vorkommt:
     * „Microsoft 365 Copilot - 12 M 12%" ist Präfix von „Microsoft 365 Copilot Business …", gehört
     * aber zu „Microsoft 365 Copilot". Ohne diese Reihenfolge landet der Rabatt am falschen Produkt.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    private function matchDiscount(array $row, array $keys): ?string
    {
        $base = LicenseEdition::discountBase($row['edition']);

        if ($base === null) {
            return null;
        }

        $baseKey = LicenseEdition::key($base);

        if (in_array($baseKey, $keys, true)) {
            return $baseKey;
        }

        $candidates = array_values(array_filter($keys, fn ($k) => str_starts_with($k, $baseKey)));

        if ($candidates === []) {
            return null;
        }

        // Längster Präfix-Treffer = spezifischste Position. Bei Gleichstand entscheidet die
        // Sortierung deterministisch, damit derselbe Import immer dasselbe Ergebnis liefert.
        usort($candidates, fn ($a, $b) => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);

        return $candidates[0];
    }

    /** @param array<string, mixed> $row */
    private function emptyPosition(string $key, array $row): array
    {
        return [
            'edition_key'            => $key,
            'edition'                => $row['edition'],
            'product'                => $row['product'],
            'quantity_weighted'      => 0.0,
            'quantity_nominal'       => 0.0,
            'amount_before_discount' => 0.0,
            'discount_amount'        => 0.0,
            'list_price'             => 0.0,
            'list_price_days'        => 0,
            'discount_rates'         => [],
            'discount_labels'        => [],
            'periods'                => [],
            'item_rows'              => 0,
            'discount_rows'          => 0,
        ];
    }

    /**
     * Monatspreis, Menge und Bindung einer Position festlegen.
     *
     * Der Preis kommt aus **Listenpreis × (1 − Rabattsatz)**. Beide Werte stehen unmittelbar in der
     * Rechnung, und das Ergebnis ist der Monatspreis eines Seats — unabhängig davon, wie viele
     * Teilperioden die Rechnung daraus gemacht hat. Genau das verlangt der Grundsatz „Snapshot zum
     * Monatsersten, keine Seat-Tage-Berechnung": ein Seat kostet den Monatspreis, nicht seine Tage.
     *
     * Die zeitanteilige Rechnung bleibt als **Gegenprobe** erhalten: Monatspreis × gewichtete Menge
     * muss den Rechnungsbetrag der Position ergeben. Weicht das ab, passen Listenpreis und Rabattsatz
     * nicht zum abgerechneten Betrag — dann ist eine der beiden Angaben unbrauchbar, und das muss
     * auffallen, statt einen falschen Preis über alle Seats zu multiplizieren.
     *
     * @param array<string, mixed> $position
     */
    private function finalize(array $position): array
    {
        $net       = round($position['amount_before_discount'] + $position['discount_amount'], 2);
        $weighted  = round($position['quantity_weighted'], 4);
        $list      = $position['list_price'] > 0 ? round($position['list_price'], 4) : null;
        $rates     = $position['discount_rates'];

        // Mehrere verschiedene Sätze auf einer Position kommen in den geprüften Rechnungen nicht vor.
        // Träte es auf, wäre jede Wahl eine Annahme — deshalb wird der höchste genommen *und* die
        // Mehrdeutigkeit gemeldet, statt sie in einem Mittelwert zu verstecken.
        $discountPercent = $rates === [] ? 0.0 : (float) max($rates);

        $unitPrice = $list !== null
            ? round($list * (1 - $discountPercent / 100), 4)
            : (abs($weighted) > 0.0001 ? round($net / $weighted, 4) : null);

        // Ohne Listenpreis bleibt nur der Rückschluss aus dem Betrag — brauchbar, aber schwächer,
        // weil er die Teilperioden-Gewichtung voraussetzt. Die Herkunft wird mitgeführt.
        $priceBasis = $list !== null ? 'list_minus_discount' : 'derived_from_amount';

        $expectedNet = $unitPrice !== null ? round($unitPrice * $weighted, 2) : null;

        return [
            'edition_key'            => $position['edition_key'],
            'edition'                => $position['edition'],
            'product'                => $position['product'],
            // Verbindliche Menge: Bestand zum Ende des Abrechnungszeitraums (Monatserster des
            // Folgemonats) — der Stand, den Graph zeigt.
            'quantity'               => round($position['quantity_nominal'], 2),
            'quantity_weighted'      => $weighted,
            'amount_net'             => $net,
            'amount_before_discount' => round($position['amount_before_discount'], 2),
            'discount_amount'        => round($position['discount_amount'], 2),
            'discount_percent'       => round($discountPercent, 2),
            'discount_ambiguous'     => count($rates) > 1,
            'list_price'             => $list,
            'unit_price'             => $unitPrice,
            'price_basis'            => $priceBasis,
            // Gegenprobe: Differenz zwischen Rechnungsbetrag und Monatspreis × gewichteter Menge.
            'price_check_difference' => $expectedNet === null ? null : round($net - $expectedNet, 2),
            'binding'                => $this->resolveBinding($position),
            'usable'                 => $unitPrice !== null && $unitPrice > 0,
            'discount_rates'         => $rates,
            'discount_labels'        => $position['discount_labels'],
            'periods'                => array_values(array_unique($position['periods'])),
            'item_rows'              => $position['item_rows'],
            'discount_rows'          => $position['discount_rows'],
        ];
    }

    /**
     * Vertragsbindung aus den beiden Signalen der Rechnung ableiten — sie nennt sie nicht als Feld.
     *
     * 1. Laufzeit-Code im Positionsnamen: „… (SMB300 Standalone) - P1Y".
     * 2. Bezeichnung der Rabattzeile: „12 M 30%" ist ein Bindungsrabatt, „Prozentualer Rabatt 8%"
     *    ein Konditionsrabatt ohne Bindung.
     *
     * Das zweite Signal ist das wichtigere: die Mai-Rechnung trägt den Laufzeit-Code noch nicht, den
     * Bindungsrabatt aber schon. Ohne beides gilt Monatsbindung — die teurere, flexiblere Annahme,
     * die niemanden zu einer Verlängerung verleitet, die es nicht gibt. Der Wert ist eine Vorbelegung
     * und wird bis zur Bestätigung als unbestätigt geführt.
     *
     * @param array<string, mixed> $position
     */
    private function resolveBinding(array $position): string
    {
        if (preg_match('/\s-\sP\d+Y\b/i', $position['edition']) === 1) {
            return 'P1Y';
        }

        foreach ($position['discount_labels'] as $label) {
            // „12 M 30%" / „12 m 18%" — Zahl plus alleinstehendes M vor dem Prozentsatz.
            if (preg_match('/(?<![\p{L}\d])\d{1,2}\s*M(?![\p{L}])/iu', (string) $label) === 1) {
                return 'P1Y';
            }
        }

        return 'P1M';
    }

    // ---- Parsen einzelner Werte ----------------------------------------------------------------

    /**
     * „01.07.2026-01.08.2026" zerlegen.
     *
     * @return array{from: Carbon, to: Carbon, days: int}|null
     */
    private function period(string $value): ?array
    {
        if (! preg_match('/(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/', trim($value), $match)) {
            return null;
        }

        $from = Carbon::createFromFormat('d.m.Y', $match[1]);
        $to   = Carbon::createFromFormat('d.m.Y', $match[2]);

        if ($from === false || $to === false) {
            return null;
        }

        $from = $from->startOfDay();
        $to   = $to->startOfDay();

        $days = (int) $from->diffInDays($to);

        // Ein Zeitraum ohne Länge liefert Gewicht 0 und damit keinen Beitrag zum Preis. Der Betrag
        // gehört aber zur Rechnung, deshalb zählt die Zeile mit mindestens einem Tag.
        return ['from' => $from, 'to' => $to, 'days' => max($days, 1)];
    }

    /** Deutsches Zahlenformat: „2.552,3400000000" und „-765,7000000000". */
    private function number(string $value): float
    {
        $value = trim($value);

        if ($value === '') {
            return 0.0;
        }

        return (float) str_replace([' ', '.', ','], ['', '', '.'], $value);
    }

    /** Währungscode aus der Einheit-Spalte („EUR"); „%" und Leeres liefern null. */
    private function currency(string $unit): ?string
    {
        $unit = strtoupper(trim($unit));

        return preg_match('/^[A-Z]{3}$/', $unit) === 1 ? $unit : null;
    }

    /** @param array{from: Carbon, to: Carbon, days: int} $period */
    private function periodLabel(array $period): string
    {
        return $period['from']->format('d.m.Y') . '–' . $period['to']->format('d.m.Y');
    }

    /**
     * Der Export ist UTF-8, aber ein durch Excel gespeicherter Export ist es nicht mehr. Ohne diese
     * Umschaltung zerfallen Umlaute und der Zeitraum-Anhang „Gebühr pro Benutzer" wird nicht mehr
     * erkannt — die Copilot-Positionen zerfielen dann in eine Zeile je Teilperiode.
     */
    private function toUtf8(string $value): string
    {
        $value = ltrim($value, "\xEF\xBB\xBF");

        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
