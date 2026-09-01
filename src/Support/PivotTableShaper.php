<?php

namespace Platform\AssetManager\Support;

/**
 * Formt den Kosten-Pivot (`CostAggregationService::costCenterByType()`) nach einer gespeicherten
 * Ansicht ({@see TableViewState}) in ein fertiges Anzeige-Modell um: Spalten in gewählter Reihenfolge
 * und Breite, ausgeblendete Spalten und Zeilen aussortiert, eingeklappte Gruppen zusammengefaltet
 * (docs/adr/0020).
 *
 * Warum eine eigene Klasse und nicht im Blade: die Faltung des Baums ist die einzige Stelle mit
 * echter Logik in dieser Ansicht (eine eingeklappte Gruppe schluckt **alle** Nachfahren, nicht nur
 * die direkten Kinder) — und sie muss ohne Datenbank prüfbar bleiben
 * (`tests/local/table-view-state.php`).
 *
 * **Rechnet nichts nach.** Beträge, Spalten- und Gesamtsummen kommen unverändert aus dem Pivot; die
 * Ansicht entscheidet ausschließlich, was zu sehen ist. Ausgeblendetes zählt weiter in die Summen —
 * so wie Excel über versteckte Zeilen summiert. Die Zeile „Summe" wäre sonst je Nutzer eine andere.
 */
final class PivotTableShaper
{
    /** Schlüssel der beiden festen Spalten (nicht verschiebbar, nicht ausblendbar, aber breitenbar). */
    public const LABEL_KEY = 'label';
    public const TOTAL_KEY = 'total';

    /** Default-Breiten in px. Bewusst schmal: die Tabelle hat viele Spalten. */
    public const DEFAULT_LABEL_WIDTH  = 240;
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
        $columns        = $this->columns($pivot);
        $visibleColumns = array_values(array_filter($columns, fn ($c) => ! $c['hidden']));
        $rows           = $this->rows($pivot);

        $labelWidth = $this->state->width(self::LABEL_KEY, self::DEFAULT_LABEL_WIDTH);
        $totalWidth = $this->state->width(self::TOTAL_KEY, self::DEFAULT_TOTAL_WIDTH);

        return [
            'columns'        => $columns,          // alle, in Anzeige-Reihenfolge (für das Spalten-Menü)
            'visibleColumns' => $visibleColumns,
            'rows'           => $rows['visible'],
            // Nur die abweichenden Breiten: der Browser-Spiegel fällt für alles Übrige auf die
            // servergerenderte Default-Breite zurück. So bleibt ein Reset ohne Sonderfall.
            'widths'         => $this->state->widths,
            'labelWidth'     => $labelWidth,
            'totalWidth'     => $totalWidth,
            'tableWidth'     => $labelWidth + $totalWidth + array_sum(array_column($visibleColumns, 'width')),
            'stats'          => [
                'hiddenColumns'      => count($columns) - count($visibleColumns),
                'hiddenRows'         => $rows['hiddenCount'],
                'collapsedGroups'    => $rows['collapsedCount'],
                'foldedRows'         => $rows['foldedCount'],
                'emptyColumnsFilter' => $this->state->hideEmptyColumns,
                'emptyRowsFilter'    => $this->state->hideEmptyRows,
                'customized'         => $this->state->isCustomized(),
            ],
        ];
    }

    /**
     * Spalten in Anzeige-Reihenfolge, jede mit Breite, Metadaten und Sichtbarkeit.
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
            $type  = $byKey[$key];
            $meta  = $pivot['meta'][$type['id']] ?? [];
            $empty = abs((float) ($pivot['colTotals'][$type['id']] ?? 0)) < self::EPSILON;

            // Zwei Gründe, warum eine Spalte weg ist — der Unterschied zählt für die UI: manuell
            // Ausgeblendetes bleibt im Menü angehakt-abwählbar, den Schnellfilter hebt ein Klick auf.
            $hiddenManually = $this->state->isColumnHidden($key);
            $hiddenByFilter = $this->state->hideEmptyColumns && $empty;

            $columns[] = [
                'key'            => $key,
                'id'             => $type['id'],
                'name'           => $type['name'],
                'vendor'         => $meta['vendor'] ?? null,
                'system'         => $meta['system'] ?? null,
                'frequency'      => $meta['frequency'] ?? null,
                'width'          => $this->state->width($key, self::DEFAULT_COLUMN_WIDTH),
                'empty'          => $empty,
                'hidden'         => $hiddenManually || $hiddenByFilter,
                'hiddenManually' => $hiddenManually,
                'hiddenByFilter' => $hiddenByFilter,
            ];
        }

        return $columns;
    }

    /**
     * Zeilen in Baum-Reihenfolge, gefaltet und gefiltert.
     *
     * Die Faltung nutzt die Tiefe statt der Eltern-Kette: die Zeilen kommen in Baum-Reihenfolge, also
     * gehören alle folgenden Zeilen mit größerer Tiefe zum eingeklappten Knoten. Das erfasst auch
     * Enkel — ein Filter auf `parent_code` würde sie durchlassen und die Faltung wäre löchrig.
     *
     * @return array{visible: array<int,array<string,mixed>>, hiddenCount:int, collapsedCount:int, foldedCount:int}
     */
    private function rows(array $pivot): array
    {
        $visible        = [];
        $hiddenCount    = 0;
        $collapsedCount = 0;
        $foldedCount    = 0;
        $foldDepth      = null;

        foreach ($this->allRows($pivot) as $row) {
            $depth = (int) ($row['depth'] ?? 0);

            // Innerhalb einer eingeklappten Gruppe: alles Tiefere überspringen.
            if ($foldDepth !== null) {
                if ($depth > $foldDepth) {
                    $foldedCount++;
                    continue;
                }
                $foldDepth = null;
            }

            $key       = $row['_key'];
            $isGroup   = ! empty($row['has_children']);
            $collapsed = $isGroup && $this->state->isRowCollapsed($key);

            if ($collapsed) {
                $collapsedCount++;
                $foldDepth = $depth;
            }

            if ($this->state->isRowHidden($key)) {
                $hiddenCount++;
                continue;
            }

            // Gruppen zeigen den Rollup (eigene + Nachfahren), Blätter ihre eigenen Zahlen. Eingeklappt
            // gilt dasselbe: die Gruppenzeile trägt die Summe ihrer nun unsichtbaren Kinder.
            $cells = $isGroup ? ($row['rollupCells'] ?? $row['cells']) : $row['cells'];
            $total = $isGroup ? ($row['rollupTotal'] ?? $row['rowTotal']) : $row['rowTotal'];

            if ($this->state->hideEmptyRows && abs((float) $total) < self::EPSILON) {
                $hiddenCount++;
                continue;
            }

            $visible[] = [
                'key'       => $key,
                'code'      => $row['code'],
                'name'      => $row['name'],
                'depth'     => $depth,
                // Einrückung in px, gedeckelt: die Baumtiefe ist fachlich unbegrenzt, die Spalte
                // nicht. Gefaltet wird weiter über die **echte** Tiefe — ein gedeckelter Wert
                // würde tiefe Zweige fälschlich als Geschwister behandeln.
                'indent'    => min($depth, self::MAX_INDENT_LEVELS) * self::INDENT_PX,
                'isGroup'   => $isGroup,
                'collapsed' => $collapsed,
                'cells'     => $cells,
                'total'     => $total,
            ];
        }

        return [
            'visible'        => $visible,
            'hiddenCount'    => $hiddenCount,
            'collapsedCount' => $collapsedCount,
            'foldedCount'    => $foldedCount,
        ];
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
