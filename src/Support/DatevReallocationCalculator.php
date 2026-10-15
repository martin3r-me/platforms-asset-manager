<?php

namespace Platform\AssetManager\Support;

/**
 * DATEV-Umbuchungsrechner (Baustein 2, Discussion #26): verteilt Rechnungsbeträge, die die Buchhaltung
 * komplett auf die Sammel-KST gebucht hat, nach den Anteilen der Kostenaufteilung auf die nutzenden
 * Kostenstellen und liefert die Buchungszeilen für {@see DatevExtfWriter}. Reines Rechnen, kein
 * DB-Zugriff: Welche Rechnung, welcher Monat und welche Anteile, entscheidet der Aufrufer.
 *
 * ## Buchungsmuster (Controlling-Vault `05_Kontierungsregeln/Umbuchungen.md`, N-Wege-Verteilung)
 *
 * Je Block (ein Sachkonto, eine Leistung) eine Quellzeile mit der vollen Summe auf der Sammel-KST (H)
 * und eine Zielzeile je KST (S) — auch für den Eigenanteil der Sammel-KST. Das Sachkonto steht im Feld
 * Konto, das Verrechnungskonto 159000 immer im Gegenkonto. Bei Erlöskonten (4xxxxx) dreht sich S/H,
 * ebenso bei einer Gutschrift (negativer Betrag) und bei einer KST mit negativem Anteil (Rabatt).
 *
 * ## Betrag
 *
 * Rechnungsbetrag × Anteil laut Kostenaufteilung, centgenau nach Largest Remainder (Entscheidung Max):
 * jede KST bekommt den abgerundeten Anteil, die Rest-Cent gehen an die größten Nachkommareste, bei
 * Gleichstand an die KST in Code-Reihenfolge. Die Summe der Zielzeilen ist damit immer exakt der
 * Rechnungsbetrag, auch wenn die Kostenaufteilung um Rundungs-Cent abweicht. Gerechnet wird in
 * Ganzzahlen (Cent), nicht in Gleitkomma.
 *
 * ## Befunde blockieren
 *
 * Ein Anteil ohne Kostenstelle, eine KST außerhalb der DATEV-KST-Liste und eine KST des falschen Mandanten
 * (Foodpol: KST 11000 nur im Stapel des Mandanten 10002, nie gemischt) sind Verstöße. Die Buchungen
 * werden trotzdem berechnet (für die Vorschau), dürfen aber nicht geschrieben werden, solange
 * `violations` nicht leer ist.
 */
final class DatevReallocationCalculator
{
    public const CLEARING_ACCOUNT = '159000';

    /** Foodpol läuft immer über einen eigenen Stapel (Controlling-Vault `01_Stammdaten/Mandant.md`). */
    public const FOODPOL_COST_CENTER = '11000';
    public const FOODPOL_CLIENT      = '10002';

    /** @param ?list<string> $knownCostCenters DATEV-KST-Liste; null = nicht prüfen */
    public function __construct(
        private readonly string $client,
        private readonly string $sourceCostCenter = '1520',
        private readonly ?array $knownCostCenters = null,
    ) {}

