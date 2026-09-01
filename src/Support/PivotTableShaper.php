<?php

namespace Platform\AssetManager\Support;

/**
 * Formt den Kosten-Pivot (`CostAggregationService::costCenterByType()`) in ein Anzeige-Modell um:
 * Spalten in gewählter Reihenfolge und Breite, jede Zeile mit ihren Vorfahren und Flags
 * (docs/adr/0020).
 *
 * **Der Shaper blendet nichts aus.** Er rendert immer alle Spalten und alle Zeilen und markiert sie
 * nur — Sichtbarkeit und Faltung entscheidet der Browser über eine generierte CSS-Regel. Grund: jede
 * Sichtbarkeits-Änderung wäre sonst ein Livewire-Roundtrip, und der lässt `render()` das komplette
 * Kostenmodell neu berechnen (`normalizedLines()` lädt Kostenzeilen, alle Asset-Träger, alle Items,
 * das Lizenz-Preisbuch und die Gerätekosten). Ein Klick auf „Spalte ausblenden" kostete damit die
 * Rechenzeit einer ganzen Auswertung. Genau das war der Ruckler.
 *
 * Die einzige Änderung, die weiter serverseitig rendert, ist das **Verschieben** einer Spalte: dabei
 * ändert sich die Zellen-Reihenfolge im DOM, und die kann CSS nicht umsortieren.
 *
 * **Rechnet nichts nach.** Beträge, Spalten- und Gesamtsummen kommen unverändert aus dem Pivot; die
 * Ansicht entscheidet ausschließlich, was zu sehen ist. Ausgeblendetes zählt weiter in die Summen —
 * so wie Excel über versteckte Zeilen summiert.
 */
final class PivotTableShaper
{
    /** Schlüssel der beiden festen Spalten (nicht verschiebbar, nicht ausblendbar, aber breitenbar). */
    public const LABEL_KEY = 'label';
    public const TOTAL_KEY = 'total';

    /**
     * Default-Breiten in px. Die Kostenart-Spalten bewusst schmal (die Tabelle hat viele davon), die
     * Kostenstellen-Spalte dagegen breit genug für den längsten echten Fall („verwaltung BROICH -
     * VERWALTUNG" braucht mit Einrückung und Klapp-Pfeil rund 240 px Text): ein abgeschnittener
     * Kostenstellen-Name macht die Zeile unlesbar, eine abgeschnittene Spaltenüberschrift nicht.
     */
    public const DEFAULT_LABEL_WIDTH  = 280;
    public const DEFAULT_COLUMN_WIDTH = 96;
    public const DEFAULT_TOTAL_WIDTH  = 112;

    /** Unter dieser Grenze gilt ein Betrag als „nichts" (Rundungsreste aus der Aggregation). */
    private const EPSILON = 0.005;

    /** Einrückung je Baumebene in px und die Ebene, ab der nicht weiter eingerückt wird. */
    private const INDENT_PX         = 14;
    private const MAX_INDENT_LEVELS = 6;

    public function __construct(private readonly TableViewState $state)
    {
    }

    /**
     * @param  array<string,mixed>  $pivot  Rückgabe von CostAggregationService::costCenterByType()
     * @return array<string,mixed> Anzeige-Modell für `_pivot-table.blade.php`
     */
    public function shape(array $pivot): array
    {
        $columns = $this->columns($pivot);

        return [
            'columns'    => $columns,
            'rows'       => $this->rows($pivot),
            'labelWidth' => $this->state->width(self::LABEL_KEY, self::DEFAULT_LABEL_WIDTH),
            'totalWidth' => $this->state->width(self::TOTAL_KEY, self::DEFAULT_TOTAL_WIDTH),
            // Der Zustand, den der Browser übernimmt — in der Transportform von TableViewState, damit
            // er unverändert zurückgeschickt werden kann. Von hier an rechnet der Browser Sichtbarkeit,
            // Zähler und Tabellenbreite selbst; der Server sieht den Zustand erst beim nächsten
            // Voll-Render wieder.
            'view'       => $this->state->toArray(),
            'defaults'   => [
                'label'  => self::DEFAULT_LABEL_WIDTH,
                'column' => self::DEFAULT_COLUMN_WIDTH,
                'total'  => self::DEFAULT_TOTAL_WIDTH,
            ],
        ];
    }

