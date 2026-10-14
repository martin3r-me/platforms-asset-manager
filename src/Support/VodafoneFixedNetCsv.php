<?php

namespace Platform\AssetManager\Support;

use Illuminate\Support\Carbon;

/**
 * Liest die Vodafone-„Rechnung CSV" eines **Festnetzkontos** (Rechnungs-Analyse-Portal → Detailseite
 * einer Rechnung) und verdichtet sie zu Blöcken, die der Import als Kostenpositionen bucht. Reines
 * Parsen und Rechnen, kein DB-Zugriff — Muster {@see LicenseInvoiceCsv}.
 *
 * ## Dateiformat (verifiziert an 22 Rechnungen zweier Konten, 07/2025–09/2026)
 *
 * 61 Spalten, Semikolon, **Windows-1252**, Kopfzeile, eine Zeile je Rechnungsposition. Kopfwerte
 * (Konto, Rechnung, Zeitraum, Rechnungsnetto) stehen in jeder Zeile. Spalten werden über den
 * **Kopfnamen** gelesen, nicht über die Position — Vodafone ergänzt Spalten gern am Ende.
 *
 * Die Spalte `Kostenart` trennt drei Zeilenarten: `MONATLICHE LEISTUNGEN`, `RABATT` (eigene Zeilen
 * mit negativem Betrag, ohne Bezug zur rabattierten Position) und `VOLUMENBEZOGENE LEISTUNGEN`
 * (Verbindungen). Positionen eines Produkts tragen in `Anschlusskennung` dessen Kennung (Leitung
 * `dusbroich2`, Anlagenanschluss `4922128208`); **kontoweite** Posten tragen stattdessen
 * `<Kundennummer><Buchstabe>-<Produkt>` (z. B. „Umlage Einrichtungspreis", alle Rabatte).
 *
 * ## Blöcke
 *
 *   internet[kennung]      Internetleitung je Anschluss (Produkt „… Internet …")
 *   telephony.licenses     RingCentral-Lizenzen: Menge aus `Dauer` bei Einheit `LIZ`
 *   telephony.fixed        Anlagenanschluss: Kanäle aus `Dauer` bei Einheit `KNL`
 *   telephony.usage        Verbindungen (volumenbezogen)
 *   unassigned             alles, was keiner Regel folgt — wird gemeldet, nie still verteilt
 *
 * Rabatte werden über ihren **Tarifnamen** zugeordnet (der Betrag sagt nicht, worauf er sich
 * bezieht): „… Lizenzen" → Lizenzen, „… Anschluss" / „Kombi" → Anlagenanschluss, „Red Business" bzw.
 * „Mobilfunk" (Rabatt auf nationale Verbindungen) → Verbindungen, Produkt „Internet" → Leitung.
 *
 * Eine Rechnung des Lizenzkontos („Software as a Service", Microsoft 365) hat dieselbe Form, gehört
 * aber in den Lizenzimport (ADR 0019) — sie wird als `is_saas` erkannt, damit der Import sie
 * abweisen kann, statt die Lizenzkosten ein zweites Mal zu buchen.
 */
final class VodafoneFixedNetCsv
{
    /** Spalten, ohne die die Datei nicht auswertbar ist — zugleich Erkennungsmerkmal. */
    private const REQUIRED = [
        'Rechnungskonto', 'Rechnungs-Nr.', 'Kostenart', 'Produktname',
        'Dienstenutzung Beschreibung', 'Anschlusskennung', 'Position Netto', 'Gesamt Rechnungsbetrag Netto',
    ];

