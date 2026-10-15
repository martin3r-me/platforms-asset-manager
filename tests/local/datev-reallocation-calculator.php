<?php

/**
 * DATEV-Umbuchungsrechner (Baustein 2, Discussion #26): aus Rechnungsbetrag und Anteilen der
 * Kostenaufteilung werden die Buchungszeilen eines EXTF-Stapels
 * ({@see \Platform\AssetManager\Support\DatevReallocationCalculator}).
 *
 * Referenz: Controlling-Vault `05_Kontierungsregeln/Umbuchungen.md`, N-Wege-Verteilung —
 * eine Quellzeile (volle Summe H auf der Sammel-KST) + eine Zielzeile je KST (S), inklusive des
 * Eigenanteils der Sammel-KST; 159000 immer im Gegenkonto; bei Erlöskonten dreht S/H.
 * Betrag = Rechnungsbetrag × Anteil, centgenau nach Largest Remainder (Entscheidung Max, #26).
 *
 * Aufruf: php tests/local/datev-reallocation-calculator.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Support\DatevExtfException;
use Platform\AssetManager\Support\DatevExtfWriter;
use Platform\AssetManager\Support\DatevReallocationCalculator;

$sep  = new DateTimeImmutable('2026-09-30');
$calc = new DatevReallocationCalculator(client: '530');

/** Kurzform der Buchungen: „H 90000 1520" je Zeile. */
function rows(array $bookings): array
{
    return array_map(fn ($b) => "{$b['side']} {$b['amount_cents']} {$b['cost_center']}", $bookings);
}

function block(int $cents, array $shares, string $account = '649500', string $service = 'Vodafone SaaS'): array
{
    return ['account' => $account, 'service' => $service, 'amount_cents' => $cents, 'shares' => $shares];
}

// ---- 1: das Vodafone-Beispiel der Anleitung (H 900 = S 400 + 300 + 200) -------------------

$r = $calc->calculate($sep, [block(90000, ['1900' => 300, '1510' => 400, '8010' => 200])]);

check('Beispiel: keine Verstöße', [], $r['violations']);
check('Beispiel: Quellzeile zuerst, Ziele nach KST', ['H 90000 1520', 'S 40000 1510', 'S 30000 1900', 'S 20000 8010'], rows($r['bookings']));
check('Beispiel: Sachkonto im Feld Konto', '649500', $r['bookings'][1]['account']);
check('Beispiel: 159000 immer Gegenkonto', ['159000'], array_values(array_unique(array_column($r['bookings'], 'contra_account'))));
check('Beispiel: Text Quellzeile', 'Umb. [1520|diverse] Vodafone SaaS Sep26', $r['bookings'][0]['text']);
check('Beispiel: Text Zielzeile', 'Umb. [1520|1510] Vodafone SaaS Sep26', $r['bookings'][1]['text']);

$writer = DatevExtfWriter::fromTemplate();
$meta   = [
    'consultant' => '189671', 'client' => '530', 'fiscal_year_start' => new DateTimeImmutable('2026-01-01'),
    'account_length' => 6, 'period_from' => new DateTimeImmutable('2026-09-01'), 'period_to' => $sep,
    'label' => 'CTRL Umb IT Sep 2026', 'initials' => 'KI', 'created_at' => new DateTimeImmutable('2026-10-15 10:00'),
];
check('Beispiel: Writer nimmt den Stapel an', [], $writer->violations($meta, $r['bookings']));

// ---- 2: Largest Remainder — centgenau, Summe = Rechnungsbetrag ----------------------------

$r = $calc->calculate($sep, [block(10000, ['2000' => 1, '2500' => 1, '2400' => 1])]);
check('Drittel: Rest-Cent an die erste KST', ['H 10000 1520', 'S 3334 2000', 'S 3333 2400', 'S 3333 2500'], rows($r['bookings']));

// Rechnung × Anteil: die Kostenaufteilung kennt 4.008,50 €, die Rechnung 4.008,52 €.
$r = $calc->calculate($sep, [block(400852, ['1510' => 200425, '1900' => 120255, '8010' => 80170])]);
$targets = array_sum(array_column(array_slice($r['bookings'], 1), 'amount_cents'));
check('Rechnung × Anteil: Ziele = Rechnungsbetrag', 400852, $targets);
check('Rechnung × Anteil: Verteilung', ['H 400852 1520', 'S 200426 1510', 'S 120256 1900', 'S 80170 8010'], rows($r['bookings']));

// Größter Rest gewinnt vor der KST-Reihenfolge: 1,00 € auf 1:2 → 0,333… / 0,666….
$r = $calc->calculate($sep, [block(100, ['1000' => 1, '2000' => 2])]);
check('Largest Remainder: größerer Rest bekommt den Cent', ['H 100 1520', 'S 33 1000', 'S 67 2000'], rows($r['bookings']));

// ---- 3: Eigenanteil der Sammel-KST, Null-Anteile ------------------------------------------

