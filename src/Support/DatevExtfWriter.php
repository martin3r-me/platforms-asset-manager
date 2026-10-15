<?php

namespace Platform\AssetManager\Support;

/**
 * Schreibt einen DATEV-EXTF-Buchungsstapel (Datenkategorie 21, Formatversion 7) für den Import in
 * DATEV Kanzlei-Rechnungswesen. Reines Formatieren und Prüfen, kein DB-Zugriff: Welche Buchungen
 * entstehen (Quell-/Zielzeilen, Verteilung), entscheidet der Aufrufer — Discussion #26.
 *
 * ## Dateiaufbau (Anleitung der Buchhaltung, Stand 13.10.2026)
 *
 *   Zeile 1   Vorlaufzeile, 31 Felder, ausgewählte Felder in Anführungszeichen
 *   Zeile 2   Kopfzeile, 116 Spaltennamen, ohne Anführungszeichen
 *   ab 3      je Buchung 116 Felder, ohne Anführungszeichen
 *
 * Windows-1252, CRLF, kein BOM, Semikolon, Dezimalkomma, Beträge immer positiv.
 *
 * ## Kopfzeile kommt aus einer Datei
 *
 * Die verbindliche Fassung pflegt die Buchhaltung (`02_EXTF/EXTF_Schema.md` in ihrem Vault). Sie liegt
 * hier als `resources/datev/extf-buchungsstapel-kopfzeile.txt` und wird beim Austausch nur dort ersetzt.
 * Stand: zeichengleich mit `02_EXTF/EXTF_Schema.md` (Controlling-Vault, korrigiert 02.09.2026) und der
 * importierten Datei `EXTF_RS_IT_Sep2026.csv`. Die ältere März-Fassung schrieb die Namen anders
 * („KOST1 - Kostenstelle"), die Positionen sind identisch; der Writer erkennt beide.
 *
 * Die Vorlaufzeile entspricht zeichengleich der des Controlling-Generators (`02_EXTF/extf_generator.py`):
 * 31 Felder, das letzte leer nach dem abschließenden Semikolon.
 * Die genutzten Spalten werden über den **Namen** gesucht und gegen die dokumentierte Position geprüft:
 * eine verrutschte Kopfzeile (bekannt: Buchungstext und Postensperre vertauscht → REW91550) fällt so
 * beim Bau des Writers auf, nicht erst beim Import.
 *
 * ## Checkliste als harte Prüfung
 *
 * Jeder Punkt der Checkliste, der sich aus der Datei selbst prüfen lässt, ist hier eine Regel. Ein
 * einziger Verstoß verhindert die Datei ({@see DatevExtfException}) — ein falscher Stapel kann beim
 * Import festgeschrieben werden und ist dann nicht mehr zurückzunehmen. Zwei Festschreib-Sicherungen
 * setzt der Writer selbst: Festschreibung 0 (Vorlauf Pos. 20 und Index 113 jeder Buchung) und kein
 * Belegdatum in der ersten Buchungszeile.
 */
final class DatevExtfWriter
{
    public const FIELD_COUNT = 116;

    /** Genutzte Spalten: dokumentierte Position (0-basiert) und Namensanfang (normalisiert). */
    private const FIELDS = [
        'amount'      => [0, 'umsatz', 'Umsatz'],
        'side'        => [1, 'sollhabenkennzeichen', 'Soll/Haben-Kennzeichen'],
        'account'     => [6, 'konto', 'Konto'],
        'contra'      => [7, 'gegenkonto', 'Gegenkonto'],
        'date'        => [9, 'belegdatum', 'Belegdatum'],
        'text'        => [13, 'buchungstext', 'Buchungstext'],
        'cost_center' => [36, 'kost1', 'KOST1'],
        'cost_unit'   => [37, 'kost2', 'KOST2'],
        'fixed'       => [113, 'festschreibung', 'Festschreibung'],
    ];

    private const TEXT_MAX         = 60;
    private const LABEL_MAX        = 30;
    private const INITIALS_MAX     = 2;
    private const COST_CENTER_MAX  = 36;

    public static function templatePath(): string
    {
        return dirname(__DIR__, 2) . '/resources/datev/extf-buchungsstapel-kopfzeile.txt';
    }

    public static function fromTemplate(?string $path = null): self
    {
        $raw = (string) file_get_contents($path ?? self::templatePath());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        return new self(explode(';', trim($raw, "\r\n")));
    }

    /** @var array<string, int> */
    private array $index = [];

    /** @param list<string> $header Spaltennamen der Kopfzeile (UTF-8) */
    public function __construct(private readonly array $header)
    {
        $violations = [];

        if (count($header) !== self::FIELD_COUNT) {
            $violations[] = sprintf('Kopfzeile hat %d statt %d Feldern.', count($header), self::FIELD_COUNT);
        }

        $normalized = array_map(fn ($n) => self::normalize((string) $n), $header);
        foreach (self::FIELDS as $field => [$expected, $prefix, $label]) {
            $found = null;
            foreach ($normalized as $i => $name) {
                if (str_starts_with($name, $prefix)) {
                    $found = $i;
                    break;
                }
            }

            if ($found === null) {
                $violations[] = "Spalte {$label} fehlt in der Kopfzeile.";
            } elseif ($found !== $expected) {
                $violations[] = sprintf('Spalte %s steht an Position %d statt %d — Kopfzeile verrutscht.', $label, $found, $expected);
            }
            $this->index[$field] = $expected;
        }

        if ($violations !== []) {
            throw new DatevExtfException($violations);
        }
    }

