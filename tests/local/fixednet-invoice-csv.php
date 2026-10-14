<?php

/**
 * Vodafone-Festnetz „Rechnung CSV": Parser und Blockbildung ({@see \Platform\AssetManager\Support\VodafoneFixedNetCsv}).
 *
 * Die Fixture wird hier gebaut: Kopfzeile wörtlich aus dem Portal-Export (61 Spalten, doppeltes
 * „Zahlungsziel"), Werte synthetisch, aber mit jedem Muster der echten Rechnungen — Internetleitung mit
 * kontoweiter Umlage, RingCentral-Lizenzen (Einheit LIZ) und Anlagenanschluss (KNL), vier Rabattarten
 * mit Zuordnung über den Tarifnamen, Verbindungszeilen, Windows-1252-Umlaute. Gegen die 22 echten
 * Rechnungen (alle auf den Cent) wurde der Parser beim Bau ad hoc geprüft; die liegen nicht im Repo.
 *
 * Aufruf: php tests/local/fixednet-invoice-csv.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Support\VodafoneFixedNetCsv;

const HEADER = 'Dokument Nummer;Rechnungskonto;Kundennummer zur Rechnung;Ihre Referenz;EBOS ID;Rechnungs-Nr.;Rechnungsdatum;Währung;Abrechnungszeitraum von;Abrechnungszeitraum bis;Abrechnungsmonat;Zahlungsziel;Zahlungsziel;Name Absender;Titel Absender;Vorname Absender;Vorname 2 Absender;Nachname Absender;Strasse Absender;Strasse 2 Absender;Postfach Absender;Ort Absender;PLZ Absender;Land Absender;Kundenebene;Lokations-Kundennummer;Referenz zur Lokations-Kundennummer;Positions-Nr Kostentyp;Kostenart ID;Kostenart;Produkt ID;Produktname;Tarif-ID;Tarifname;Produktnutzung ID;Produktnutzung Beschreibung;Rechnungsposition-Nr;Dienstenutzung ID;Dienstenutzung Beschreibung;Anzahl;Anschlusskennung;Position Netto;Position Umsatzsteuersatz;Position Umsatzsteuerpflichtig Netto;Zusatzinformation;Berechnung ab;Berechnung bis;Rabattierfähiger Betrag;Tarifzone ID;Tarifzone Beschreibung;Dauer;Einheit;Tarifzeit ID;Tarifzeit Beschreibung;Gesamtbetrag Netto;Gesamtbetrag Brutto;Gesamt Umsatzsteuerbetrag;Rechnungsbetrag Netto;Rechnung Umsatzsteuersatz;Gesamt MwSt. Betrag;Gesamt Rechnungsbetrag Netto';

/** Eine Zeile im Spaltenschema; nur die gesetzten Felder sind belegt. */
function row(array $values, string $total): string
{
    $cols = explode(';', HEADER);
    $base = [
        'Dokument Nummer' => '9000000001', 'Rechnungskonto' => '0100000001', 'Rechnungs-Nr.' => '9000000001',
        'Rechnungsdatum' => '02-10-2026', 'Abrechnungszeitraum von' => '26-08-2026', 'Abrechnungszeitraum bis' => '25-09-2026',
        'Abrechnungsmonat' => '202609', 'Gesamt Rechnungsbetrag Netto' => $total,
    ];
    $v    = $values + $base;
    $line = [];
    $seen = [];
    foreach ($cols as $c) {
        $line[] = isset($seen[$c]) ? '' : ($v[$c] ?? '');
        $seen[$c] = true;
    }

    return implode(';', $line);
}

