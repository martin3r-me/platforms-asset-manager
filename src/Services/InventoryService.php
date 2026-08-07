<?php

namespace Platform\AssetManager\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Support\DeviceCostResolver;
use Platform\AssetManager\Support\InventoryRow;

/**
 * Stateless: führt die zwei getrennten Hardware-Quellen (`asset_items` + `asset_devices`) zu EINER
 * normalisierten, read-only Inventar-Liste zusammen (In-Memory-Merge). Bewusst KEIN DB-UNION:
 * die Spalten sind heterogen und die Geräte-Monatskosten existieren nicht als Spalte, sondern werden
 * aus Override/Modell-Default berechnet.
 *
 * Rührt das doppelzählungsfreie Kostenmodell (CostAggregationService) NICHT an — nutzt nur Lese-Methoden.
 */
class InventoryService
{
    /**
     * Alle Inventar-Zeilen eines Teams, N+1-frei.
     *
     * Geräte-Kosten werden NICHT über AssetDevice::resolvedMonthlyCost() je Zeile aufgelöst (das baut
     * je Gerät einen eigenen Resolver), sondern über EINEN vorgeladenen {@see DeviceCostResolver} —
     * dieselbe Logik, aber 2 Queries statt N. Der Resolver kennt die tenant-eigenen Modell-Kosten
     * (docs/adr/0016).
     */
    public function rows(int $teamId, ?int $tenantId = null): Collection
    {
        $items = AssetItem::with('assignee', 'category')
            ->where('team_id', $teamId)
            ->forTenant($tenantId)
            ->get()
            ->map(fn (AssetItem $item) => InventoryRow::fromItem($item));

        // Katalog + tenant-eigene Modell-Kosten EINMAL vorladen (statt je Gerät).
        $resolver = DeviceCostResolver::for($teamId, $tenantId);

        $devices = AssetDevice::with('costCenter')
            ->where('team_id', $teamId)
            ->forTenant($tenantId)
            ->get()
            ->map(fn (AssetDevice $device) => InventoryRow::fromDevice($device, $resolver->monthlyCost($device)));

        return $items->concat($devices)->values();
    }

    /**
     * Filtert die gemergte Collection. Suche/Typ/Zuweisung/Status wirken über BEIDE Quellen
     * (`statusSortKey` trägt `status` bzw. `lifecycle_status`). Die typ-spezifischen Facetten
     * schließen die jeweils andere Welt implizit aus: ein Kategorie-Filter lässt nur manuelle
     * Zeilen übrig, ein Kostenstellen-Filter nur Geräte.
     */
    public function filter(
        Collection $rows,
        string $search,
        string $type,
        string $assignment,
        string $status = '',
        ?int $categoryId = null,
        ?int $costCenterId = null,
    ): Collection {
        $needle = mb_strtolower(trim($search));

        return $rows->filter(function (InventoryRow $row) use ($needle, $type, $assignment, $status, $categoryId, $costCenterId) {
            if ($type !== '' && $row->type !== $type) {
                return false;
            }
            if ($assignment === 'assigned' && ! filled($row->assignedTo)) {
                return false;
            }
            if ($assignment === 'unassigned' && filled($row->assignedTo)) {
                return false;
            }
            if ($status !== '' && $row->statusSortKey !== $status) {
                return false;
            }
            // Kategorie ist eine reine Item-Facette → Geräte fallen implizit raus.
            if ($categoryId !== null && $row->categoryId !== $categoryId) {
                return false;
            }
            // Kostenstelle ist eine reine Geräte-Facette → manuelle Assets fallen implizit raus.
            if ($costCenterId !== null && $row->costCenterId !== $costCenterId) {
                return false;
            }
            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row->name, $row->manufacturer, $row->model, $row->serialNumber, $row->assignedTo,
                ])));
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /** Sortiert über abgeleitete, typ-übergreifende Felder (nicht über DB-Spalten einer Quelle). */
    public function sort(Collection $rows, string $field, string $direction): Collection
    {
        $descending = $direction === 'desc';

        return $rows->sortBy(function (InventoryRow $row) use ($field) {
            return match ($field) {
                'assignedTo'  => mb_strtolower($row->assignedTo ?? ''),
                'status'      => $row->statusSortKey,
                'monthlyCost' => $row->monthlyCost,
                'type'        => $row->type,
                default       => mb_strtolower($row->name),
            };
        }, SORT_REGULAR, $descending)->values();
    }

    /** Manuelle Pagination auf der bereits gefilterten + sortierten Collection. */
    public function paginate(Collection $rows, int $perPage, int $page): LengthAwarePaginator
    {
        $page = max(1, $page);

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
}
