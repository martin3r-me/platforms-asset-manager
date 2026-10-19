<?php

namespace Platform\AssetManager\Support;

/**
 * Betragseingaben aus Formularfeldern lesen — deutsches und technisches Format.
 *
 * Warum es das gibt (#958): `(float) str_replace(',', '.', $wert)` macht aus „abc" still 0,00 € und
 * aus „1.234,56" 1,234. Beides landet ohne Fehlermeldung in Kosten und Weiterberechnung. Diese
 * Klasse liefert stattdessen `null`, wenn die Eingabe keine Zahl ist — der Aufrufer meldet das.
 *
 * Regeln:
 *  - Enthält die Eingabe ein Komma, ist es das Dezimalzeichen; Punkte davor sind Tausender-Trenner.
 *  - Ohne Komma gilt der Punkt als Dezimalzeichen („12.50" aus Copy-Paste).
 *  - Leerzeichen und ein führendes/abschließendes „€" werden ignoriert.
 */
final class DecimalInput
{
    public static function parse(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(str_replace(["\u{00A0}", ' ', '€'], '', $raw));
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return (float) $value;
    }
}