function fixture(array $rows, string $total): string
{
    $csv  = implode("\r\n", array_merge([HEADER], array_map(fn ($r) => row($r, $total), $rows)));
    $path = tempnam(sys_get_temp_dir(), 'fn');
    file_put_contents($path, mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

    return $path;
}

$m = 'MONATLICHE LEISTUNGEN';
$uc = 'Vodafone Business UC mit RingCentral';
$inet = 'Vodafone Business Internet Connect';

// Summe: Internet 100+50+2 = 152 · Lizenzen 10×20 = 200 − 136 = 64 · Anlage 120 − 24 − 12 = 84 · Verbindungen 10 − 2 = 8 → 308
$path = fixture([
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'leitung1', 'Position Netto' => '100,000000'],
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => '1000 Mbit/s', 'Anschlusskennung' => 'leitung1', 'Position Netto' => '50,000000'],
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => 'Umlage Einrichtungspreis', 'Anschlusskennung' => '000091000823M-Vodafone Business Internet Connect', 'Position Netto' => '2,000000'],
    ['Kostenart' => $m, 'Produktname' => $uc, 'Dienstenutzung Beschreibung' => 'Premium', 'Anschlusskennung' => '4920000000', 'Position Netto' => '200,000000', 'Dauer' => '10,000000', 'Einheit' => 'LIZ'],
    ['Kostenart' => $m, 'Produktname' => $uc, 'Dienstenutzung Beschreibung' => 'IP Anlagenanschluss RingCentral', 'Anschlusskennung' => '4920000000', 'Position Netto' => '120,000000', 'Dauer' => '10,000000', 'Einheit' => 'KNL'],
    ['Kostenart' => 'RABATT', 'Produktname' => 'Vodafone Business UC Rabatt', 'Tarifname' => '68% Lizenzen', 'Dienstenutzung Beschreibung' => 'Rabatt Service', 'Anschlusskennung' => '000091000823R-Vodafone Business UC Rabatt', 'Position Netto' => '-136,000000'],
    ['Kostenart' => 'RABATT', 'Produktname' => 'Vodafone Business UC Rabatt', 'Tarifname' => '20% Anschluss', 'Dienstenutzung Beschreibung' => 'Rabatt Service', 'Anschlusskennung' => '000091000823R-Vodafone Business UC Rabatt', 'Position Netto' => '-24,000000'],
    ['Kostenart' => 'RABATT', 'Produktname' => 'Vodafone Business UC Rabatt', 'Tarifname' => '10% Rabatt Kombi-Vorteil ', 'Dienstenutzung Beschreibung' => 'Rabatt Service', 'Anschlusskennung' => '000091000823R-Vodafone Business UC Rabatt', 'Position Netto' => '-12,000000'],
    ['Kostenart' => 'RABATT', 'Produktname' => 'Red Business', 'Tarifname' => '20% Rabatt National Mobilfunk', 'Dienstenutzung Beschreibung' => 'Rabatt Service', 'Anschlusskennung' => '000091000823R-Red Business', 'Position Netto' => '-2,000000'],
    ['Kostenart' => 'VOLUMENBEZOGENE LEISTUNGEN', 'Produktname' => $uc, 'Dienstenutzung Beschreibung' => 'IP Anlagenanschluss RingCentral', 'Anschlusskennung' => '4921000000', 'Position Netto' => '6,500000'],
    ['Kostenart' => 'VOLUMENBEZOGENE LEISTUNGEN', 'Produktname' => $uc, 'Dienstenutzung Beschreibung' => 'Gespräche ins Ausland', 'Anschlusskennung' => '4921000000', 'Position Netto' => '3,500000'],
], '308,000000');

$r = (new VodafoneFixedNetCsv())->parse($path);
@unlink($path);

check('Kopf: Konto', '0100000001', $r['account']);
check('Kopf: Rechnung', '9000000001', $r['invoice']);
check('Kopf: Zeitraum von (dd-mm-yyyy)', '2026-08-26', $r['period']['from']?->format('Y-m-d'));
check('Kopf: Abrechnungsmonat', '2026-09', $r['period']['label']);
check('Kein SaaS', false, $r['is_saas']);
check('Internet: eine Leitung', ['leitung1'], array_keys($r['internet']));
check('Internet: kontoweite Umlage hängt an der einzigen Leitung', 152.0, $r['internet']['leitung1']['amount']);
check('Lizenzen: Menge aus Dauer/LIZ', 10, $r['telephony']['licenses']['seats']);
check('Lizenzen: nur der Lizenz-Rabatt (68 %)', 64.0, $r['telephony']['licenses']['net']);
check('Anlage: Kanäle aus Dauer/KNL', 10, $r['telephony']['fixed']['channels']);
check('Anlage: Anschluss- + Kombi-Rabatt', 84.0, $r['telephony']['fixed']['net']);
check('Verbindungen: abzgl. Red-Business-Rabatt', 8.0, $r['telephony']['usage']['net']);
check('Nichts unzugeordnet', 0, count($r['unassigned']));
check('Summe = Rechnungsnetto', 308.0, $r['sum_net']);
check('reconciles', true, $r['reconciles']);

