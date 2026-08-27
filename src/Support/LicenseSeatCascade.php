<?php

namespace Platform\AssetManager\Support;

use Platform\AssetManager\Models\AssetLicenseContractLine;

/**
 * Die Verteilungs-Kaskade: welcher Seat wird von welcher Vertragszeile bezahlt.
 *
 * Reine Rechenlogik ohne Datenbank — deshalb vollständig prüfbar, und das ist hier wichtig, weil das
 * Ergebnis darüber entscheidet, auf welcher Kostenstelle vierstellige Monatsbeträge landen.
 *
 * ## Warum überhaupt verteilt wird
 *
 * Eine Rechnung enthält mehrere Tarif-Varianten derselben Lizenz — 118 Seats Business Premium aus dem
 * Jahresvertrag zu 15,14 € und 15 Seats im Monatstarif zu 22,87 €. Graph verrät **nicht**, welche
 * Person auf welchem Tarif sitzt; diese Information existiert nirgends. Gemittelt wird trotzdem
 * nicht: ein Durchschnitt von 16,01 € stünde auf keiner Rechnung. Stattdessen behält jede
 * Vertragszeile ihren echten Preis, und die bezahlten **Mengen** werden nach fester Regel verteilt.
 *
 * ## Die Reihenfolge (verbindlich)
 *
 * 1. **Funktionskonten erhalten die Monatspreis-Zeilen.** Service-Accounts sind der Ort, an dem
 *    Flexibilität etwas wert ist — sie kommen und gehen mit Systemen, nicht mit Arbeitsverhältnissen.
 * 2. **Übrige Monats-Seats gehen gesammelt auf die Überhang-Kostenstelle**, nicht auf willkürlich
 *    gewählte Personen. Diese Menge ist die eigentliche Kennzahl: jeder Seat hier ist eine teure
 *    Monatslizenz, die auf Jahresbindung umstellbar wäre.
 * 3. **Überzählige Funktionskonten erhalten Bindungspreis-Zeilen.**
 * 4. **Personen erhalten Bindungspreis-Zeilen.**
 * 5. **Der unbesetzte Rest geht auf die Pool-Kostenstelle** — bezahlter Leerstand, sichtbar gemacht.
 *
 * ## Stabilität statt Willkür
 *
 * Weil die Zuordnung gesetzt und nicht hergeleitet ist, darf sie sich nicht ohne Anlass ändern: eine
 * Abteilung, die im Juli 15,14 € trägt und im August 22,87 €, ohne dass jemand etwas getan hat, wäre
 * nicht erklärbar. Jeder Schritt bevorzugt deshalb Empfänger, die **im Vormonat bereits auf derselben
 * Vertragszeile** saßen (erkannt am `edition_key`, weil die Zeilen selbst monatlich neu entstehen).
 * Erst danach wird nach Kennung sortiert aufgefüllt — auch das deterministisch, damit zwei Läufe über
 * dieselben Daten dasselbe Ergebnis liefern.
 *
 * ## Vollständigkeit
 *
 * Jede bezahlte Menge landet entweder an einem Seat oder auf einer der beiden Sammel-Kostenstellen.
 * Die Bilanz `zugeordnet + Überhang + Pool = Rechnungsmenge` wird pro Zeile geprüft; eine Abweichung
 * ist ein Fehler ohne Toleranz, denn sie bedeutet verlorenes Geld in der Kostenaufteilung.
 */
