<?php

/**
 * DATEV-EXTF-Buchungsstapel: Dateiaufbau und Checkliste ({@see \Platform\AssetManager\Support\DatevExtfWriter})
 * sowie der Buchungstext nach Stil C ({@see \Platform\AssetManager\Support\DatevBookingText}).
 *
 * Referenz ist die Anleitung der Buchhaltung „IT-Kostenumbuchungen als DATEV-EXTF-Buchungsstapel"
 * (Stand 13.10.2026, Discussion #26): Vorlaufzeile wörtlich aus deren Beispiel, Buchungen aus dem
 * Vodafone-Beispiel (H 900 = S 400 + 300 + 200). Jede Zeile der Checkliste ist hier ein Prüffall —
 * ein Verstoß darf keine Datei erzeugen.
 *
 * Aufruf: php tests/local/datev-extf-writer.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Support\DatevBookingText;
use Platform\AssetManager\Support\DatevExtfException;
use Platform\AssetManager\Support\DatevExtfWriter;

function meta(array $over = []): array
{
    return $over + [
        'consultant'        => '189671',
        'client'            => '530',
        'fiscal_year_start' => new DateTimeImmutable('2026-01-01'),
        'account_length'    => 6,
        'period_from'       => new DateTimeImmutable('2026-09-01'),
        'period_to'         => new DateTimeImmutable('2026-09-30'),
        'label'             => 'CTRL Umb Vodafone Sep 2026',
        'initials'          => 'KI',
        'created_at'        => new DateTimeImmutable('2026-10-13 09:30:00'),
    ];
}

function booking(int $cents, string $side, string $cc, string $text, string $account = '649500'): array
{
    return ['amount_cents' => $cents, 'side' => $side, 'account' => $account, 'contra_account' => '159000', 'cost_center' => $cc, 'text' => $text];
}

/** Das Vodafone-Beispiel der Anleitung. */
function example(): array
{
    return [
        booking(90000, 'H', '1520', 'Umb. [1520|diverse] Vodafone SaaS Sep26'),
        booking(40000, 'S', '1510', 'Umb. [1520|1510] Vodafone SaaS Sep26'),
        booking(30000, 'S', '1900', 'Umb. [1520|1900] Vodafone SaaS Sep26'),
        booking(20000, 'S', '8010', 'Umb. [1520|8010] Vodafone SaaS Sep26'),
    ];
}

/** Liefert die Verstöße eines Stapels; leer = Datei würde geschrieben. */
function violations(DatevExtfWriter $w, array $meta, array $bookings): array
{
    try {
        $w->write($meta, $bookings);

        return [];
    } catch (DatevExtfException $e) {
        return $e->violations;
    }
}

function mentions(array $violations, string $needle): bool
{
    foreach ($violations as $v) {
        if (str_contains($v, $needle)) {
            return true;
        }
    }

    return false;
}

$w = DatevExtfWriter::fromTemplate();

// ---- Das Beispiel der Anleitung -----------------------------------------------------------------

$bytes = $w->write(meta(), example());

check('Kein BOM', false, str_starts_with($bytes, "\xEF\xBB\xBF"));
check('Zeilenende CRLF, auch nach der letzten Zeile', true, str_ends_with($bytes, "\r\n"));
check('Kein nacktes LF', 0, preg_match_all('/(?<!\r)\n/', $bytes));
check('CP1252: ü als 0xFC in der Kopfzeile', true, str_contains($bytes, ";BU-Schl\xFCssel;"));

$lines = explode("\r\n", rtrim($bytes, "\r\n"));
check('Vorlauf + Kopf + 4 Buchungen', 6, count($lines));

check(
    'Vorlaufzeile wörtlich wie im Beispiel der Anleitung',
    '"EXTF";510;21;Buchungsstapel;7;20261013093000000;;"BP";"";"";189671;530;20260101;6;20260901;20260930;"CTRL Umb Vodafone Sep 2026";"KI";1;0;0;EUR;;"";;"";;"";"";"";',
    $lines[0],
);
check('Vorlaufzeile: 31 Felder', 31, count(explode(';', $lines[0])));
check('Kopfzeile: 116 Felder, ohne Anführungszeichen', [116, false], [count(explode(';', $lines[1])), str_contains($lines[1], '"')]);

