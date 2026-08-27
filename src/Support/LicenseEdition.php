<?php

namespace Platform\AssetManager\Support;

/**
 * Namensarbeit an den Positionsnamen einer Lizenz-Rechnung.
 *
 * Der Wiederverkäufer schreibt in die Spalte „Edition" keinen Produktnamen, sondern eine
 * Artikelbezeichnung: Tarifform, Laufzeit, Rahmenvertrag und teils der abgerechnete Zeitraum stecken
 * mit drin. Drei Beispiele aus echten Rechnungen (05–07/2026):
 *
 *   NCE Microsoft 365 Business Premium 12 (SMB300 Standalone) - P1Y
 *   NCE Microsoft 365 Business Premium 12 (SMB300 Standalone) - P1Y - 12 M 30%
 *   Microsoft 365 Copilot Business (Standalone Add-on) - Microsoft 365 Copilot Business - Per User Fee Zeitraum von 07/01/26 bis 07/23/26
 *
 * Daraus werden **zwei verschiedene** Schlüssel gebraucht, und sie dürfen nicht verwechselt werden:
 *
 * - {@see key()} — die **Identität der Rechnungsposition**. Stabil über Monate, damit ein erneuter
 *   Import dieselbe Zeile trifft und ein gelernter Alias hält. Die Tarifform bleibt drin: „NCE …
 *   Business Premium 12" und „Microsoft 365 Business Premium" sind zwei Artikel mit zwei Preisen
 *   (15,14 € und 22,87 €), auch wenn sie auf dieselbe Lizenz zeigen.
 * - {@see productTerm()} — der **Produktbegriff** für den Vergleich mit Microsoft Graph. Hier fällt
 *   genau der Teil weg, der Vertrag und nicht Produkt ist, damit beide Beispiele oben auf
 *   „microsoft 365 business premium" zusammenlaufen und dieselbe SKU treffen.
 *
 * Anlass für die Trennung: der Anbieter hat seine Artikel zwischen Mai und Juni 2026 um „- P1Y"
 * erweitert. Am Rohnamen aufgehängt wäre daraus ein neues Produkt geworden — mit neuer Zeile, neu
 * verlorener Zuordnung und einem Preis, der ohne Grund von vorn anfängt.
 */
final class LicenseEdition
{
    /**
     * Wortbestandteile, die den Rahmenvertrag beschreiben und nicht das Produkt. Sie fliegen nur
     * für {@see productTerm()} heraus, nie für die Identität der Position.
     */
    private const CONTRACT_NOISE = [
        'nce',                 // „New Commerce Experience" — Microsofts Vertragsmodell
        'smb300 standalone',   // Rahmenvertrag des Wiederverkäufers
        'standalone add-on',
        'standalone addon',
        'addon',
        'add-on',
        'p1y', 'p1m', 'p3y',   // Laufzeit-Codes
    ];

    /** Füllwörter, die für den Produktvergleich nichts unterscheiden. */
    private const STOP_WORDS = ['for', 'with', 'per', 'and', 'the', 'plan', 'user', 'users', 'fee', 'von', 'bis'];

    /**
     * Identität der Rechnungsposition — kleingeschrieben, ohne Zeitraum- und Rabatt-Anhänge,
     * mit normalisierten Leerzeichen. Tarifform und Laufzeit bleiben erhalten.
     */
    public static function key(string $edition): string
    {
        $value = self::stripPeriodSuffix($edition);
        $value = self::stripDiscountSuffix($value);
        $value = self::stripContractPeriod($value);

        return self::squash($value);
    }

    /**
     * Produktbegriff für den Graph-Vergleich: zusätzlich ohne Vertrags-Vokabular, Laufzeit-Zahl und
     * Satzzeichen. Aus „NCE Microsoft 365 Business Premium 12 (SMB300 Standalone) - P1Y" wird
     * „microsoft 365 business premium".
     */
    public static function productTerm(string $edition): string
    {
        $value = self::key($edition);

        // Klammerinhalte, die Vertrags-Vokabular sind, komplett entfernen — „(SMB300 Standalone)".
        // Andere Klammern (etwa „(Plan 2)") tragen Produktbedeutung und bleiben, nur ohne Klammern.
        $value = self::replace('/\((?:[^()]*(?:standalone|smb\d+|nce)[^()]*)\)/iu', ' ', $value);
        $value = self::stripContractNoise($value);

        // Laufzeit in Monaten als freistehende Zahl („… Business Premium 12 …"). Zahlen, die zum
        // Produktnamen gehören, hängen an einem Namensteil („Microsoft 365", „Plan 2"); nur die
        // bekannten Laufzeiten werden entfernt, damit aus „Plan 2" nicht „Plan" wird.
        $value = self::replace('/(?<![\p{L}\d])(?:12|24|36)(?![\p{L}\d])/u', ' ', $value);

        $value = str_replace(['(', ')', '[', ']', '_', '/', ',', '.', ':', '-'], ' ', $value);

        return self::squash($value);
    }