    /**
     * Spalten in Anzeige-Reihenfolge, jede mit Breite, Metadaten und Ausgangs-Sichtbarkeit.
     *
     * @return array<int,array<string,mixed>>
     */
    private function columns(array $pivot): array
    {
        $types = $pivot['types'] ?? [];
        $byKey = [];
        foreach ($types as $type) {
            $byKey[(string) $type['id']] = $type;
        }

        $columns = [];
        foreach ($this->state->orderColumns(array_keys($byKey)) as $key) {
            $type = $byKey[$key];
            $meta = $pivot['meta'][$type['id']] ?? [];

            $columns[] = [
                'key'       => $key,
                'id'        => $type['id'],
                'name'      => $type['name'],
                'vendor'    => $meta['vendor'] ?? null,
                'system'    => $meta['system'] ?? null,
                'frequency' => $meta['frequency'] ?? null,
                'width'     => $this->state->width($key, self::DEFAULT_COLUMN_WIDTH),
                // Spalte ohne jeden Betrag — Grundlage des Schnellfilters „leere Spalten ausblenden".
                'empty'     => abs((float) ($pivot['colTotals'][$type['id']] ?? 0)) < self::EPSILON,
                'hidden'    => $this->state->isColumnHidden($key),
            ];
        }

        return $columns;
    }

    /**
     * Alle Zeilen in Baum-Reihenfolge, jede mit der Kette ihrer Vorfahren.
     *
     * Die Vorfahren-Kette ist der Trick, mit dem die Faltung ohne Server auskommt: eine eingeklappte
     * Gruppe `cc:100` versteckt ihre Nachfahren als eine CSS-Regel
     * (`[data-ancestors~="cc:100"]{display:none}`) — inklusive Enkel, ohne Baumlogik im Browser.
     * Abgeleitet wird sie aus der Tiefe, denn die Zeilen kommen in Baum-Reihenfolge: ein Knoten der
     * Tiefe d hat als Vorfahren genau die zuletzt gesehenen Knoten der Tiefen 0…d-1.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $pivot): array
    {
        $rows    = [];
        $lineage = [];   // Tiefe → Schlüssel des dort zuletzt gesehenen Knotens

        foreach ($this->allRows($pivot) as $row) {
            $depth = max(0, (int) ($row['depth'] ?? 0));
            $key   = $row['_key'];

            $lineage          = array_slice($lineage, 0, $depth);
            $ancestors        = $lineage;
            $lineage[$depth]  = $key;

            $isGroup = ! empty($row['has_children']);

            // Gruppen zeigen den Rollup (eigene + Nachfahren), Blätter ihre eigenen Zahlen. Das gilt
            // eingeklappt wie ausgeklappt: die Gruppenzeile trägt immer die Summe ihres Teilbaums.
            $cells = $isGroup ? ($row['rollupCells'] ?? $row['cells']) : $row['cells'];
            $total = $isGroup ? ($row['rollupTotal'] ?? $row['rowTotal']) : $row['rowTotal'];

            $rows[] = [
                'key'       => $key,
                'code'      => $row['code'],
                'name'      => $row['name'],
                'depth'     => $depth,
                // Einrückung in px, gedeckelt: die Baumtiefe ist fachlich unbegrenzt, die Spalte nicht.
                'indent'    => min($depth, self::MAX_INDENT_LEVELS) * self::INDENT_PX,
                'isGroup'   => $isGroup,
                'collapsed' => $isGroup && $this->state->isRowCollapsed($key),
                'ancestors' => implode(' ', $ancestors),
                'empty'     => abs((float) $total) < self::EPSILON,
                'hidden'    => $this->state->isRowHidden($key),
                'cells'     => $cells,
                'total'     => $total,
            ];
        }

        return $rows;
    }

    /**
     * Baumzeilen plus die beiden Auffangzeilen, jede mit stabilem Ansicht-Schlüssel.
     *
     * Der Schlüssel hängt an der Kostenstellen-ID, nicht am Code: Codes sind im Baum nicht garantiert
     * eindeutig, und ein umbenannter Code würde sonst die gespeicherte Ansicht verlieren. Die
     * Auffangzeilen haben keine ID und bekommen feste Schlüssel.
     *
     * @return array<int,array<string,mixed>>
     */
    private function allRows(array $pivot): array
    {
        $rows = [];

        foreach ($pivot['rows'] ?? [] as $row) {
            $row['_key'] = 'cc:' . (int) ($row['cost_center_id'] ?? 0);
            $rows[]      = $row;
        }

        foreach (['unassigned' => 'x:unassigned', 'orphan' => 'x:orphan'] as $slot => $key) {
            if (! empty($pivot[$slot])) {
                $row         = $pivot[$slot];
                $row['_key'] = $key;
                $rows[]      = $row;
            }
        }

        return $rows;
    }
}