$rows = array_map(fn ($l) => explode(';', $l), array_slice($lines, 2));
check('Jede Buchungszeile hat 116 Felder', [116, 116, 116, 116], array_map('count', $rows));
check('Keine Anführungszeichen in Buchungszeilen', false, str_contains(implode('', array_slice($lines, 2)), '"'));
check('Erste Buchungszeile ohne Belegdatum', '', $rows[0][9]);
check('Weitere Zeilen: Belegdatum TTMM des Monatsletzten', ['3009', '3009', '3009'], array_column(array_slice($rows, 1), 9));
check('Festschreibung (Index 113) überall 0', ['0', '0', '0', '0'], array_column($rows, 113));
check('Quellzeile: 900 H auf 649500 gegen 159000', ['900', 'H', '649500', '159000'], [$rows[0][0], $rows[0][1], $rows[0][6], $rows[0][7]]);
check('KOST1 = KOST2', [['1520', '1520'], ['1510', '1510'], ['1900', '1900'], ['8010', '8010']], array_map(fn ($r) => [$r[36], $r[37]], $rows));
check('Buchungstext steht in Index 13, Postensperre (14) leer', ['Umb. [1520|1510] Vodafone SaaS Sep26', ''], [$rows[1][13], $rows[1][14]]);
check('BU-Schlüssel leer (Netto-Umbuchung)', ['', '', '', ''], array_column($rows, 8));
check(
    'Beispielzeile der Anleitung (Anfang)',
    '400;S;;;;;649500;159000;;3009;;;;Umb. [1520|1510] Vodafone SaaS Sep26;',
    substr($lines[3], 0, strlen('400;S;;;;;649500;159000;;3009;;;;Umb. [1520|1510] Vodafone SaaS Sep26;')),
);

// ---- Beträge: Komma, nur nötige Nachkommastellen -------------------------------------------------

check('Betrag 2200,00 → 2200', '2200', DatevExtfWriter::amount(220000));
check('Betrag 166,29 → 166,29', '166,29', DatevExtfWriter::amount(16629));
check('Betrag 166,20 → 166,2', '166,2', DatevExtfWriter::amount(16620));
check('Betrag 0,05 → 0,05', '0,05', DatevExtfWriter::amount(5));
check('Keine Tausenderpunkte', '4008,52', DatevExtfWriter::amount(400852));

// ---- Dateiname ------------------------------------------------------------------------------------

check('Dateiname mit EXTF_-Präfix', 'EXTF_Umbuchung_IT_Sep2026.csv', DatevExtfWriter::filename('Umbuchung IT Sep2026'));
check('Dateiname ohne Sonderzeichen', 'EXTF_Umb_Vodafone_M_rz_2026.csv', DatevExtfWriter::filename('Umb Vodafone M/rz 2026'));

// ---- Checkliste: jeder Verstoß verhindert die Datei -------------------------------------------------

$b = example();
$b[3]['amount_cents'] = 19999;
$v = violations($w, meta(), $b);
check('Unausgeglichen je Sachkonto → gesperrt', true, mentions($v, '649500'));
check('Unausgeglichen: 159000 saldiert nicht 0', true, mentions($v, '159000'));

$b = example();
$b[1]['text'] = 'Umb. [1520|1510] ' . str_repeat('x', 40) . ' Sep26';
check('Buchungstext > 60 Zeichen → gesperrt', true, mentions(violations($w, meta(), $b), '60 Zeichen'));

$b = example();
$b[1]['text'] = 'Umb. [1520|1510] Vodafone; SaaS Sep26';
check('Semikolon im Buchungstext → gesperrt', true, mentions(violations($w, meta(), $b), 'Semikolon'));

$b = example();
$b[1]['text'] = 'Umb. [1520|1510] Vodafone → SaaS Sep26';
check('Nicht in CP1252 darstellbar → gesperrt', true, mentions(violations($w, meta(), $b), 'CP1252'));

$b = example();
$b[2]['account'] = '64950';
check('Sachkonto nicht 6-stellig → gesperrt', true, mentions(violations($w, meta(), $b), 'Sachkontenlänge'));

$b = example();
$b[2]['amount_cents'] = 0;
check('Betrag 0 → gesperrt', true, mentions(violations($w, meta(), $b), 'Betrag'));

$b = example();
$b[2]['side'] = 'X';
check('S/H ungültig → gesperrt', true, mentions(violations($w, meta(), $b), 'S/H'));

$b = example();
$b[2]['cost_center'] = '';
check('Kostenstelle fehlt → gesperrt', true, mentions(violations($w, meta(), $b), 'Kostenstelle'));

