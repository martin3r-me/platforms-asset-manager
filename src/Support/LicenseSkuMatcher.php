<?php

namespace Platform\AssetManager\Support;

use Platform\AssetManager\Models\AssetLicenseContractLine;

/**
 * Ordnet eine Rechnungsposition einer Microsoft-Graph-Lizenz zu.
 *
 * ## Warum nicht einfach unscharf vergleichen
 *
 * Ein falsch zugeordneter Preis wird über alle Seats dieser Lizenz multipliziert — bei Business
 * Premium über 131. Ein Beinahe-Treffer ist deshalb nicht besser als kein Treffer, sondern
 * schlechter: kein Treffer fällt auf und wird einmal von Hand entschieden, ein falscher Treffer sieht
 * richtig aus. Der Matcher rät daher nie auf Namensähnlichkeit allein, sondern verlangt für jeden
 * unscharfen Treffer ein **zweites, unabhängiges Signal** — die Menge.
 *
 * ## Die Stufen (erste, die trägt, gewinnt)
 *
 * 1. **Begriffsgleichheit.** Der Produktbegriff der Position stimmt mit `display_name` oder
 *    `sku_part_number` überein, beide normalisiert. Trifft „Microsoft 365 Business Premium" → `SPB`
 *    und „Microsoft 365 Copilot" → `Microsoft_365_Copilot`. Zwei Positionen dürfen dabei auf
 *    dieselbe SKU laufen — genau das sind die Tarif-Varianten.
 * 2. **Wortmenge plus Mengenbestätigung.** Die Wörter der einen Seite sind in der anderen enthalten
 *    *und* die Rechnungsmenge trifft `purchased_units` oder `consumed_units`. So findet
 *    „Microsoft 365 Copilot Business (Standalone Add-on)" (3 Seats) die SKU
 *    `MICROSOFT_365_COPILOT_FOR_BUSINESS` (3/3), ohne mit „Microsoft 365 Copilot" (2/2) zu kollidieren.
 * 3. **Wortüberschneidung plus eindeutige Mengenbestätigung.** Mindestens zwei tragende Wörter
 *    gemeinsam (mit Wortstamm-Vergleich, damit „Archiving" und „ARCHIVE" zusammenfinden) und **genau
 *    ein** Kandidat mit passender Menge. So findet „Power Automate per user with attended RPA plan"
 *    die SKU `POWERAUTOMATE_ATTENDED_RPA` über „attended"/„rpa" — die Menge 2 allein wäre
 *    mehrdeutig, weil fünf SKUs im Tenant zwei Seats haben.
 *
 * Alles andere bleibt offen und wartet auf eine Entscheidung, die dann als Alias gelernt wird.
 */
final class LicenseSkuMatcher
{
    /** Ab dieser Länge gilt ein gemeinsamer Wortanfang als derselbe Wortstamm („archiv|ing" / „archiv|e"). */
    private const STEM_LENGTH = 5;

    /** Mindestzahl gemeinsamer tragender Wörter für Stufe 3. */
    private const MIN_SHARED_TOKENS = 2;

    /**
     * @param list<array{sku_id: string, sku_part_number: string, display_name: ?string, purchased_units: int, consumed_units: int}> $skus
     */
    public function __construct(private array $skus) {}

    /**
     * Zuordnung für eine Position bestimmen.
     *
     * @param string $edition  Positionsname, wie er auf der Rechnung steht
     * @param float  $quantity Rechnungsmenge (Bestand zum Periodenende)
     * @return array{sku_id: ?string, state: string, note: ?string}
     */
    public function match(string $edition, float $quantity): array
    {
        $term = LicenseEdition::productTerm($edition);

        if ($term === '') {
            return $this->miss('Positionsname ergibt keinen Produktbegriff.');
        }

        return $this->byExactTerm($term)
            ?? $this->byTokenSubset($term, $quantity)
            ?? $this->byTokenOverlap($term, $quantity)
            ?? $this->miss('Kein sicherer Treffer — bitte einmal zuordnen.');
    }

    /** Stufe 1: normalisierter Anzeigename oder Part-Number stimmt überein. */
    private function byExactTerm(string $term): ?array
    {
        foreach ($this->skus as $sku) {
            foreach ($this->graphTerms($sku) as $candidate) {
                if ($candidate === $term) {
                    return $this->hit($sku, 'Produktname stimmt überein.');
                }
            }
        }

        return null;
    }

