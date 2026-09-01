<?php

namespace Platform\AssetManager\Support;

/**
 * Zustand einer anpassbaren Tabellen-Ansicht: Spaltenbreiten, Spaltenreihenfolge, aus-/eingeblendete
 * Spalten und Zeilen, eingeklappte Gruppen, Schnellfilter (docs/adr/0020).
 *
 * Bewusst **framework-frei und unveränderlich**: keine Eloquent-, Auth- oder Request-Kenntnis. Jede
 * Änderung liefert eine neue Instanz. Damit ist der Zustand ohne Datenbank prüfbar
 * (`tests/local/table-view-state.php`) und dieselbe Klasse trägt die Ansicht für UI **und** Export.
 *
 * **Alles hier ist Anzeige-Ergonomie, nie eine Zugriffsgrenze.** Der Zustand kommt aus dem Browser
 * und darf nur Reihenfolge und Sichtbarkeit beeinflussen — niemals, welche Daten geladen werden. Er
 * verändert auch keine Beträge: ausgeblendete Spalten und Zeilen zählen weiter in die Summen (wie in
 * Excel, wo SUM über versteckte Zellen summiert).
 *
 * Deshalb prüft {@see self::fromArray()} jeden Wert und verwirft alles Unbekannte: Schlüssel müssen
 * dem engen Muster {@see self::KEY_PATTERN} folgen, Breiten werden auf {@see self::MIN_WIDTH} …
 * {@see self::MAX_WIDTH} geklemmt, Listen werden dedupliziert und gedeckelt. Ein manipulierter
 * Payload kann so nur die eigene Ansicht verunstalten.
 */
final class TableViewState
{
    /** Kleinste Spaltenbreite in px — darunter ist auch eine Zahl nicht mehr lesbar. */
    public const MIN_WIDTH = 56;

    /** Größte Spaltenbreite in px. */
    public const MAX_WIDTH = 720;

    /** Deckel für jede gespeicherte Liste (Reihenfolge, ausgeblendet, eingeklappt). */
    public const MAX_ENTRIES = 500;

    /** Erlaubte Spalten-/Zeilen-Schlüssel: Ziffern, Buchstaben und `:_.-` (z. B. `12`, `cc:34`). */
    public const KEY_PATTERN = '/^[A-Za-z0-9:_.\-]{1,64}$/';

    /**
     * @param  array<string,int>  $widths         Spalten-Schlüssel → Breite in px
     * @param  array<int,string>  $order          Spalten-Schlüssel in Anzeige-Reihenfolge (darf unvollständig sein)
     * @param  array<int,string>  $hiddenColumns  ausgeblendete Spalten
     * @param  array<int,string>  $hiddenRows     ausgeblendete Zeilen (Zeilen-Schlüssel)
     * @param  array<int,string>  $collapsedRows  eingeklappte Gruppen (Zeilen-Schlüssel)
     */
    public function __construct(
        public readonly array $widths = [],
        public readonly array $order = [],
        public readonly array $hiddenColumns = [],
        public readonly array $hiddenRows = [],
        public readonly array $collapsedRows = [],
        public readonly bool $hideEmptyColumns = false,
        public readonly bool $hideEmptyRows = false,
    ) {
    }

    /** Leere Ansicht (Werkszustand). */
    public static function fresh(): self
    {
        return new self();
    }

    /**
     * Aus einem gespeicherten oder vom Browser gelieferten Payload — prüfend, nicht vertrauend.
     * Unbekannte Schlüssel, faule Typen und Ausreißer fallen weg statt die Ansicht zu sprengen.
     */
    public static function fromArray(?array $raw): self
    {
        $raw ??= [];

        return new self(
            widths: self::sanitizeWidths($raw['widths'] ?? []),
            order: self::sanitizeKeyList($raw['order'] ?? []),
            hiddenColumns: self::sanitizeKeyList($raw['hidden_columns'] ?? []),
            hiddenRows: self::sanitizeKeyList($raw['hidden_rows'] ?? []),
            collapsedRows: self::sanitizeKeyList($raw['collapsed_rows'] ?? []),
            hideEmptyColumns: (bool) ($raw['hide_empty_columns'] ?? false),
            hideEmptyRows: (bool) ($raw['hide_empty_rows'] ?? false),
        );
    }