    /**
     * Erzeugt die Datei als Windows-1252-Bytes.
     *
     * @param array{
     *     consultant: string|int, client: string|int, fiscal_year_start: \DateTimeInterface,
     *     account_length: int, period_from: \DateTimeInterface, period_to: \DateTimeInterface,
     *     label: string, initials: string, created_at: \DateTimeInterface
     * } $meta
     * @param list<array{
     *     amount_cents: int, side: string, account: string, contra_account: string,
     *     cost_center: string, text: string
     * }> $bookings  KOST1 = KOST2 = cost_center; das Belegdatum setzt der Writer (Monatsletzter).
     *
     * @throws DatevExtfException bei jedem Verstoß gegen die Checkliste
     */
    public function write(array $meta, array $bookings): string
    {
        $violations = $this->violations($meta, $bookings);
        if ($violations !== []) {
            throw new DatevExtfException($violations);
        }

        $lines = [
            $this->preamble($meta),
            implode(';', $this->header),
        ];

        $date = $meta['period_to']->format('dm');
        foreach (array_values($bookings) as $i => $b) {
            $row = array_fill(0, self::FIELD_COUNT, '');
            $row[$this->index['amount']]      = self::amount((int) $b['amount_cents']);
            $row[$this->index['side']]        = $b['side'];
            $row[$this->index['account']]     = $b['account'];
            $row[$this->index['contra']]      = $b['contra_account'];
            // Erste Zeile ohne Belegdatum: mit Datum schreibt DATEV den Stapel beim Import sofort fest.
            $row[$this->index['date']]        = $i === 0 ? '' : $date;
            $row[$this->index['text']]        = $b['text'];
            $row[$this->index['cost_center']] = $b['cost_center'];
            $row[$this->index['cost_unit']]   = $b['cost_center'];
            $row[$this->index['fixed']]       = '0';
            $lines[] = implode(';', $row);
        }

        return mb_convert_encoding(implode("\r\n", $lines) . "\r\n", 'Windows-1252', 'UTF-8');
    }

    /**
     * Alle Verstöße gegen die Checkliste; leer = die Datei darf geschrieben werden.
     *
     * @return list<string>
     */
    public function violations(array $meta, array $bookings): array
    {
        $v = [];

        if (! preg_match('/^\d{1,7}$/', (string) ($meta['consultant'] ?? ''))) {
            $v[] = 'Berater muss numerisch sein (max. 7 Stellen).';
        }
        if (! preg_match('/^\d{1,5}$/', (string) ($meta['client'] ?? ''))) {
            $v[] = 'Mandant muss numerisch sein (max. 5 Stellen).';
        }

        $length = (int) ($meta['account_length'] ?? 0);
        if ($length < 4 || $length > 8) {
            $v[] = 'Sachkontenlänge muss zwischen 4 und 8 liegen.';
        }

        $from = $meta['period_from'];
        $to   = $meta['period_to'];
        $fy   = \DateTimeImmutable::createFromInterface($meta['fiscal_year_start'])->setTime(0, 0);
        if ($from > $to || $from->format('Y-m') !== $to->format('Y-m')) {
            $v[] = 'Datum von/bis müssen im selben Buchungsmonat liegen (ein Stapel je Monat).';
        }
        if ($from < $fy || $to >= $fy->modify('+1 year')) {
            $v[] = 'Zeitraum liegt außerhalb des Wirtschaftsjahres ab ' . $fy->format('d.m.Y') . '.';
        }

        $label = (string) ($meta['label'] ?? '');
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX) {
            $v[] = 'Bezeichnung muss 1–' . self::LABEL_MAX . ' Zeichen haben.';
        }
        $initials = (string) ($meta['initials'] ?? '');
        if ($initials === '' || mb_strlen($initials) > self::INITIALS_MAX) {
            $v[] = 'Diktatkürzel muss 1–' . self::INITIALS_MAX . ' Zeichen haben.';
        }
        foreach (['Bezeichnung' => $label, 'Diktatkürzel' => $initials] as $what => $value) {
            if (str_contains($value, '"') || str_contains($value, ';') || ! self::encodable($value)) {
                $v[] = "{$what} enthält Anführungszeichen, Semikolon oder Zeichen, die nicht in CP1252 darstellbar sind.";
            }
        }

        if ($bookings === []) {
            $v[] = 'Der Stapel enthält keine Buchungen.';

            return $v;
        }

