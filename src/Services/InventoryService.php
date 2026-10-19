<?php

namespace Platform\AssetManager\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Support\DeviceCostResolver;
use Platform\AssetManager\Support\InventoryRow;

/**
 * Stateless: führt die zwei getrennten Hardware-Quellen (`asset_items` + `asset_devices`) zu EINER
 * normalisierten, read-only Inventar-Liste zusammen. Bewusst KEIN DB-UNION: die Spalten sind
 * heterogen und die Geräte-Monatskosten existieren nicht als Spalte, sondern werden aus
 * Override/Modell-Default berechnet.
 *
 * Gefiltert wird in SQL (#977), je Quelle eine Abfrage — vorher wurden bei jedem Tastendruck beide
 * Tabellen samt Relationen vollständig geladen und in PHP gefiltert. Sortiert und geblättert wird
 * über die beiden gefilterten Mengen gemeinsam; dafür reichen schmale Schlüsselspalten, und erst die
 * Zeilen der angezeigten Seite werden mit Relationen und Kosten aufgelöst ({@see page()}). Nur die
 * Sortierung nach Monatskosten braucht die Kosten jeder gefilterten Zeile und lädt deshalb die ganze
 * (vorgefilterte) Menge — sie ist ohnehin Controlling-only.
 *
 * Rührt das doppelzählungsfreie Kostenmodell (CostAggregationService) NICHT an — nutzt nur Lese-Methoden.
 *
 * Filter-Array (alle Schlüssel optional):
 *   search      string   Wörter, jedes muss in Name/Hersteller/Modell/Seriennr./Zuordnung vorkommen
 *   type        string   '' | 'manual' | 'intune'
 *   assignment  string   '' | 'assigned' | 'unassigned'
 *   status      string   '' | Item-Status | Geräte-Lifecycle
 *   category    ?int     nur manuelle Assets → Geräte fallen raus
 *   costCenter  ?int     nur Geräte → manuelle Assets fallen raus
 */
class InventoryService
{
    /**
     * Eine Seite der gefilterten, sortierten Liste.
     *
     * @param  array<string, mixed>  $filters
     */
    public function page(
        int $teamId,
        ?int $tenantId,
        array $filters,
        string $sortField,
        string $direction,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        if ($sortField === 'monthlyCost') {
            return $this->paginate(
                $this->sort($this->filteredRows($teamId, $tenantId, $filters), $sortField, $direction),
                $perPage,
                $page,
            );
        }

        $page    = max(1, $page);
        $perPage = max(1, $perPage); // 0 aus der URL wäre eine Division durch null (#957)

        $keys  = $this->sortKeys($teamId, $tenantId, $filters, $sortField, $direction);
        $slice = $keys->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $this->hydrate($teamId, $tenantId, $slice),
            $keys->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * Alle gefilterten Zeilen vollständig (Relationen + Kosten) — für den CSV-Export und die
     * Sortierung nach Monatskosten. Unsortiert: Items vor Geräten, je Quelle in DB-Reihenfolge.
     *
     * Geräte-Kosten laufen über EINEN vorgeladenen {@see DeviceCostResolver} statt über
     * AssetDevice::resolvedMonthlyCost() je Zeile (das baut je Gerät einen eigenen Resolver).
     *
     * @param  array<string, mixed>  $filters
     */
    public function filteredRows(int $teamId, ?int $tenantId, array $filters): Collection
    {
        $rows = collect();

        if ($items = $this->itemQuery($teamId, $tenantId, $filters)) {
            $rows = $rows->concat(
                $items->with('assignee', 'category')->get()->map(fn (AssetItem $item) => InventoryRow::fromItem($item)),
            );
        }

        if ($devices = $this->deviceQuery($teamId, $tenantId, $filters)) {
            $devices  = $devices->with('costCenter')->get();
            $resolver = $devices->isNotEmpty() ? DeviceCostResolver::for($teamId, $tenantId) : null;

            $rows = $rows->concat(
                $devices->map(fn (AssetDevice $d) => InventoryRow::fromDevice($d, $resolver->monthlyCost($d))),
            );
        }

        return $rows->values();
    }

    /**
     * IDs der gefilterten **manuellen** Assets — Grundlage von „alle gefilterten auswählen".
     * Geräte sind nicht auswählbar (Sammelaktionen für Geräte liegen auf der Geräteliste).
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    public function filteredItemIds(int $teamId, ?int $tenantId, array $filters): array
    {
        $query = $this->itemQuery($teamId, $tenantId, $filters);

        return $query ? $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    }

    /**
     * Gefilterte Abfrage der manuellen Assets — `null`, wenn ein Filter die Quelle ganz ausschließt
     * (Typ „Intune", Kostenstellen-Filter).
     *
     * @param  array<string, mixed>  $filters
     */
    public function itemQuery(int $teamId, ?int $tenantId, array $filters): ?Builder
    {
        $f = $this->normalizeFilters($filters);

        if ($f['type'] === 'intune' || $f['costCenter'] !== null) {
            return null;
        }

        $query = AssetItem::query()->where('asset_items.team_id', $teamId)->forTenant($tenantId);

        if ($f['assignment'] === 'assigned') {
            $query->whereHas('assignee');
        } elseif ($f['assignment'] === 'unassigned') {
            $query->whereDoesntHave('assignee');
        }

        if ($f['status'] !== '') {
            $query->where('status', $f['status']);
        }

        if ($f['category'] !== null) {
            $query->where('category_id', $f['category']);
        }

        foreach ($f['words'] as $word) {
            $like = '%' . $word . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhereHas('assignee', fn (Builder $h) => $h
                        ->where('display_name', 'like', $like)
                        ->orWhere('user_principal_name', 'like', $like));
            });
        }