    /**
     * @return array{
     *     account: string, invoice: string, invoice_date: ?Carbon,
     *     period: array{from: ?Carbon, to: ?Carbon, label: ?string},
     *     is_saas: bool,
     *     internet: array<string, array{product: string, amount: float, items: list<array{label: string, amount: float}>}>,
     *     telephony: ?array{
     *         licenses: array{seats: int, gross: float, discount: float, net: float},
     *         fixed: array{channels: int, gross: float, discount: float, net: float},
     *         usage: array{gross: float, discount: float, net: float},
     *     },
     *     unassigned: list<array{kostenart: string, product: string, label: string, amount: float}>,
     *     total_net: float, sum_net: float, reconciles: bool, difference: float, row_count: int
     * }
     */
    public function parse(string $path): array
    {
        $rows = $this->readRows($path);

        if ($rows === []) {
            throw new \RuntimeException('Keine Rechnungspositionen gefunden — ist das die „Rechnung CSV" eines Vodafone-Festnetzkontos?');
        }

        $first = $rows[0];

        $result = [
            'account'      => $first['Rechnungskonto'],
            'invoice'      => $first['Rechnungs-Nr.'],
            'invoice_date' => $this->date($first['Rechnungsdatum'] ?? ''),
            'period'       => [
                'from'  => $this->date($first['Abrechnungszeitraum von'] ?? ''),
                'to'    => $this->date($first['Abrechnungszeitraum bis'] ?? ''),
                'label' => $this->monthLabel($first['Abrechnungsmonat'] ?? ''),
            ],
            'is_saas'      => false,
            'internet'     => [],
            'telephony'    => null,
            'unassigned'   => [],
            'total_net'    => $this->number($first['Gesamt Rechnungsbetrag Netto']),
            'row_count'    => count($rows),
        ];

        // Kontoweite Internet-Posten (Umlage, Internet-Rabatt) kennen ihre Leitung nicht — sie werden
        // erst nach dem Durchlauf zugeordnet, wenn feststeht, wie viele Leitungen das Konto hat.
        $accountWideInternet = [];

        foreach ($rows as $row) {
            $kostenart = mb_strtoupper(trim($row['Kostenart']));
            $product   = trim($row['Produktname']);
            $tarif     = trim($row['Tarifname'] ?? '');
            $label     = trim($row['Dienstenutzung Beschreibung']);
            $kennung   = trim($row['Anschlusskennung']);
            $amount    = $this->number($row['Position Netto']);

            if (stripos($product, 'Software as a Service') !== false) {
                $result['is_saas'] = true;
                continue;
            }

            $isTelephony = stripos($product, 'RingCentral') !== false || stripos($product, 'Business UC') !== false;
            $isInternet  = stripos($product, 'Internet') !== false;

            if (str_starts_with($kostenart, 'RABATT')) {
                $target = $this->discountTarget($product, $tarif);

                if ($target === 'internet') {
                    $accountWideInternet[] = ['label' => $tarif !== '' ? $tarif : $label, 'amount' => $amount];
                } elseif ($target !== null) {
                    $this->addTelephony($result, $target, 'discount', $amount);
                } else {
                    $result['unassigned'][] = ['kostenart' => $kostenart, 'product' => $product, 'label' => $tarif ?: $label, 'amount' => $amount];
                }
                continue;
            }

            if ($isTelephony) {
                $unit = mb_strtoupper(trim($row['Einheit'] ?? ''));

                if (str_starts_with($kostenart, 'VOLUMEN')) {
                    $this->addTelephony($result, 'usage', 'gross', $amount);
                } elseif ($unit === 'LIZ') {
                    $this->addTelephony($result, 'licenses', 'seats', (int) round($this->number($row['Dauer'] ?? '')));
                    $this->addTelephony($result, 'licenses', 'gross', $amount);
                } elseif ($unit === 'KNL') {
                    $this->addTelephony($result, 'fixed', 'channels', (int) round($this->number($row['Dauer'] ?? '')));
                    $this->addTelephony($result, 'fixed', 'gross', $amount);
                } else {
                    // Weitere monatliche Anlagen-Posten (z. B. Zusatzoption) sind Fixkosten der Anlage —
                    // sie gehören auf die Sammelstelle, nicht auf die Köpfe.
                    $this->addTelephony($result, 'fixed', 'gross', $amount);
                }
                continue;
            }

            if ($isInternet) {
                if ($this->isAccountWide($kennung)) {
                    $accountWideInternet[] = ['label' => $label, 'amount' => $amount];
                } else {
                    $result['internet'][$kennung] ??= ['product' => $product, 'amount' => 0.0, 'items' => []];
                    $result['internet'][$kennung]['items'][] = ['label' => $label, 'amount' => $amount];
                    $result['internet'][$kennung]['amount'] += $amount;
                }
                continue;
            }

            $result['unassigned'][] = ['kostenart' => $kostenart, 'product' => $product, 'label' => $label, 'amount' => $amount];
        }

        if ($accountWideInternet !== []) {
            if (count($result['internet']) === 1) {
                $key = array_key_first($result['internet']);
                foreach ($accountWideInternet as $item) {
                    $result['internet'][$key]['items'][]  = $item;
                    $result['internet'][$key]['amount']  += $item['amount'];
                }
            } else {
                // Null oder mehrere Leitungen: welcher gehört die Umlage? Das entscheidet kein Code.
                foreach ($accountWideInternet as $item) {
                    $result['unassigned'][] = ['kostenart' => 'kontoweit', 'product' => 'Internet'] + $item;
                }
            }
        }

        foreach ($result['internet'] as $key => $line) {
            $result['internet'][$key]['amount'] = round($line['amount'], 4);
        }

        if ($result['telephony'] !== null) {
            foreach (['licenses', 'fixed', 'usage'] as $block) {
                $b = &$result['telephony'][$block];
                $b['net'] = round($b['gross'] + $b['discount'], 4);
                unset($b);
            }
        }

        $sum = array_sum(array_column($result['internet'], 'amount'))
            + array_sum(array_column($result['unassigned'], 'amount'))
            + ($result['telephony'] === null ? 0.0 : array_sum(array_column($result['telephony'], 'net')));

        $result['sum_net']    = round($sum, 2);
        // Die Positionen ergeben in jeder geprüften Rechnung exakt das Rechnungsnetto. Weicht das ab,
        // fehlt eine Zeilenart — dann darf kein Betrag gebucht werden, ohne dass es auffällt.
        $result['difference'] = round($result['sum_net'] - $result['total_net'], 2);
        $result['reconciles'] = $result['is_saas'] || abs($result['difference']) < 0.01;

        return $result;
    }

