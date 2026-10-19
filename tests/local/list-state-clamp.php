<?php

/**
 * Listen-Zustand aus URL/Client (#957): {@see \Platform\AssetManager\Concerns\ClampsListState}.
 *
 * Aufruf: php tests/local/list-state-clamp.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Concerns\ClampsListState;

$list = new class {
    use ClampsListState;

    public int $perPage = 25;
    public string $sortField = 'device_name';
    public string $sortDirection = 'asc';

    protected function listPerPageOptions(): array { return [15, 25, 50, 100]; }
    protected function listSortable(): array { return ['device_name', 'serial_number']; }

    public function clamp(): void { $this->clampListState(); }
};

$list->perPage = 0;          $list->clamp(); check('perPage=0 → 25', 25, $list->perPage);
$list->perPage = -5;         $list->clamp(); check('perPage negativ → 25', 25, $list->perPage);
$list->perPage = 100000;     $list->clamp(); check('perPage riesig → 25', 25, $list->perPage);
$list->perPage = 50;         $list->clamp(); check('erlaubter Wert bleibt', 50, $list->perPage);
$list->sortField = 'password'; $list->clamp(); check('unbekannte Spalte → Rückfall', 'device_name', $list->sortField);
$list->sortField = 'serial_number'; $list->clamp(); check('erlaubte Spalte bleibt', 'serial_number', $list->sortField);
$list->sortDirection = 'desc; drop'; $list->clamp(); check('Richtung nur asc/desc', 'asc', $list->sortDirection);

check_summary();