    /**
     * @param list<array{account: string, service: string, amount_cents: int, shares: array<string, int>}> $blocks
     *        shares: KST-Code => Gewicht (Betrag laut Kostenaufteilung in Cent); '' = ohne Kostenstelle
     * @return array{
     *     bookings: list<array{amount_cents: int, side: string, account: string, contra_account: string, cost_center: string, text: string}>,
     *     violations: list<string>,
     *     blocks: list<array{account: string, service: string, amount_cents: int, lines: int}>
     * }
     */
    public function calculate(\DateTimeInterface $month, array $blocks): array
    {
        $bookings   = [];
        $violations = [];
        $summary    = [];

        foreach ($blocks as $block) {
            $account = (string) $block['account'];
            $service = (string) $block['service'];
            $total   = (int) $block['amount_cents'];

            if ($total === 0) {
                continue;
            }

            $shares = [];
            foreach ($block['shares'] as $code => $weight) {
                $shares[(string) $code] = ((int) $weight) + ($shares[(string) $code] ?? 0);
            }

            $what = "{$service} ({$account})";

            if (array_sum($shares) === 0) {
                $violations[] = "{$what}: die Anteile summieren sich auf 0 — der Betrag " . self::euro($total) . ' ist nicht verteilbar.';
                continue;
            }

            $cents = self::largestRemainder($total, $shares);
            uksort($cents, 'strnatcmp');

            $violations = [...$violations, ...$this->costCenterViolations($what, $cents)];

            // Erlöskonten drehen S/H (Soll mindert Erlös) — Quellzeile S, Zielzeile H.
            $revenue = str_starts_with($account, '4');
            $side    = fn (int $amount, bool $source) => (($amount > 0) xor $source xor $revenue) ? 'S' : 'H';

            $bookings[] = $this->booking($total, $side($total, true), $account, $this->sourceCostCenter,
                DatevBookingText::reallocation($this->sourceCostCenter, null, $service, $month));

            $lines = 0;
            foreach ($cents as $code => $amount) {
                if ($amount === 0) {
                    continue;
                }
                $bookings[] = $this->booking($amount, $side($amount, false), $account, (string) $code,
                    DatevBookingText::reallocation($this->sourceCostCenter, (string) $code === '' ? '?' : (string) $code, $service, $month));
                $lines++;
            }

            $summary[] = ['account' => $account, 'service' => $service, 'amount_cents' => $total, 'lines' => $lines];
        }

        return ['bookings' => $bookings, 'violations' => $violations, 'blocks' => $summary];
    }

    /**
     * Verteilt `$total` Cent im Verhältnis der Gewichte. Ganzzahlig: floor(total × w / W), Rest-Cent an
     * die größten Reste (total × w mod W), bei Gleichstand an die KST in Code-Reihenfolge.
     *
     * @param array<string, int> $weights
     * @return array<string, int>
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $dir = $sum < 0 ? -1 : 1; // W > 0 halten, damit floor und Rest das richtige Vorzeichen haben
        $sum *= $dir;

        $base = [];
        $rest = [];
        foreach ($weights as $code => $weight) {
            $num = $total * $weight * $dir;
            $floor = intdiv($num, $sum) - (($num % $sum !== 0 && $num < 0) ? 1 : 0);
            $base[$code] = $floor;
            $rest[$code] = $num - $floor * $sum;
        }

        $codes = array_keys($weights);
        usort($codes, fn ($a, $b) => ($rest[$b] <=> $rest[$a]) ?: strnatcmp((string) $a, (string) $b));

        $missing = $total - array_sum($base);
        foreach (array_slice($codes, 0, $missing) as $code) {
            $base[$code]++;
        }

        return $base;
    }

    /** @param array<string, int> $cents */
    private function costCenterViolations(string $what, array $cents): array
    {
        $v = [];

        foreach ($cents as $code => $amount) {
            $code = (string) $code;

            if ($code === '') {
                $v[] = "{$what}: " . self::euro($amount) . ' ohne Kostenstelle — erst in der Kostenaufteilung zuordnen.';
                continue;
            }
            if (($code === self::FOODPOL_COST_CENTER) !== ($this->client === self::FOODPOL_CLIENT)) {
                $v[] = "{$what}: KST {$code} gehört nicht in den Stapel des Mandanten {$this->client} (Foodpol nie mischen).";
                continue;
            }
            if ($this->knownCostCenters !== null && ! in_array($code, $this->knownCostCenters, true)) {
                $v[] = "{$what}: KST {$code} steht nicht in der DATEV-KST-Liste.";
            }
        }

        return $v;
    }

    private function booking(int $cents, string $side, string $account, string $costCenter, string $text): array
    {
        return [
            'amount_cents'   => abs($cents),
            'side'           => $side,
            'account'        => $account,
            'contra_account' => self::CLEARING_ACCOUNT,
            'cost_center'    => $costCenter,
            'text'           => $text,
        ];
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }
}
