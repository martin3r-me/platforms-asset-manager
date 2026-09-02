<?php

namespace Platform\AssetManager\Livewire\Holders;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\HolderTypeService;
use Platform\AssetManager\Services\TenantContext;

class Index extends Component
{
    use WithPagination;
    use ResolvesCurrentTeam;

    /**
     * Deckel für „alle gefilterten auswählen". Eine Auswahl über tausende Träger wäre weder im
     * Livewire-Payload noch als Schreibschleife eine gute Idee — und dieser Bildschirm ist genau der,
     * auf dem man „alle Träger" + „unzugeordnet" kombiniert.
     */
    protected const BULK_MAX = 500;

    public string $preset           = 'active'; // all|active|with_license|with_device|with_asset|unassigned|inactive
    public string $search           = '';
    public string $filterDept       = '';
    public string $filterSku        = '';
    public string $filterSource     = '';
    public string $filterCostCenter = '';
    /**
     * Träger-Typ-Filter (ADR 0017). Default `person`: Funktionskonten, Admin- und Service-Accounts
     * sind echte Träger mit echten Lizenzen, aber keine Personen — in der Personen-Liste stören sie
     * die Übersicht. Sie werden gelabelt, nicht weggeworfen: '' zeigt alle, 'non_person' nur sie,
     * und die Kosten-/Lizenzauswertungen zählen sie unabhängig von diesem Filter mit.
     */
    public string $filterHolderType = 'person';
    public bool   $filterHasLicense = false;
    public bool   $filterHasDevice  = false;
    public bool   $filterHasAsset   = false;
    public int    $perPage          = 25;
    public string $sortField        = 'display_name';
    public string $sortDirection    = 'asc';

    /**
     * Mehrfachauswahl für die Typ-Sammelaktion.
     *
     * IDs als **Strings**: `wire:model` vergleicht Checkbox-`value` als String, ein Array aus Ints
     * würde die Häkchen nicht wiederfinden (Muster aus CostLines/Devices). Bewusst **nicht** im
     * `$queryString` — 500 IDs machen die URL unbrauchbar.
     *
     * @var list<string>
     */
    public array   $selected     = [];
    public bool    $selectPage   = false;
    public string  $bulkType     = '';
    public bool    $showBulkType = false;
    public ?array  $bulkPreview  = null;
    public ?string $flash        = null;