    /**
     * Basisname einer Rabattzeile: der Positionsname, zu dem sie gehört. „Exchange Online (Plan 2) -
     * 12 m 18%" wird zu „Exchange Online (Plan 2)". Gibt null zurück, wenn kein Rabatt-Anhang
     * erkennbar ist — dann ist die Zeile trotz Einheit „%" nicht im erwarteten Format.
     */
    public static function discountBase(string $edition): ?string
    {
        $parts = preg_split('/\s+-\s+/u', trim($edition)) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        $last = array_pop($parts);

        if (! str_ends_with(trim($last), '%')) {
            return null;
        }

        return implode(' - ', $parts);
    }

    /** Ist das eine Rabattzeile? Entscheidend ist die Einheit „%" der Rechnung, nicht der Name. */
    public static function isDiscountUnit(string $unit): bool
    {
        return trim($unit) === '%';
    }

    /**
     * Vergleichsbegriff für einen Graph-Namen (`display_name` oder `sku_part_number`). Graph liefert
     * beides gemischt: sprechende Namen („Microsoft 365 Business Premium") und rohe Part-Numbers
     * („EXCHANGEARCHIVE_ADDON", „Microsoft_365_Copilot") — Unterstriche sind dort Worttrenner.
     */
    public static function graphTerm(string $name): string
    {
        $value = str_replace(['_', '-', '(', ')', '/', '.'], ' ', $name);

        // Dieselbe Noise-Filterung wie auf der Rechnungsseite. Ohne sie ist der Vergleich asymmetrisch
        // und scheitert an Wörtern, die auf beiden Seiten nichts unterscheiden: „NCE Exchange Online
        // Archiving for Exchange Online - Addon" verliert sein „Addon", `EXCHANGEARCHIVE_ADDON` aber
        // nicht — und dann trennt genau dieses Wort zwei Namen, die dasselbe Produkt meinen.
        return self::squash(self::stripContractNoise($value));
    }

    /** Vertrags-Vokabular entfernen, das kein Produkt unterscheidet. */
    private static function stripContractNoise(string $value): string
    {
        foreach (self::CONTRACT_NOISE as $noise) {
            $value = self::replace(
                '/(?<![\p{L}\d])' . preg_quote($noise, '/') . '(?![\p{L}\d])/iu',
                ' ',
                $value,
            );
        }

        return $value;
    }

    /** @return list<string> Tokens eines Vergleichsbegriffs, ohne inhaltsleere Füllwörter. */
    public static function tokens(string $term): array
    {
        $tokens = preg_split('/[^\p{L}\d]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            fn ($token) => ! in_array($token, self::STOP_WORDS, true),
        )));
    }

    /**
     * Zeitraum-Anhang der Copilot-Positionen entfernen. Der Anbieter hängt dort den abgerechneten
     * Zeitraum an den Artikelnamen — in zwei Sprachfassungen, die in denselben Dateien vorkommen
     * („Per User Fee Zeitraum von 05/05/26 bis 05/23/26" und „Gebühr pro Benutzer Zeitraum von …").
     * Bliebe er stehen, wäre jede Teilperiode ein eigenes Produkt und der Preis nie ableitbar.
     */
    private static function stripPeriodSuffix(string $edition): string
    {
        $pattern = '/\s+-\s+(?:.*?\s+-\s+)?'
            . '(?:Per\s+User\s+Fee|Geb(?:ü|ue)hr\s+pro\s+Benutzer|User\s+Fee)'
            . '\s+Zeitraum\s+von\b.*$/iu';

        return self::replace($pattern, '', trim($edition));
    }

    /** Rabatt-Anhang entfernen — „ - 12 m 18%", „ - Prozentualer Rabatt 8%". */
    private static function stripDiscountSuffix(string $edition): string
    {
        return self::discountBase($edition) ?? $edition;
    }

    /**
     * Laufzeit-Code am Ende entfernen („ - P1Y"). Er kam im Juni 2026 zu allen NCE-Artikeln hinzu,
     * ohne dass sich Produkt oder Preis änderten — er darf die Identität der Position nicht ändern.
     */
    private static function stripContractPeriod(string $edition): string
    {
        return self::replace('/\s+-\s+P\d+[YMD]\s*$/iu', '', $edition);
    }

    /** Kleinschreiben, Leerraum vereinheitlichen. */
    private static function squash(string $value): string
    {
        return trim(mb_strtolower(self::replace('/\s+/u', ' ', $value), 'UTF-8'));
    }

    /** preg_replace, das bei einem Regex-Fehler den Eingabewert behält statt null zu liefern. */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