        $byAccount = [];
        $byContra  = [];
        foreach (array_values($bookings) as $i => $b) {
            $at   = sprintf('Zeile %d (KST %s)', $i + 1, $b['cost_center'] ?? '?');
            $side = $b['side'] ?? '';

            if (! is_int($b['amount_cents'] ?? null) || $b['amount_cents'] <= 0) {
                $v[] = "{$at}: Betrag muss größer 0 sein (Beträge sind immer positiv, die Richtung steht in S/H).";
            }
            if (! in_array($side, ['S', 'H'], true)) {
                $v[] = "{$at}: S/H muss S oder H sein.";
            }
            foreach (['Konto' => $b['account'] ?? '', 'Gegenkonto' => $b['contra_account'] ?? ''] as $what => $acct) {
                if (! preg_match('/^\d{' . $length . '}$/', (string) $acct)) {
                    $v[] = "{$at}: {$what} „{$acct}“ entspricht nicht der Sachkontenlänge {$length}.";
                }
            }
            if (($b['account'] ?? null) === ($b['contra_account'] ?? null)) {
                $v[] = "{$at}: Konto und Gegenkonto sind gleich.";
            }

            $cc = (string) ($b['cost_center'] ?? '');
            if ($cc === '') {
                $v[] = "{$at}: Kostenstelle fehlt.";
            } elseif (mb_strlen($cc) > self::COST_CENTER_MAX || ! self::plain($cc)) {
                $v[] = "{$at}: Kostenstelle „{$cc}“ ist ungültig.";
            }

            $text = (string) ($b['text'] ?? '');
            if ($text === '') {
                $v[] = "{$at}: Buchungstext fehlt.";
            } elseif (mb_strlen($text) > self::TEXT_MAX) {
                $v[] = "{$at}: Buchungstext ist länger als " . self::TEXT_MAX . ' Zeichen.';
            }
            if (preg_match('/[;"\r\n]/', $text)) {
                $v[] = "{$at}: Buchungstext enthält Semikolon, Anführungszeichen oder Zeilenumbruch.";
            } elseif (! self::encodable($text)) {
                $v[] = "{$at}: Buchungstext enthält Zeichen, die nicht in CP1252 darstellbar sind.";
            }

            if (is_int($b['amount_cents'] ?? null) && in_array($side, ['S', 'H'], true)) {
                $signed = $side === 'S' ? $b['amount_cents'] : -$b['amount_cents'];
                $byAccount[(string) $b['account']] = ($byAccount[(string) $b['account']] ?? 0) + $signed;
                $byContra[(string) $b['contra_account']] = ($byContra[(string) $b['contra_account']] ?? 0) + $signed;
            }
        }

        foreach ($byAccount as $account => $saldo) {
            if ($saldo !== 0) {
                $v[] = "Sachkonto {$account}: Summe Soll ≠ Summe Haben (Differenz " . self::amount(abs($saldo)) . ').';
            }
        }
        foreach ($byContra as $contra => $saldo) {
            if ($saldo !== 0) {
                $v[] = "Gegenkonto {$contra} saldiert nicht 0 (Differenz " . self::amount(abs($saldo)) . ').';
            }
        }

        return $v;
    }

    /** Betrag in Cent → DATEV-Format: Dezimalkomma, keine Tausenderpunkte, nur nötige Nachkommastellen. */
    public static function amount(int $cents): string
    {
        $euros = intdiv($cents, 100);
        $rest  = $cents % 100;
        if ($rest === 0) {
            return (string) $euros;
        }

        return $euros . ',' . rtrim(sprintf('%02d', $rest), '0');
    }

    /** Dateiname `EXTF_<Bezeichnung>.csv` — ohne Präfix lehnt DATEV ab (REW04506). */
    public static function filename(string $label): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $label), '_');

        return 'EXTF_' . ($slug !== '' ? $slug : 'Buchungsstapel') . '.csv';
    }

    private function preamble(array $meta): string
    {
        return implode(';', [
            '"EXTF"',
            '510',
            '21',
            'Buchungsstapel',
            '7',
            $meta['created_at']->format('YmdHis') . '000',
            '',
            '"BP"',
            '""',
            '""',
            (string) $meta['consultant'],
            (string) $meta['client'],
            $meta['fiscal_year_start']->format('Ymd'),
            (string) $meta['account_length'],
            $meta['period_from']->format('Ymd'),
            $meta['period_to']->format('Ymd'),
            '"' . $meta['label'] . '"',
            '"' . $meta['initials'] . '"',
            '1',
            '0',
            '0',   // Festschreibung — nie leer, leer kann sofort festschreiben
            'EUR',
            // 22–30 reserviert: Leer-Muster wie im Beispiel der Anleitung; das letzte Feld ist leer
            // (Semikolon am Zeilenende), sonst zählt DATEV nur 30 Felder.
            '', '""', '', '""', '', '""', '""', '""', '',
        ]);
    }

    private static function normalize(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }

    private static function encodable(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8')
            && mb_convert_encoding(mb_convert_encoding($value, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') === $value;
    }

    private static function plain(string $value): bool
    {
        return ! preg_match('/[;"\r\n]/', $value) && self::encodable($value);
    }
}