    /** Stufe 2: Wortmenge der einen Seite in der anderen enthalten + Menge bestätigt. */
    private function byTokenSubset(string $term, float $quantity): ?array
    {
        $tokens = LicenseEdition::tokens($term);

        if ($tokens === []) {
            return null;
        }

        $matches = [];

        foreach ($this->skus as $sku) {
            if (! $this->quantityFits($sku, $quantity)) {
                continue;
            }

            foreach ($this->graphTerms($sku) as $candidate) {
                $candidateTokens = LicenseEdition::tokens($candidate);

                if ($candidateTokens === []) {
                    continue;
                }

                if ($this->isSubset($tokens, $candidateTokens) || $this->isSubset($candidateTokens, $tokens)) {
                    $matches[$sku['sku_id']] = $sku;
                    break;
                }
            }
        }

        // Mehrere Kandidaten heißt: die Wortmenge trennt sie nicht und die Menge auch nicht. Dann ist
        // jede Wahl geraten — das ist genau der Fall, für den es die Handzuordnung gibt.
        if (count($matches) !== 1) {
            return null;
        }

        $sku = reset($matches);

        return $this->hit($sku, 'Produktname enthalten, Menge ' . $this->quantityLabel($quantity) . ' bestätigt.');
    }

    /** Stufe 3: mindestens zwei gemeinsame Wortstämme + genau ein Kandidat mit passender Menge. */
    private function byTokenOverlap(string $term, float $quantity): ?array
    {
        $tokens  = LicenseEdition::tokens($term);
        $matches = [];

        foreach ($this->skus as $sku) {
            if (! $this->quantityFits($sku, $quantity)) {
                continue;
            }

            $best = 0;

            foreach ($this->graphTerms($sku) as $candidate) {
                $best = max($best, $this->sharedTokenCount($tokens, LicenseEdition::tokens($candidate)));
            }

            if ($best >= self::MIN_SHARED_TOKENS) {
                $matches[$sku['sku_id']] = $sku;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $sku = reset($matches);

        return $this->hit($sku, 'Schlüsselwörter und Menge ' . $this->quantityLabel($quantity) . ' passen eindeutig.');
    }

    /**
     * Trifft die Rechnungsmenge den Bestand der Lizenz? Geprüft werden **beide** Zahlen: `purchased`
     * deckt den Fall ab, dass nicht alle bezahlten Seats zugewiesen sind (Business Premium: 133
     * gekauft, 131 belegt), `consumed` den Fall, dass mehr zugewiesen als in dieser Position bezahlt
     * ist. Ganzzahlig verglichen, weil Seats keine Bruchteile haben.
     *
     * @param array{purchased_units: int, consumed_units: int} $sku
     */
    private function quantityFits(array $sku, float $quantity): bool
    {
        $needle = (int) round($quantity);

        if ($needle <= 0) {
            return false;
        }

        return $needle === (int) $sku['purchased_units'] || $needle === (int) $sku['consumed_units'];
    }

    /**
     * @param list<string> $needle
     * @param list<string> $haystack
     */
    private function isSubset(array $needle, array $haystack): bool
    {
        foreach ($needle as $token) {
            if (! $this->containsStem($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function sharedTokenCount(array $a, array $b): int
    {
        $shared = 0;

        foreach ($a as $token) {
            if ($this->containsStem($b, $token)) {
                $shared++;
            }
        }

        return $shared;
    }

    /**
     * Wort in einer Wortliste finden — gleich oder mit gemeinsamem Wortstamm. Nötig, weil der
     * Anbieter „Archiving" schreibt und Graph „ARCHIVE"; ein reiner Gleichheitsvergleich verfehlt das.
     *
     * @param list<string> $tokens
     */
    private function containsStem(array $tokens, string $token): bool
    {
        foreach ($tokens as $candidate) {
            if ($candidate === $token) {
                return true;
            }

            $length = min(mb_strlen($candidate), mb_strlen($token));

            if ($length >= self::STEM_LENGTH
                && mb_substr($candidate, 0, self::STEM_LENGTH) === mb_substr($token, 0, self::STEM_LENGTH)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vergleichsbegriffe einer Lizenz: Anzeigename und Part-Number. Graph liefert beides gemischt —
     * teils sprechende Namen, teils rohe Part-Numbers als Anzeigename.
     *
     * @param array{sku_part_number: string, display_name: ?string} $sku
     * @return list<string>
     */
    private function graphTerms(array $sku): array
    {
        $terms = [];

        foreach ([$sku['display_name'] ?? '', $sku['sku_part_number']] as $name) {
            $term = LicenseEdition::graphTerm((string) $name);

            if ($term !== '' && ! in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /** @param array{sku_id: string} $sku */
    private function hit(array $sku, string $note): array
    {
        return [
            'sku_id' => $sku['sku_id'],
            'state'  => AssetLicenseContractLine::MATCH_AUTO,
            'note'   => $note,
        ];
    }

    private function miss(string $note): array
    {
        return [
            'sku_id' => null,
            'state'  => AssetLicenseContractLine::MATCH_UNMATCHED,
            'note'   => $note,
        ];
    }

    private function quantityLabel(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
    }
}