    // ---- Zuordnung --------------------------------------------------------------------------

    /** Ziel eines Rabatts: licenses | fixed | usage | internet | null (unbekannt). */
    private function discountTarget(string $product, string $tarif): ?string
    {
        $t = mb_strtolower($tarif);
        $p = mb_strtolower($product);

        return match (true) {
            str_contains($t, 'lizenz')                                   => 'licenses',
            str_contains($t, 'anschluss'), str_contains($t, 'kombi')     => 'fixed',
            str_contains($p, 'red business'), str_contains($t, 'mobilfunk') => 'usage',
            str_contains($p, 'internet')                                 => 'internet',
            default                                                      => null,
        };
    }

    /** `000091000823M-Vodafone Business Internet Connect` — Kundennummer + Buchstabe + Bindestrich. */
    private function isAccountWide(string $kennung): bool
    {
        return $kennung === '' || preg_match('/^\d{6,}[A-Z]-/', $kennung) === 1;
    }

    /** Einen Wert auf einen Telefonie-Block buchen; legt die Blöcke beim ersten Treffer an. */
    private function addTelephony(array &$result, string $block, string $field, float|int $value): void
    {
        $result['telephony'] ??= [
            'licenses' => ['seats' => 0, 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0],
            'fixed'    => ['channels' => 0, 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0],
            'usage'    => ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0],
        ];

        $result['telephony'][$block][$field] += $value;
    }

    // ---- Lesen ------------------------------------------------------------------------------

    /** @return list<array<string, string>> Datenzeilen, Schlüssel = Spaltenname */
    private function readRows(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Die Datei konnte nicht gelesen werden.');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $header = null;
        $rows   = [];

        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, ';', '"', '');

            if ($header === null) {
                $header = array_map('trim', $fields);
                $missing = array_values(array_diff(self::REQUIRED, $header));
                if ($missing !== []) {
                    throw new \RuntimeException('Das ist nicht die „Rechnung CSV" eines Vodafone-Festnetzkontos — es fehlen die Spalten: ' . implode(', ', $missing) . '.');
                }
                continue;
            }

            $row = [];
            foreach ($header as $i => $name) {
                // Doppelte Spaltennamen (z. B. zweimal „Zahlungsziel"): der erste gewinnt.
                $row[$name] ??= (string) ($fields[$i] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function number(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        return (float) str_replace([' ', '.', ','], ['', '', '.'], $value);
    }

    /** `26-08-2026` (Festnetz) oder `26.08.2026`. */
    private function date(string $value): ?Carbon
    {
        $value = trim($value);
        foreach (['d-m-Y', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                // nächstes Format
            }
        }

        return null;
    }

    /** `202609` → `2026-09`. */
    private function monthLabel(string $value): ?string
    {
        return preg_match('/^(\d{4})(\d{2})$/', trim($value), $m) ? $m[1] . '-' . $m[2] : null;
    }
}