        return $query;
    }

    /**
     * Gefilterte Abfrage der Intune-Geräte — `null`, wenn ein Filter die Quelle ganz ausschließt
     * (Typ „Manuell", Kategorie-Filter).
     *
     * @param  array<string, mixed>  $filters
     */
    public function deviceQuery(int $teamId, ?int $tenantId, array $filters): ?Builder
    {
        $f = $this->normalizeFilters($filters);

        if ($f['type'] === 'manual' || $f['category'] !== null) {
            return null;
        }

        $query = AssetDevice::query()->where('asset_devices.team_id', $teamId)->forTenant($tenantId);

        // „Zugeordnet" heißt wie in der Zeile: Anzeigename ODER UPN gefüllt.
        $assigned = fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->whereNotNull('user_display_name')->where('user_display_name', '!=', ''))
            ->orWhere(fn (Builder $w) => $w->whereNotNull('user_principal_name')->where('user_principal_name', '!=', ''));

        if ($f['assignment'] === 'assigned') {
            $query->where($assigned);
        } elseif ($f['assignment'] === 'unassigned') {
            $query->whereNot($assigned);
        }

        if ($f['status'] !== '') {
            $query->where('lifecycle_status', $f['status']);
        }

        if ($f['costCenter'] !== null) {
            $query->where('cost_center_id', $f['costCenter']);
        }

        foreach ($f['words'] as $word) {
            $like = '%' . $word . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('device_name', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('user_display_name', 'like', $like)
                    ->orWhere('user_principal_name', 'like', $like);
            });
        }

        return $query;
    }

    /** Sortiert über abgeleitete, typ-übergreifende Felder (nicht über DB-Spalten einer Quelle). */
    public function sort(Collection $rows, string $field, string $direction): Collection
    {
        return $rows->sortBy(
            fn (InventoryRow $row) => $field === 'monthlyCost'
                ? $row->monthlyCost
                : $this->sortValue($field, $row->type, $row->name, $row->assignedTo, $row->statusSortKey),
            SORT_REGULAR,
            $direction === 'desc',
        )->values();
    }

    /** Manuelle Pagination auf der bereits gefilterten + sortierten Collection. */
    public function paginate(Collection $rows, int $perPage, int $page): LengthAwarePaginator
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage); // 0 aus der URL wäre eine Division durch null (#957)

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /** Zähler für die Stat-Karten (unabhängig von der gefilterten Sicht; tenant-rein, wenn $tenantId gesetzt). */
    public function counts(int $teamId, ?int $tenantId = null): array
    {
        $manual = AssetItem::where('team_id', $teamId)->forTenant($tenantId)->count();
        $intune = AssetDevice::where('team_id', $teamId)->forTenant($tenantId)->count();

        $assigned = AssetItem::where('team_id', $teamId)->forTenant($tenantId)->whereNotNull('assignee_id')->count()
            + AssetDevice::where('team_id', $teamId)->forTenant($tenantId)
                ->whereNotNull('user_principal_name')
                ->where('user_principal_name', '!=', '')
                ->count();

        return [
            'total'    => $manual + $intune,
            'manual'   => $manual,
            'intune'   => $intune,
            'assigned' => $assigned,
        ];
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Sortierschlüssel aller gefilterten Zeilen aus schmalen Spalten — sortiert wie {@see sort()},
     * damit Seite und Export dieselbe Reihenfolge haben.
     *
     * @return Collection<int, array{type: string, id: int}>
     */
    protected function sortKeys(int $teamId, ?int $tenantId, array $filters, string $field, string $direction): Collection
    {
        $keys = collect();

        if ($items = $this->itemQuery($teamId, $tenantId, $filters)) {
            $items = $items->get(['asset_items.id', 'asset_items.name', 'asset_items.status', 'asset_items.assignee_id']);

            // Anzeigename der Träger in EINER Abfrage — über das Model, damit dieselben Scopes greifen
            // wie bei `$item->assignee` in der Zeile.
            $names = $field === 'assignedTo'
                ? AssetHolder::whereIn('id', $items->pluck('assignee_id')->filter()->unique()->values())
                    ->get(['id', 'display_name', 'user_principal_name'])
                    ->mapWithKeys(fn (AssetHolder $h) => [(int) $h->id => $h->name])
                : collect();

            foreach ($items as $i) {
                $keys->push([
                    'type' => 'manual',
                    'id'   => (int) $i->id,
                    'sort' => $this->sortValue(
                        $field, 'manual', $i->name ?: '—',
                        $i->assignee_id ? ($names[(int) $i->assignee_id] ?? null) : null,
                        (string) ($i->status ?? ''),
                    ),
                ]);
            }
        }

        if ($devices = $this->deviceQuery($teamId, $tenantId, $filters)) {
            $devices = $devices->get([
                'asset_devices.id', 'asset_devices.device_name', 'asset_devices.lifecycle_status',
                'asset_devices.user_display_name', 'asset_devices.user_principal_name',
            ]);

            foreach ($devices as $d) {
                $keys->push([
                    'type' => 'intune',
                    'id'   => (int) $d->id,
                    'sort' => $this->sortValue(
                        $field, 'intune', $d->device_name ?: '—',
                        $d->user_display_name ?: $d->user_principal_name,
                        (string) ($d->lifecycle_status ?? ''),
                    ),
                ]);
            }
        }

        return $keys->sortBy('sort', SORT_REGULAR, $direction === 'desc')->values();
    }

    /** Die Zeilen einer Seite mit Relationen und Kosten — in der Reihenfolge der Schlüssel. */
    protected function hydrate(int $teamId, ?int $tenantId, Collection $slice): Collection
    {
        $itemIds   = $slice->where('type', 'manual')->pluck('id')->all();
        $deviceIds = $slice->where('type', 'intune')->pluck('id')->all();

        $items = $itemIds === [] ? collect() : AssetItem::with('assignee', 'category')
            ->where('team_id', $teamId)->forTenant($tenantId)
            ->whereIn('id', $itemIds)->get()->keyBy('id');

        $devices = $deviceIds === [] ? collect() : AssetDevice::with('costCenter')
            ->where('team_id', $teamId)->forTenant($tenantId)
            ->whereIn('id', $deviceIds)->get()->keyBy('id');

        $resolver = $devices->isNotEmpty() ? DeviceCostResolver::for($teamId, $tenantId) : null;

        return $slice->map(function (array $key) use ($items, $devices, $resolver) {
            if ($key['type'] === 'manual') {
                $item = $items[$key['id']] ?? null;

                return $item ? InventoryRow::fromItem($item) : null;
            }

            $device = $devices[$key['id']] ?? null;

            return $device ? InventoryRow::fromDevice($device, $resolver->monthlyCost($device)) : null;
        })->filter()->values();
    }

    /** Ein Sortierwert — dieselbe Regel für schmale Schlüssel und volle Zeilen. */
    protected function sortValue(string $field, string $type, string $name, ?string $assignedTo, string $status): string
    {
        return match ($field) {
            'assignedTo' => mb_strtolower($assignedTo ?? ''),
            'status'     => $status,
            'type'       => $type,
            default      => mb_strtolower($name),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type: string, assignment: string, status: string, category: ?int, costCenter: ?int, words: list<string>}
     */
    protected function normalizeFilters(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return [
            'type'       => (string) ($filters['type'] ?? ''),
            'assignment' => (string) ($filters['assignment'] ?? ''),
            'status'     => (string) ($filters['status'] ?? ''),
            'category'   => isset($filters['category']) && $filters['category'] !== '' ? (int) $filters['category'] : null,
            'costCenter' => isset($filters['costCenter']) && $filters['costCenter'] !== '' ? (int) $filters['costCenter'] : null,
            // Wortweise: „dell lat" findet den Dell Latitude, auch wenn die Wörter in verschiedenen
            // Spalten stehen (vorher: Teilstring über die zusammengesetzte Zeile).
            'words'      => $search === '' ? [] : array_values(array_filter(preg_split('/\s+/u', $search) ?: [], 'strlen')),
        ];
    }
}
