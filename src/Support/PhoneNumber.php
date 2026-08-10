<?php

namespace Platform\AssetManager\Support;

/**
 * Rufnummern auf eine vergleichbare Form bringen — der Anker des Vodafone-Rechnungsabgleichs.
 *
 * Dieselbe Nummer steht je nach Quelle völlig verschieden da:
 *
 *   Vodafone-Export   1721234567          (national, ohne führende Null)
 *   Microsoft Entra   +49 172 1234567     (E.164 mit Leerzeichen)
 *   manuell gepflegt  0172/123 45 67      (mit Null, Schrägstrich, Blöcken)
 *
 * Ohne Normalisierung matcht davon nichts, und der Import legt 115 Kostenpositionen ohne Zuordnung
 * an — technisch fehlerfrei und fachlich wertlos. {@see e164()} bringt alle drei auf
 * `+491721234567`.
 */
class PhoneNumber
{
    /** Ländervorwahl, die angenommen wird, wenn die Nummer keine trägt. */
    public const DEFAULT_COUNTRY = '49';

    /**
     * Nummer auf E.164 normalisieren (`+49…`). Gibt `null` zurück, wenn nichts Verwertbares übrig
     * bleibt — der Aufrufer soll „keine Nummer" von „Nummer, die nicht passt" unterscheiden können.
     *
     * Reihenfolge der Fälle ist bewusst: `00` vor `0` prüfen, sonst wird aus `0049…` ein `+490049…`.
     */
    public static function e164(?string $raw, string $defaultCountry = self::DEFAULT_COUNTRY): ?string
    {
        if ($raw === null) {
            return null;
        }

        $hasPlus = str_starts_with(trim($raw), '+');
        $digits  = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            return '+' . self::stripTrunkZero($digits, $defaultCountry);
        }

        if (str_starts_with($digits, '00')) {
            return '+' . self::stripTrunkZero(substr($digits, 2), $defaultCountry);
        }

        if (str_starts_with($digits, '0')) {
            return '+' . $defaultCountry . substr($digits, 1);
        }

        // Ohne Präfix: der Vodafone-Export liefert die nationale Nummer ohne führende Null
        // (z. B. 1721234567). Trägt sie bereits die Ländervorwahl (491721234567), nicht doppeln.
        if (str_starts_with($digits, $defaultCountry) && strlen($digits) > strlen($defaultCountry) + 6) {
            return '+' . $digits;
        }

        return '+' . $defaultCountry . $digits;
    }

    /**
     * Die „(0)" aus Schreibweisen wie `+49 (0)172 1234567` entfernen.
     *
     * Das ist die deutsche Konvention, die Verkehrsausscheidungsziffer für Inlandswähler in Klammern
     * mitzuschreiben. In E.164 hat sie nichts verloren — bliebe sie stehen, wäre `+4901721234567`
     * eine andere Nummer als `+491721234567`, und der Rechnungsabgleich verfehlte den Träger.
     * Nur für die Standard-Ländervorwahl: in Ländern mit anderer Nummerierung kann die Null
     * bedeutungstragend sein.
     */
    protected static function stripTrunkZero(string $digits, string $country): string
    {
        if ($country === self::DEFAULT_COUNTRY && str_starts_with($digits, $country . '0')) {
            return $country . ltrim(substr($digits, strlen($country)), '0');
        }

        return $digits;
    }

    /**
     * Anzeigeform für Labels: `+49 172 1234567` → `0172 1234567`. Rein kosmetisch; der Abgleich
     * läuft immer über {@see e164()}.
     */
    public static function national(?string $raw, string $defaultCountry = self::DEFAULT_COUNTRY): ?string
    {
        $e164 = self::e164($raw, $defaultCountry);
        if ($e164 === null) {
            return null;
        }

        $digits = substr($e164, 1);
        if (str_starts_with($digits, $defaultCountry)) {
            return '0' . substr($digits, strlen($defaultCountry));
        }

        return $e164;
    }
}