    /** @return array<string,mixed> Speicher-/Transport-Form (Schlüssel = die von fromArray() erwarteten). */
    public function toArray(): array
    {
        return [
            'widths'             => $this->widths,
            'order'              => $this->order,
            'hidden_columns'     => $this->hiddenColumns,
            'hidden_rows'        => $this->hiddenRows,
            'collapsed_rows'     => $this->collapsedRows,
            'hide_empty_columns' => $this->hideEmptyColumns,
            'hide_empty_rows'    => $this->hideEmptyRows,
        ];
    }

    /** Weicht die Ansicht überhaupt vom Werkszustand ab? Steuert den Zurücksetzen-Knopf. */
    public function isCustomized(): bool
    {
        return $this->widths !== []
            || $this->order !== []
            || $this->hiddenColumns !== []
            || $this->hiddenRows !== []
            || $this->collapsedRows !== []
            || $this->hideEmptyColumns
            || $this->hideEmptyRows;
    }

    /** Breite einer Spalte, sonst der übergebene Default. */
    public function width(string $column, int $default): int
    {
        return $this->widths[$column] ?? $default;
    }

    public function isColumnHidden(string $column): bool
    {
        return in_array($column, $this->hiddenColumns, true);
    }

    public function isRowHidden(string $row): bool
    {
        return in_array($row, $this->hiddenRows, true);
    }

    public function isRowCollapsed(string $row): bool
    {
        return in_array($row, $this->collapsedRows, true);
    }

    /**
     * Die verfügbaren Spalten in Anzeige-Reihenfolge.
     *
     * Die gespeicherte Reihenfolge ist bewusst nur ein Wunsch, keine Wahrheit: Kostenarten kommen und
     * gehen. Zuerst kommen die gespeicherten Schlüssel, die es noch gibt, dahinter jede weitere Spalte
     * in ihrer Ursprungsreihenfolge — eine neu angelegte Kostenart verschwindet so nie still, nur weil
     * sie in einer älteren gespeicherten Ansicht fehlt.
     *
     * @param  array<int,string>  $available
     * @return array<int,string>
     */
    public function orderColumns(array $available): array
    {
        $pending = [];
        foreach ($available as $key) {
            $pending[(string) $key] = true;
        }

        $ordered = [];
        foreach ($this->order as $key) {
            if ($pending[$key] ?? false) {
                $ordered[]      = $key;
                $pending[$key]  = false;
            }
        }

        foreach ($available as $key) {
            if ($pending[(string) $key] ?? false) {
                $ordered[] = (string) $key;
            }
        }

        return $ordered;
    }

    // --- Mutatoren (liefern jeweils eine neue Instanz) -----------------------------------------

    /** Breiten setzen/überschreiben (Teilmenge erlaubt, der Rest bleibt). */
    public function withWidths(array $widths): self
    {
        return $this->copy(widths: self::sanitizeWidths($widths) + $this->widths);
    }

    /** Alle Breiten zurück auf die Default-Breiten der Tabelle. */
    public function withDefaultWidths(): self
    {
        return $this->copy(widths: []);
    }

    /**
     * Eine Spalte vor `$target` einsortieren (`$target === null` → ganz nach hinten). `$available` ist
     * die vollständige Spaltenliste, damit die gespeicherte Reihenfolge danach lückenlos ist.
     *
     * @param  array<int,string>  $available
     */
    public function withColumnMovedBefore(array $available, string $column, ?string $target): self
    {
        $ordered = $this->orderColumns($available);

        if (! in_array($column, $ordered, true) || $column === $target) {
            return $this;
        }

        $ordered = array_values(array_filter($ordered, fn ($key) => $key !== $column));
        $at      = $target === null ? false : array_search($target, $ordered, true);

        if ($at === false) {
            $ordered[] = $column;
        } else {
            array_splice($ordered, (int) $at, 0, [$column]);
        }

        return $this->copy(order: $ordered);
    }