$r = $calc->calculate($sep, [block(50000, ['1520' => 100, '2000' => 300, '2500' => 0])]);
check('Eigenanteil 1520 als eigene Zielzeile, 0-Anteil ohne Zeile', ['H 50000 1520', 'S 12500 1520', 'S 37500 2000'], rows($r['bookings']));
check('Eigenanteil: Text mit Ziel 1520', 'Umb. [1520|1520] Vodafone SaaS Sep26', $r['bookings'][1]['text']);

// Ein Anteil, der auf 0 Cent fällt, erzeugt keine Zeile.
$r = $calc->calculate($sep, [block(100, ['2000' => 1000, '2500' => 1])]);
check('Anteil unter ½ Cent: keine Zeile', ['H 100 1520', 'S 100 2000'], rows($r['bookings']));

// ---- 4: Vorzeichen — Gutschrift, negativer Anteil, Erlöskonto ------------------------------

$r = $calc->calculate($sep, [block(-30000, ['2000' => 2, '2500' => 1])]);
check('Gutschrift: Quelle S, Ziele H', ['S 30000 1520', 'H 20000 2000', 'H 10000 2500'], rows($r['bookings']));

$r = $calc->calculate($sep, [block(10000, ['2000' => 15000, '1520' => -5000])]);
check('Negativer Anteil (Rabatt auf 1520): Zielzeile H', ['H 10000 1520', 'H 5000 1520', 'S 15000 2000'], rows($r['bookings']));
check('Negativer Anteil: Writer saldiert 0', [], $writer->violations($meta, $r['bookings']));

$r = $calc->calculate($sep, [block(20000, ['2000' => 1, '2500' => 1], '430001', 'Erlöse')]);
check('Erlöskonto: Quelle S, Ziele H', ['S 20000 1520', 'H 10000 2000', 'H 10000 2500'], rows($r['bookings']));

// ---- 5: blockierende Befunde ---------------------------------------------------------------

$r = $calc->calculate($sep, [block(10000, ['2000' => 80, '' => 20])]);
check('Anteil ohne Kostenstelle blockiert', 1, count($r['violations']));
check('… mit Betrag und Leistung', true, str_contains($r['violations'][0] ?? '', '20,00') && str_contains($r['violations'][0] ?? '', 'Vodafone SaaS'));

$known = new DatevReallocationCalculator(client: '530', knownCostCenters: ['1520', '2000', '2500']);
$r = $known->calculate($sep, [block(10000, ['2000' => 1, '9999' => 1])]);
check('Unbekannte Ziel-KST blockiert', true, count($r['violations']) === 1 && str_contains($r['violations'][0], '9999'));

$r = $known->calculate($sep, [block(10000, ['2000' => 1, '2500' => 1])]);
check('Bekannte Ziel-KST: keine Verstöße', [], $r['violations']);

$r = $calc->calculate($sep, [block(10000, ['2000' => 1, '11000' => 1])]);
check('Foodpol-KST 11000 im 530er-Stapel blockiert', true, count($r['violations']) === 1 && str_contains($r['violations'][0], '11000'));

$foodpol = new DatevReallocationCalculator(client: '10002', sourceCostCenter: '11000');
$r = $foodpol->calculate($sep, [block(10000, ['11000' => 1, '2000' => 1])]);
check('Foodpol-Stapel nimmt keine 530er-KST', true, count($r['violations']) === 1 && str_contains($r['violations'][0], '2000'));

$r = $calc->calculate($sep, [block(10000, ['2000' => 1, '2500' => -1])]);
check('Anteile summieren 0 bei Betrag ≠ 0 blockiert', 1, count($r['violations']));

$r = $calc->calculate($sep, [block(0, ['2000' => 1])]);
check('Betrag 0: keine Buchung, kein Verstoß', [[], []], [$r['bookings'], $r['violations']]);

// ---- 6: mehrere Blöcke (je Sachkonto) -------------------------------------------------------

$r = $calc->calculate($sep, [
    block(30000, ['2000' => 1, '2500' => 2], '680510', 'Vodafone Mobilfunk'),
    block(10000, ['2000' => 1], '681000', 'Vodafone Internet'),
]);
check('Mehrere Blöcke: je Block Quelle + Ziele', ['H 30000 1520', 'S 10000 2000', 'S 20000 2500', 'H 10000 1520', 'S 10000 2000'], rows($r['bookings']));
check('Mehrere Blöcke: Konto je Block', ['680510', '680510', '680510', '681000', '681000'], array_column($r['bookings'], 'account'));
check('Mehrere Blöcke: Zusammenfassung je Block', [['account' => '680510', 'service' => 'Vodafone Mobilfunk', 'amount_cents' => 30000, 'lines' => 2], ['account' => '681000', 'service' => 'Vodafone Internet', 'amount_cents' => 10000, 'lines' => 1]], $r['blocks']);
check('Mehrere Blöcke: Writer nimmt den Stapel an', [], $writer->violations($meta, $r['bookings']));

check_summary();