    /**
     * Request-lokale Memoisierung der drei Zugehörigkeits-Listen. `protected` und damit nicht Teil
     * des Livewire-State. Ohne sie liefen `render()`, `applyPreset()` und der Auswahl-Pfad dieselben
     * drei tabellenweiten `distinct()`-Abfragen je einmal.
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    protected array $lookupMemo = [];

    protected $queryString = [
        'preset'           => ['except' => 'active'],
        'search'           => ['except' => ''],
        'filterDept'       => ['except' => ''],
        'filterSku'        => ['except' => ''],
        'filterSource'     => ['except' => ''],
        'filterCostCenter' => ['except' => ''],
        'filterHolderType' => ['except' => 'person'],
        'filterHasLicense' => ['except' => false],
        'filterHasDevice'  => ['except' => false],
        'filterHasAsset'   => ['except' => false],
        'perPage'          => ['except' => 25],
    ];

    /**
     * Jeder Filterwechsel verwirft die Auswahl: eine Auswahl, die nach dem Umschalten unsichtbar
     * weiterlebt, würde auf Zeilen schreiben, die der Bedienende nicht mehr sieht.
     * `updatingFilterHolderType()` fehlte bisher ganz — der Typfilter setzte die Seite nicht zurück.
     */
    public function updatingPreset(): void           { $this->resetPage(); $this->clearSelection(); }
    public function updatingSearch(): void           { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterDept(): void       { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterSku(): void        { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterSource(): void     { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterCostCenter(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterHolderType(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterHasLicense(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterHasDevice(): void  { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterHasAsset(): void   { $this->resetPage(); $this->clearSelection(); }

    /**
     * Beim Blättern bleibt die Auswahl bewusst erhalten — nur das Kopf-Häkchen fällt zurück, weil es
     * sich auf die jetzt sichtbare Seite bezieht. „Die 12 Admin-Konten von zwei Seiten" soll man
     * zusammensammeln können.
     */
    public function updatingPage(): void { $this->selectPage = false; }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;
        $this->resetPage();
        $this->clearSelection();
    }

    public function resetFilters(): void
    {
        $this->preset           = 'active';
        $this->search           = '';
        $this->filterDept       = '';
        $this->filterSku        = '';
        $this->filterSource     = '';
        $this->filterHolderType = 'person';
        $this->filterCostCenter = '';
        $this->filterHasLicense = false;
        $this->filterHasDevice  = false;
        $this->filterHasAsset   = false;
        $this->resetPage();
        $this->clearSelection();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    /** UPNs aller User mit mindestens einer Lizenz im Team (optional gefiltert nach sku_id). */
    protected function upnsWithLicense(int $teamId, ?string $skuId = null)
    {
        return $this->lookupMemo['lic:' . $skuId] ??= AssetUserLicense::where('team_id', $teamId)
            ->when($skuId, fn ($q) => $q->where('sku_id', $skuId))
            ->distinct()
            ->pluck('user_principal_name');
    }

    /** UPNs aller User mit mindestens einem Intune-Gerät im Team. */
    protected function upnsWithDevice(int $teamId)
    {
        return $this->lookupMemo['dev'] ??= AssetDevice::where('team_id', $teamId)
            ->whereNotNull('user_principal_name')
            ->distinct()
            ->pluck('user_principal_name');
    }

    /** Asset-Träger-IDs mit mindestens einem zugewiesenen Asset. */
    /**
     * Träger-Typ-Filter auf eine Query anwenden.
     *
     * `person` (Default) = nur echte Personen · `non_person` = nur Funktions-/Admin-/Service-Accounts ·
     * `''` = alle · ein konkreter Typ = genau dieser. Kein Filter auf einen unbekannten Wert: das
     * würde still eine leere Liste zeigen statt den Fehler zu offenbaren.
     */
    protected function applyHolderTypeFilter($query): void
    {
        if ($this->filterHolderType === '') {
            return;
        }

        if ($this->filterHolderType === 'non_person') {
            $query->nonPersons();

            return;
        }

        if (in_array($this->filterHolderType, AssetHolder::TYPES, true)) {
            $query->where('holder_type', $this->filterHolderType);
        }
    }

    protected function holderIdsWithAsset(int $teamId)
    {
        return $this->lookupMemo['asset'] ??= AssetItem::where('team_id', $teamId)
            ->whereNotNull('assignee_id')
            ->distinct()
            ->pluck('assignee_id');
    }

    /**
     * Wendet das Preset auf die Query an.
     * Preset 'inactive' überschreibt is_active-Filter — sonst implizit is_active=true.
     */
    protected function applyPreset($query, int $teamId): void
    {
        $licenseUpns = $this->upnsWithLicense($teamId);
        $deviceUpns  = $this->upnsWithDevice($teamId);
        $assetIds    = $this->holderIdsWithAsset($teamId);
        $allUpns     = $licenseUpns->merge($deviceUpns)->unique()->values();

        switch ($this->preset) {
            case 'inactive':
                $query->where('is_active', false);
                return;
            case 'with_license':
                $query->where('is_active', true)->whereIn('user_principal_name', $licenseUpns);
                return;
            case 'with_device':
                $query->where('is_active', true)->whereIn('user_principal_name', $deviceUpns);
                return;
            case 'with_asset':
                $query->where('is_active', true)->whereIn('id', $assetIds);
                return;
            case 'active':
                $query->where('is_active', true)
                    ->where(function ($q) use ($allUpns, $assetIds) {
                        $q->whereIn('user_principal_name', $allUpns)
                          ->orWhereIn('id', $assetIds);
                    });
                return;
            case 'unassigned':
                $query->where('is_active', true)
                    ->whereNotIn('user_principal_name', $allUpns)
                    ->whereNotIn('id', $assetIds);
                return;
            case 'all':
            default:
                // keine Einschränkung
        }
    }

    /**
     * Preset + alle Sidebar-Filter, ohne Sortierung und Pagination.
     *
     * Extrahiert, weil Liste und Mehrfachauswahl **dieselbe** Menge sehen müssen. Bliebe die Query
     * inline in `render()`, gäbe es eine zweite Kopie der Filterlogik — und „alle 312 gefilterten
     * auswählen" würde bei der ersten Abweichung etwas anderes auswählen als der Bildschirm zeigt.
     */
    protected function filteredQuery(int $teamId): Builder
    {
        $query = AssetHolder::where('team_id', $teamId);
        $this->applyPreset($query, $teamId);

        // Sidebar-Filter (kombiniert mit Preset)
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('user_principal_name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }
        if ($this->filterDept)       $query->where('department', $this->filterDept);
        if ($this->filterSource)     $query->where('source', $this->filterSource);
        $this->applyHolderTypeFilter($query);
        if ($this->filterCostCenter !== '') $query->where('cost_center_id', $this->filterCostCenter);

        if ($this->filterHasLicense || $this->filterSku) {
            $query->whereIn('user_principal_name', $this->upnsWithLicense($teamId, $this->filterSku ?: null));
        }
        if ($this->filterHasDevice) {
            $query->whereIn('user_principal_name', $this->upnsWithDevice($teamId));
        }
        if ($this->filterHasAsset) {
            $query->whereIn('id', $this->holderIdsWithAsset($teamId));
        }

        return $query;
    }

    // ---- Mehrfachauswahl --------------------------------------------------------------------

    public function clearSelection(): void
    {
        $this->selected     = [];
        $this->selectPage   = false;
        $this->bulkPreview  = null;
        $this->showBulkType = false;
    }

    /**
     * Kopf-Häkchen: fügt die IDs der aktuellen Seite hinzu oder entfernt sie wieder — additiv, nicht
     * überschreibend. Ohne diesen Hook wäre die Checkbox ein Blindgänger: Livewire setzt nur das
     * Flag, nicht die Auswahl.
     */
    public function updatedSelectPage($value): void
    {
        $pageIds = $this->filteredQuery($this->teamId())
            ->orderBy($this->sortField, $this->sortDirection)
            ->forPage($this->getPage(), $this->perPage)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->selected = $value
            ? array_values(array_unique(array_merge($this->selected, $pageIds)))
            : array_values(array_diff($this->selected, $pageIds));
    }

    public function selectAllFiltered(): void
    {
        $this->selected = $this->filteredQuery($this->teamId())
            ->orderBy($this->sortField, $this->sortDirection)
            ->limit(self::BULK_MAX)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** Hat der Deckel zugeschlagen? Die Oberfläche muss das sagen, sonst wirkt es wie ein Fehler. */
    public function selectionCapped(): bool
    {
        return count($this->selected) >= self::BULK_MAX;
    }

    // ---- Typ-Sammelaktion -------------------------------------------------------------------

    /** Vorschau rechnen und Modal öffnen — schreibt nichts. */
    public function openBulkType(HolderTypeService $service): void
    {
        Gate::authorize('asset-manager.manage');

        $result = $service->setType(
            $this->teamId(),
            $this->activeTenantId(),
            array_map('intval', $this->selected),
            $this->bulkType,
            true,
        );

        if (! $result['ok']) {
            $this->flash = $result['message'];

            return;
        }

        $this->bulkPreview  = $result;
        $this->showBulkType = true;
    }

    public function bulkSetType(HolderTypeService $service): void
    {
        Gate::authorize('asset-manager.manage');

        $result = $service->setType(
            $this->teamId(),
            $this->activeTenantId(),
            array_map('intval', $this->selected),
            $this->bulkType,
            false,
        );

        $this->flash = $result['message'];

        $this->clearSelection();
        $this->bulkType = '';
    }

    /** Backdrop/ESC verwirft nur die Vorschau — die mühsam zusammengeklickte Auswahl bleibt. */
    public function updatedShowBulkType(bool $value): void
    {
        if (! $value) {
            $this->bulkPreview = null;
        }
    }

    protected function activeTenantId(): int
    {
        return TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());
    }

    public function render()
    {
        $teamId = $this->teamId();

        // --- Counts für Chips (immer "echt", nicht durch Sidebar gefiltert) ---
        $licenseUpns = $this->upnsWithLicense($teamId);
        $deviceUpns  = $this->upnsWithDevice($teamId);
        $assetIds    = $this->holderIdsWithAsset($teamId);
        $allUpns     = $licenseUpns->merge($deviceUpns)->unique()->values();

        $base = AssetHolder::where('team_id', $teamId);

        $counts = [
            'all'          => (clone $base)->count(),
            'active'       => (clone $base)->where('is_active', true)
                                ->where(function ($q) use ($allUpns, $assetIds) {
                                    $q->whereIn('user_principal_name', $allUpns)
                                      ->orWhereIn('id', $assetIds);
                                })->count(),
            'with_license' => (clone $base)->where('is_active', true)->whereIn('user_principal_name', $licenseUpns)->count(),
            'with_device'  => (clone $base)->where('is_active', true)->whereIn('user_principal_name', $deviceUpns)->count(),
            'with_asset'   => (clone $base)->where('is_active', true)->whereIn('id', $assetIds)->count(),
            'unassigned'   => (clone $base)->where('is_active', true)
                                ->whereNotIn('user_principal_name', $allUpns)
                                ->whereNotIn('id', $assetIds)->count(),
            'inactive'     => (clone $base)->where('is_active', false)->count(),
        ];

        // Nicht-Personen zaehlen — fuer den Hinweis „x Funktions-/Admin-Accounts ausgeblendet".
        $nonPersonCount = (clone $base)->nonPersons()->count();

        $holders = $this->filteredQuery($teamId)
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        // --- Counts pro angezeigtem Asset-Träger ---
        $pageEmpIds = $holders->pluck('id')->toArray();
        $pageUpns   = $holders->pluck('user_principal_name')->toArray();

        $itemCounts = AssetItem::whereIn('assignee_id', $pageEmpIds)
            ->select('assignee_id', DB::raw('count(*) as count'))
            ->groupBy('assignee_id')
            ->pluck('count', 'assignee_id');

        $deviceCounts = AssetDevice::where('team_id', $teamId)
            ->whereIn('user_principal_name', $pageUpns)
            ->select('user_principal_name', DB::raw('count(*) as count'))
            ->groupBy('user_principal_name')
            ->pluck('count', 'user_principal_name');

        $licenseCounts = AssetUserLicense::where('team_id', $teamId)
            ->whereIn('user_principal_name', $pageUpns)
            ->select('user_principal_name', DB::raw('count(*) as count'))
            ->groupBy('user_principal_name')
            ->pluck('count', 'user_principal_name');

        // --- Dropdowns ---
        $departments = AssetHolder::where('team_id', $teamId)
            ->whereNotNull('department')
            ->select('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        $skus = AssetLicenseSku::where('team_id', $teamId)
            ->orderBy('display_name')
            ->get(['id', 'sku_id', 'sku_part_number', 'display_name']);

        // Baum-Reihenfolge (docs/adr/0016): oberste Knoten nach sort_order, darunter nach Code.
        $costCenters = AssetCostCenter::where('team_id', $teamId)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'parent_id', 'depth']);

        return view('asset-manager::livewire.holders.index', [
            'holders'       => $holders,
            'itemCounts'    => $itemCounts,
            'deviceCounts'  => $deviceCounts,
            'licenseCounts' => $licenseCounts,
            'counts'        => $counts,
            'departments'   => $departments,
            'skus'          => $skus,
            'costCenters'   => $costCenters,
            // Für den Hinweis „x Nicht-Personen ausgeblendet" — sie sind gelabelt, nicht gelöscht.
            'nonPersonCount' => $nonPersonCount,
            'holderTypes'    => AssetHolder::TYPES,
            // Einmal pro Render, nie `@can` in der Schleife: das Gate cacht seine Callbacks nicht
            // und `currentTeam` ist ein ungecachter Accessor mit eigener Query.
            'canManage'      => Gate::allows('asset-manager.manage'),
            'bulkMax'        => self::BULK_MAX,
        ])->layout('platform::layouts.app');
    }
}
