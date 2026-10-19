<?php

namespace Platform\AssetManager\Concerns;

use Platform\AssetManager\Support\TableViewState;

/**
 * Merkt sich Sortierung und Seitengröße einer Liste pro (User × Team) — über denselben
 * {@see \Platform\AssetManager\Services\TableViewService} wie die Spalten-Ansicht (ADR 0020, #979).
 * Vorher lag so etwas in der Session und war nach dem Logout bzw. am zweiten Gerät weg.
 *
 * Setzt voraus: {@see CustomizesTableView} (Service, Team, User, `tableViewKey()`) und
 * {@see ClampsListState} (`listSortable()`, `listPerPageOptions()` — dieselben Whitelists gelten
 * beim Speichern **und** beim Laden).
 *
 * **Speicherform.** {@see TableViewState} kennt (noch) keine Felder für Sortierung und Seitengröße und
 * verwirft Unbekanntes. Der Listen-Zustand liegt deshalb unter einem eigenen Schlüssel
 * `<tableViewKey>.list` als geprüfte Token-Liste in `order`: `sort:<feld>`, `dir:desc`, `per:<n>`.
 * Gespeichert wird nur, was vom Default abweicht — „angepasst" bleibt eine Frage der Existenz, und
 * eine Rückkehr zum Default löscht die Zeile. Die Token passen in das Schlüsselmuster von
 * TableViewState und werden beim Lesen zusätzlich gegen die Whitelists der Komponente geprüft: ein
 * alter oder manipulierter Wert (`sort:password`, `per:0`) wird ignoriert, nie angewendet.
 *
 * **Deep-Links gewinnen.** Steht ein Wert im Query-String, bleibt er; die gespeicherte Ansicht füllt
 * nur, was die URL offen lässt.
 */
trait RemembersListState
{
    /** Gespeicherten Listen-Zustand übernehmen — aus `mount()` aufrufen. */
    protected function restoreListState(): void
    {
        $tokens = $this->tableViewService()
            ->load($this->teamId(), $this->currentUserId(), $this->listStateKey())
            ->order;

        $stored = self::decodeListState($tokens, $this->listSortable(), $this->listPerPageOptions());

        foreach ($stored as $property => $value) {
            if (! property_exists($this, $property) || $this->listStateInUrl($property)) {
                continue;
            }

            $this->{$property} = $value;
        }
    }

    /** Aktuellen Listen-Zustand speichern (nach Sortier-Klick oder Seitengrößen-Wechsel). */
    protected function rememberListState(): void
    {
        $tokens = self::encodeListState(
            [
                'sortField'     => $this->sortField ?? null,
                'sortDirection' => $this->sortDirection ?? null,
                'perPage'       => $this->perPage ?? null,
            ],
            $this->listStateDefaults(),
            $this->listSortable(),
            $this->listPerPageOptions(),
        );

        $this->tableViewService()->save(
            $this->teamId(),
            $this->currentUserId(),
            $this->listStateKey(),
            TableViewState::fromArray(['order' => $tokens]),
        );
    }

    /** Livewire-Trait-Hook: `wire:model` auf die Seitengröße speichert mit. */
    public function updatedRemembersListState(string $name = '', mixed $value = null): void
    {
        if (in_array($name, ['perPage', 'sortField', 'sortDirection'], true)) {
            $this->rememberListState();
        }
    }

    protected function listStateKey(): string
    {
        return $this->tableViewKey() . '.list';
    }

    /** Steht der Wert im Query-String der Seite (Deep-Link)? Beachtet ein `as`-Alias im `$queryString`. */
    protected function listStateInUrl(string $property): bool
    {
        $config = property_exists($this, 'queryString') && is_array($this->queryString)
            ? ($this->queryString[$property] ?? null)
            : null;
        $name = is_array($config) && isset($config['as']) ? (string) $config['as'] : $property;

        return request()->query->has($name);
    }

    /**
     * Defaults = die Property-Defaults der Klasse (dieselben, die `$queryString` als `except` nennt).
     *
     * @return array{sortField: ?string, sortDirection: string, perPage: ?int}
     */
    protected function listStateDefaults(): array
    {
        $defaults = (new \ReflectionClass($this))->getDefaultProperties();

        return [
            'sortField'     => $defaults['sortField'] ?? ($this->listSortable()[0] ?? null),
            'sortDirection' => $defaults['sortDirection'] ?? 'asc',
            'perPage'       => isset($defaults['perPage']) ? (int) $defaults['perPage'] : null,
        ];
    }

    /**
     * Zustand → Token-Liste. Nur erlaubte und vom Default abweichende Werte.
     *
     * @param  array{sortField: mixed, sortDirection: mixed, perPage: mixed}  $current
     * @param  array{sortField: mixed, sortDirection: mixed, perPage: mixed}  $defaults
     * @param  list<string>  $sortable
     * @param  list<int>     $perPageOptions
     * @return list<string>
     */
    protected static function encodeListState(array $current, array $defaults, array $sortable, array $perPageOptions): array
    {
        $tokens = [];

        $field = $current['sortField'] ?? null;
        if (is_string($field) && in_array($field, $sortable, true) && $field !== ($defaults['sortField'] ?? null)) {
            $tokens[] = 'sort:' . $field;
        }

        $direction = $current['sortDirection'] ?? null;
        if (in_array($direction, ['asc', 'desc'], true) && $direction !== ($defaults['sortDirection'] ?? 'asc')) {
            $tokens[] = 'dir:' . $direction;
        }

        $perPage = $current['perPage'] ?? null;
        if (is_numeric($perPage) && in_array((int) $perPage, $perPageOptions, true)
            && (int) $perPage !== (isset($defaults['perPage']) ? (int) $defaults['perPage'] : null)) {
            $tokens[] = 'per:' . (int) $perPage;
        }

        return array_values(array_filter($tokens, fn (string $t) => (bool) preg_match(TableViewState::KEY_PATTERN, $t)));
    }

    /**
     * Token-Liste → Property-Werte. Prüft gegen die Whitelists; Unbekanntes und Unerlaubtes fällt weg.
     *
     * @param  array<int,string>  $tokens
     * @param  list<string>       $sortable
     * @param  list<int>          $perPageOptions
     * @return array<string, string|int>
     */
    protected static function decodeListState(array $tokens, array $sortable, array $perPageOptions): array
    {
        $out = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || ! str_contains($token, ':')) {
                continue;
            }

            [$kind, $value] = explode(':', $token, 2);

            if ($kind === 'sort' && in_array($value, $sortable, true)) {
                $out['sortField'] = $value;
            } elseif ($kind === 'dir' && in_array($value, ['asc', 'desc'], true)) {
                $out['sortDirection'] = $value;
            } elseif ($kind === 'per' && ctype_digit($value) && in_array((int) $value, $perPageOptions, true)) {
                $out['perPage'] = (int) $value;
            }
        }

        return $out;
    }
}