check('Leerer Stapel → gesperrt', true, mentions(violations($w, meta(), []), 'keine Buchungen'));
check('Bezeichnung > 30 Zeichen → gesperrt', true, mentions(violations($w, meta(['label' => str_repeat('B', 31)]), example()), 'Bezeichnung'));
check('Diktatkürzel > 2 Zeichen → gesperrt', true, mentions(violations($w, meta(['initials' => 'ABC']), example()), 'Diktatkürzel'));
check(
    'Zeitraum über zwei Monate → gesperrt',
    true,
    mentions(violations($w, meta(['period_to' => new DateTimeImmutable('2026-10-31')]), example()), 'Buchungsmonat'),
);
check(
    'Zeitraum außerhalb des Wirtschaftsjahres → gesperrt',
    true,
    mentions(violations($w, meta(['fiscal_year_start' => new DateTimeImmutable('2027-01-01')]), example()), 'Wirtschaftsjahr'),
);
check('Mandant nicht numerisch → gesperrt', true, mentions(violations($w, meta(['client' => '53O']), example()), 'Mandant'));
check('Das Beispiel selbst ist sauber', [], violations($w, meta(), example()));

// ---- Kopfzeile: verrutschte Spalten fallen auf ---------------------------------------------------------

$header = explode(';', trim(file_get_contents(dirname(__DIR__, 2) . '/resources/datev/extf-buchungsstapel-kopfzeile.txt')));
[$header[13], $header[14]] = [$header[14], $header[13]];
try {
    new DatevExtfWriter($header);
    check('Buchungstext/Postensperre vertauscht → abgewiesen', 'Exception', 'keine');
} catch (DatevExtfException $e) {
    check('Buchungstext/Postensperre vertauscht → abgewiesen (REW91550)', true, mentions($e->violations, 'Buchungstext'));
}

try {
    new DatevExtfWriter(array_slice($header, 0, 115));
    check('Kopfzeile mit 115 Feldern → abgewiesen', 'Exception', 'keine');
} catch (DatevExtfException $e) {
    check('Kopfzeile mit 115 Feldern → abgewiesen', true, mentions($e->violations, '116'));
}

// Schreibweise der Spaltennamen variiert zwischen den Fassungen: die ältere (März 2026, Formatversion 6)
// schreibt „KOST1 - Kostenstelle", „Umsatz (ohne Soll/Haben-Kz)", „Gegenkonto (ohne BU-Schlüssel)".
$alt = explode(';', trim(file_get_contents(dirname(__DIR__, 2) . '/resources/datev/extf-buchungsstapel-kopfzeile.txt')));
$alt[0]  = 'Umsatz (ohne Soll/Haben-Kz)';
$alt[7]  = 'Gegenkonto (ohne BU-Schlüssel)';
$alt[36] = 'KOST1 - Kostenstelle';
$alt[37] = 'KOST2 - Kostenstelle';
check('Andere Schreibweise der Spaltennamen wird erkannt', 6, count(explode("\r\n", rtrim((new DatevExtfWriter($alt))->write(meta(), example()), "\r\n"))));

// ---- Buchungstext Stil C -------------------------------------------------------------------------------

$sep = new DateTimeImmutable('2026-09-30');
check('Quellzeile', 'Umb. [1520|diverse] Vodafone SaaS Sep26', DatevBookingText::reallocation('1520', null, 'Vodafone SaaS', $sep));
check('Zielzeile', 'Umb. [1520|1900] Vodafone SaaS Sep26', DatevBookingText::reallocation('1520', '1900', 'Vodafone SaaS', $sep));
check('März heißt Mrz', 'Umb. [1520|2599] Vodafone Mobilfunk Mrz26', DatevBookingText::reallocation('1520', '2599', 'Vodafone Mobilfunk', new DateTimeImmutable('2026-03-31')));
check('Oktober heißt Okt', 'Okt26', substr(DatevBookingText::reallocation('1520', '1900', 'X', new DateTimeImmutable('2026-10-31')), -5));

$long = DatevBookingText::reallocation('1520', 'diverse', 'Vodafone Software as a Service Microsoft 365 Business Premium', $sep);
check('Zu lang: auf 60 Zeichen gekürzt', 60, mb_strlen($long));
check('Zu lang: Klammer-Tag bleibt', true, str_starts_with($long, 'Umb. [1520|diverse] '));
check('Zu lang: Stapelmonat bleibt am Ende', true, str_ends_with($long, ' Sep26'));
check('Zu lang: kein Leerzeichen vor dem Monat doppelt', false, str_contains($long, '  '));

check_summary();