// ---- Unbekannte Zeilen werden gemeldet, nicht verteilt — und die Summe bleibt vollständig ----

$path = fixture([
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'leitung1', 'Position Netto' => '100,000000'],
    ['Kostenart' => 'RABATT', 'Produktname' => 'Sonder', 'Tarifname' => 'Treuebonus', 'Dienstenutzung Beschreibung' => 'Rabatt Service', 'Anschlusskennung' => '000091000823R-Sonder', 'Position Netto' => '-5,000000'],
    ['Kostenart' => $m, 'Produktname' => 'Vodafone Störungsservice', 'Dienstenutzung Beschreibung' => 'Service', 'Anschlusskennung' => 'x', 'Position Netto' => '7,000000'],
], '102,000000');
$r = (new VodafoneFixedNetCsv())->parse($path);
@unlink($path);

check('Unbekannter Rabatt + fremdes Produkt gemeldet', 2, count($r['unassigned']));
check('Unbekannter Rabatt trägt den Tarifnamen', 'Treuebonus', $r['unassigned'][0]['label']);
check('Summe zählt Unzugeordnetes mit', true, $r['reconciles']);
check('Ohne RingCentral-Zeilen kein Telefonie-Block', null, $r['telephony']);

// ---- Zwei Leitungen: kontoweite Umlage ist nicht zuordenbar ---------------------------------

$path = fixture([
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'a', 'Position Netto' => '10,000000'],
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => 'Anschlussleitung', 'Anschlusskennung' => 'b', 'Position Netto' => '20,000000'],
    ['Kostenart' => $m, 'Produktname' => $inet, 'Dienstenutzung Beschreibung' => 'Umlage Einrichtungspreis', 'Anschlusskennung' => '000091000823M-Vodafone Business Internet Connect', 'Position Netto' => '3,000000'],
], '33,000000');
$r = (new VodafoneFixedNetCsv())->parse($path);
@unlink($path);

check('Zwei Leitungen getrennt', 2, count($r['internet']));
check('Umlage bei zwei Leitungen: gemeldet statt geraten', 1, count($r['unassigned']));
check('Summe bleibt vollständig', true, $r['reconciles']);

// ---- Lizenzkonto (Software as a Service) wird erkannt ---------------------------------------

$path = fixture([
    ['Kostenart' => $m, 'Produktname' => 'Vodafone - Software as a Service', 'Dienstenutzung Beschreibung' => 'Microsoft 365 Business Premium mtl. Zahlung', 'Anschlusskennung' => 'SaaS1', 'Position Netto' => '2704,230000'],
], '2704,230000');
$r = (new VodafoneFixedNetCsv())->parse($path);
@unlink($path);

check('SaaS erkannt', true, $r['is_saas']);
check('SaaS: nichts gebucht', 0.0, $r['sum_net']);

// ---- Falsche Datei ----------------------------------------------------------------------------

$path = tempnam(sys_get_temp_dir(), 'fn');
file_put_contents($path, "Typ;Rechnungskonto;Rechnungs-Nummer\r\nRechnung;1;2\r\n");
try {
    (new VodafoneFixedNetCsv())->parse($path);
    check('Falsche Datei wird abgewiesen', 'Exception', 'keine');
} catch (\RuntimeException $e) {
    check('Falsche Datei nennt die fehlenden Spalten', true, str_contains($e->getMessage(), 'Kostenart'));
}
@unlink($path);

check_summary();
