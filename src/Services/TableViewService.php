<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetTableView;
use Platform\AssetManager\Support\TableViewState;

/**
 * Lesen und Schreiben der gespeicherten Tabellen-Ansichten je (User × Team × Tabelle) — die
 * Persistenz hinter der Excel-artigen Tabellen-Anpassung (docs/adr/0020).
 *
 * Stateless (Modul-Konvention): IDs kommen herein, nichts wird aus Auth oder Session gezogen. Das
 * hält den Service in Jobs und Tests brauchbar und macht den Aufrufer für das Scoping verantwortlich
 * — sichtbar statt versteckt.
 *
 * Der Payload wird beim Lesen **und** beim Schreiben durch {@see TableViewState} geprüft: eine alte
 * Zeile mit inzwischen gelöschten Kostenarten oder ein manipulierter Browser-Wert kann die Ansicht
 * nicht sprengen. Fällt eine Ansicht auf den Werkszustand zurück, wird die Zeile gelöscht statt ein
 * leerer Payload gespeichert — so bleibt „hat der Nutzer etwas angepasst" eine Frage der Existenz.
 */
class TableViewService
{
    public function load(int $teamId, int $userId, string $key): TableViewState
    {
        $payload = AssetTableView::query()
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->where('table_key', $this->normalizeKey($key))
            ->value('payload');

        return TableViewState::fromArray(is_array($payload) ? $payload : null);
    }

    /** Speichert und liefert den **geprüften** Zustand zurück (das ist der, der ab jetzt gilt). */
    public function save(int $teamId, int $userId, string $key, TableViewState $state): TableViewState
    {
        $key    = $this->normalizeKey($key);
        $stored = TableViewState::fromArray($state->toArray());

        if (! $stored->isCustomized()) {
            return $this->reset($teamId, $userId, $key);
        }

        AssetTableView::updateOrCreate(
            ['user_id' => $userId, 'team_id' => $teamId, 'table_key' => $key],
            ['payload' => $stored->toArray()],
        );

        return $stored;
    }

    /** Zurück auf Werkszustand: Zeile weg, Default-Ansicht zurück. */
    public function reset(int $teamId, int $userId, string $key): TableViewState
    {
        AssetTableView::query()
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->where('table_key', $this->normalizeKey($key))
            ->delete();

        return TableViewState::fresh();
    }

    /**
     * Tabellen-Schlüssel auf das Spaltenformat begrenzen (64 Zeichen, konservatives Alphabet).
     * Die Schlüssel sind Konstanten im Code — das hier fängt Tippfehler, nicht Angriffe.
     */
    private function normalizeKey(string $key): string
    {
        return substr(preg_replace('/[^A-Za-z0-9:_.\-]/', '', $key) ?: 'default', 0, 64);
    }
}
