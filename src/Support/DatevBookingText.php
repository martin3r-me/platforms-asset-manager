<?php

namespace Platform\AssetManager\Support;

/**
 * Buchungstext einer Umbuchung nach „Stil C" der Buchhaltung:
 *
 *     Umb. [<Quell-KST>|<Ziel-KST>] <Leistung> <Stapelmonat>
 *
 * Quellzeile mit Ziel „diverse", je Zielzeile die Ziel-KST, max. 60 Zeichen. Gekürzt wird nur die
 * Leistung — Klammer-Tag und Stapelmonat bleiben immer stehen, damit DATEV Quell- und Zielzeilen
 * zusammen findet. Stapelmonat: Monatskürzel + zweistelliges Jahr (Jan26 … Mrz26 … Okt26).
 */
final class DatevBookingText
{
    public const MAX = 60;

    private const MONTHS = ['Jan', 'Feb', 'Mrz', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /** @param ?string $target Ziel-KST; null = Quellzeile („diverse") */
    public static function reallocation(string $source, ?string $target, string $service, \DateTimeInterface $month): string
    {
        $prefix = 'Umb. [' . $source . '|' . ($target ?? 'diverse') . '] ';
        $suffix = ' ' . self::month($month);
        $room   = self::MAX - mb_strlen($prefix) - mb_strlen($suffix);

        if ($room < 1) {
            throw new \InvalidArgumentException("Buchungstext: Kostenstellen „{$source}|{$target}“ lassen keinen Platz für die Leistung.");
        }

        $service = trim((string) preg_replace('/\s+/', ' ', $service));
        $service = rtrim(mb_substr($service, 0, $room));

        return $prefix . $service . $suffix;
    }

    /** Stapelmonat, z. B. „Sep26". */
    public static function month(\DateTimeInterface $month): string
    {
        return self::MONTHS[(int) $month->format('n') - 1] . $month->format('y');
    }
}