final class LicenseSeatCascade
{
    /**
     * Mengen einer Lizenz auf ihre Empfänger verteilen.
     *
     * @param list<array{id: int|string, edition_key: string, binding: string, quantity: float, unit_price: float}> $lines
     *        Vertragszeilen dieser Lizenz im Abrechnungsmonat.
     * @param list<array{id: int|string, upn: string, is_function: bool, complete: bool, previous_key: ?string}> $receivers
     *        Lizenzzuweisungen. `is_function` = Funktionskonto, `complete` = System und Kostenstelle
     *        gepflegt (ohne beides greift der Vorrang aus Schritt 1 nicht), `previous_key` = die
     *        Vertragszeile des Vormonats.
     * @return array{
     *     seats: array<string, array{line_id: int|string, unit_price: float}>,
     *     overflow: array<string, float>,
     *     pool: array<string, float>,
     *     unpaid: list<int|string>,
     *     incomplete_functions: list<int|string>
     * }
     */
    public function distribute(array $lines, array $receivers): array
    {
        $monthly = $this->sortLines(array_values(array_filter(
            $lines,
            fn ($l) => $l['binding'] !== AssetLicenseContractLine::BINDING_YEARLY,
        )));

        $yearly = $this->sortLines(array_values(array_filter(
            $lines,
            fn ($l) => $l['binding'] === AssetLicenseContractLine::BINDING_YEARLY,
        )));

        // Ein Funktionskonto ohne System oder Kostenstelle ist nicht bewirtschaftbar; es bekommt
        // keinen Vorrang, wird aber weiter versorgt (Schritt 4) und gemeldet. Der Seat unbezahlt zu
        // lassen wäre die schlechtere Wahl: Der Betrag verschwände aus der Kostenaufteilung, obwohl er
        // bezahlt ist — und die fehlende Pflege wäre nirgends als Ursache erkennbar.
        $functions = array_values(array_filter($receivers, fn ($r) => $r['is_function'] && $r['complete']));
        $others    = array_values(array_filter($receivers, fn ($r) => ! ($r['is_function'] && $r['complete'])));

        $incomplete = array_map(
            fn ($r) => $r['id'],
            array_values(array_filter($receivers, fn ($r) => $r['is_function'] && ! $r['complete'])),
        );

        $seats     = [];
        $remaining = [];

        foreach ($lines as $line) {
            $remaining[(string) $line['id']] = (int) round($line['quantity']);
        }

        // 1. Funktionskonten ← Monatspreis-Zeilen
        $functions = $this->fill($monthly, $functions, $remaining, $seats);

        // 2. Was von den Monats-Seats übrig ist, geht gesammelt auf die Überhang-Kostenstelle.
        $overflow = [];

        foreach ($monthly as $line) {
            $key  = (string) $line['id'];
            $left = $remaining[$key] ?? 0;

            if ($left > 0) {
                $overflow[$key]   = (float) $left;
                $remaining[$key]  = 0;
            }
        }

        // 3. Überzählige Funktionskonten ← Bindungspreis-Zeilen
        $functions = $this->fill($yearly, $functions, $remaining, $seats);

        // 4. Personen (und unvollständige Funktionskonten) ← Bindungspreis-Zeilen
        $others = $this->fill($yearly, $others, $remaining, $seats);

        // 5. Der unbesetzte Rest ist bezahlter Leerstand.
        $pool = [];

        foreach ($remaining as $key => $left) {
            if ($left > 0) {
                $pool[$key] = (float) $left;
            }
        }

        return [
            'seats'                => $seats,
            'overflow'             => $overflow,
            'pool'                 => $pool,
            // Zugewiesen, aber von keiner Rechnungsposition bezahlt — entweder gratis oder eine Lücke.
            'unpaid'               => array_map(fn ($r) => $r['id'], array_merge($functions, $others)),
            'incomplete_functions' => $incomplete,
        ];
    }

    /**
     * Empfänger aus den Kontingenten der übergebenen Zeilen versorgen.
     *
     * @param list<array<string, mixed>>  $lines
     * @param list<array<string, mixed>>  $receivers  noch unversorgte Empfänger
     * @param array<string, int>          $remaining  freie Menge je Zeile (wird verringert)
     * @param array<string, array>        $seats      Ergebnis je Empfänger (wird gefüllt)
     * @return list<array<string, mixed>> die weiterhin unversorgten Empfänger
     */
    private function fill(array $lines, array $receivers, array &$remaining, array &$seats): array
    {
        foreach ($lines as $line) {
            $key = (string) $line['id'];

            if (($remaining[$key] ?? 0) <= 0 || $receivers === []) {
                continue;
            }

            // Zuerst die Empfänger, die im Vormonat schon auf dieser Vertragszeile saßen — daher die
            // Stabilität der Zuordnung. Danach nach Kennung, damit die Reihenfolge reproduzierbar ist.
            $receivers = $this->sortReceivers($receivers, $line['edition_key']);

            $keep = [];

            foreach ($receivers as $receiver) {
                if (($remaining[$key] ?? 0) > 0) {
                    $seats[(string) $receiver['id']] = [
                        'line_id'    => $line['id'],
                        'unit_price' => (float) $line['unit_price'],
                    ];
                    $remaining[$key]--;
                    continue;
                }

                $keep[] = $receiver;
            }

            $receivers = $keep;
        }

        return $receivers;
    }

    /**
     * Zeilen in verbindliche Reihenfolge bringen: größte Menge zuerst, bei Gleichstand der
     * Positionsname. Die größte Menge ist der Haupttarif — ihn zuerst zu vergeben hält die Verteilung
     * auch dann stabil, wenn eine kleine Nebenposition dazukommt oder wegfällt.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function sortLines(array $lines): array
    {
        usort($lines, fn ($a, $b) => [$b['quantity'], $a['edition_key']] <=> [$a['quantity'], $b['edition_key']]);

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $receivers
     * @return list<array<string, mixed>>
     */
    private function sortReceivers(array $receivers, string $editionKey): array
    {
        usort($receivers, function ($a, $b) use ($editionKey) {
            $aSticky = ($a['previous_key'] ?? null) === $editionKey ? 0 : 1;
            $bSticky = ($b['previous_key'] ?? null) === $editionKey ? 0 : 1;

            return [$aSticky, $a['upn']] <=> [$bSticky, $b['upn']];
        });

        return $receivers;
    }
}