    public function withColumnToggled(string $column): self
    {
        if (! preg_match(self::KEY_PATTERN, $column)) {
            return $this;
        }

        return $this->copy(hiddenColumns: self::toggleIn($this->hiddenColumns, $column));
    }

    /** Alle Spalten wieder sichtbar — inklusive des Schnellfilters, der sie versteckt hat. */
    public function withAllColumnsVisible(): self
    {
        return $this->copy(hiddenColumns: [], hideEmptyColumns: false);
    }

    public function withRowHidden(string $row): self
    {
        if (! preg_match(self::KEY_PATTERN, $row) || $this->isRowHidden($row)) {
            return $this;
        }

        return $this->copy(hiddenRows: self::capped([...$this->hiddenRows, $row]));
    }

    /** Alle Zeilen wieder sichtbar: ausgeblendete, eingeklappte Gruppen und der Nullzeilen-Filter. */
    public function withAllRowsVisible(): self
    {
        return $this->copy(hiddenRows: [], collapsedRows: [], hideEmptyRows: false);
    }

    public function withRowGroupToggled(string $row): self
    {
        if (! preg_match(self::KEY_PATTERN, $row)) {
            return $this;
        }

        return $this->copy(collapsedRows: self::toggleIn($this->collapsedRows, $row));
    }

    /** Schnellfilter umschalten: `hide_empty_columns` oder `hide_empty_rows`. */
    public function withFlagToggled(string $flag): self
    {
        return match ($flag) {
            'hide_empty_columns' => $this->copy(hideEmptyColumns: ! $this->hideEmptyColumns),
            'hide_empty_rows'    => $this->copy(hideEmptyRows: ! $this->hideEmptyRows),
            default              => $this,
        };
    }

    // --- intern -------------------------------------------------------------------------------

    /** Named-Argument-Kopie: alles Nicht-Genannte bleibt, wie es ist. */
    private function copy(
        ?array $widths = null,
        ?array $order = null,
        ?array $hiddenColumns = null,
        ?array $hiddenRows = null,
        ?array $collapsedRows = null,
        ?bool $hideEmptyColumns = null,
        ?bool $hideEmptyRows = null,
    ): self {
        return new self(
            widths: $widths ?? $this->widths,
            order: $order ?? $this->order,
            hiddenColumns: $hiddenColumns ?? $this->hiddenColumns,
            hiddenRows: $hiddenRows ?? $this->hiddenRows,
            collapsedRows: $collapsedRows ?? $this->collapsedRows,
            hideEmptyColumns: $hideEmptyColumns ?? $this->hideEmptyColumns,
            hideEmptyRows: $hideEmptyRows ?? $this->hideEmptyRows,
        );
    }

    /** @return array<string,int> */
    private static function sanitizeWidths(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;

            if (! preg_match(self::KEY_PATTERN, $key) || ! is_numeric($value)) {
                continue;
            }

            $out[$key] = max(self::MIN_WIDTH, min(self::MAX_WIDTH, (int) round((float) $value)));

            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private static function sanitizeKeyList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value) || ! preg_match(self::KEY_PATTERN, $value) || in_array($value, $out, true)) {
                continue;
            }

            $out[] = $value;
        }

        return self::capped($out);
    }

    /** @return array<int,string> */
    private static function capped(array $list): array
    {
        return array_values(array_slice(array_values(array_unique($list)), 0, self::MAX_ENTRIES));
    }

    /** @return array<int,string> */
    private static function toggleIn(array $list, string $value): array
    {
        return in_array($value, $list, true)
            ? array_values(array_filter($list, fn ($key) => $key !== $value))
            : self::capped([...$list, $value]);
    }
}
