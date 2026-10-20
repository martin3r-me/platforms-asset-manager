<?php

namespace Platform\AssetManager\Livewire\Inventory;

use Platform\AssetManager\Concerns\ClampsListState;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\AssetWriteService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\InventoryService;
use Platform\AssetManager\Support\InventoryRow;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Platform\AssetManager\Concerns\ValidatesInGerman;

/**
 * Vereinte „Inventar"-Hauptliste über manuelle Assets (asset_items) UND Intune-Geräte
 * (asset_devices) — das kanonische Dach über beide Hardware-Welten (E1, ADR 0012). Zeilen-Klick führt
 * über InventoryRow::detailRoute auf die typ-richtige Detailseite.
 *
 * Schreibpfade hier betreffen nur **manuelle** Assets — Geräte kommen ausschließlich aus dem
 * Intune-Sync (E7), ihre Sammelaktionen liegen auf der Geräteliste:
 *  - Anlage-Modal, auch für mehrere gleiche Stücke auf einmal (vormals „Mehrere anlegen" auf /assets),
 *  - Sammelaktionen über eine Mehrfachauswahl (Zuordnen, Ins Lager, Status, Löschen, #974) — alle über
 *    {@see AssetWriteService}, damit Detailseite und Sammelweg dieselbe Status-Regel haben (#941).
 * Die Legacy-Liste /assets leitet seitdem hierher um.
 *
 * Gefiltert wird in SQL, geladen werden nur die Zeilen der Seite (#977); Träger für Anlage-Modal und
 * Sammel-Zuordnung nur, wenn eins von beiden offen ist.
 */
class Index extends Component
{
    use ClampsListState;
    use ResolvesCurrentTeam;
    use WithPagination;
    use ValidatesInGerman;

    public string $search           = '';
    public string $filterType       = '';   // '' | 'manual' | 'intune'
    public string $filterAssignment = '';   // '' | 'assigned' | 'unassigned'
    public string $filterStatus     = '';   // '' | item-status | device-lifecycle (statusSortKey)
    public ?int   $filterCategory   = null; // nur manuelle Assets
    public ?int   $filterCostCenter = null; // nur Geräte
    public int    $perPage          = 25;
    public string $sortField        = 'name';
    public string $sortDirection    = 'asc';

    // --- Anlage-Modal (nur manuelle Assets, E7) ---
    public bool    $showCreate          = false;
    public ?int    $cCategoryId         = null;
    public string  $cName               = '';
    public string  $cManufacturer       = '';
    public string  $cModel              = '';
    public string  $cSerialNumber       = '';
    public ?int    $cAssigneeId         = null;
    public string  $cStatus             = 'in_stock';
    public ?string $cPurchaseDate       = null;
    public ?string $cPurchasePrice      = null;
    public ?int    $cDepreciationMonths = null;
    public string  $cNotes              = '';
    /** Anzahl gleicher Stücke (vormals „Mehrere anlegen" auf /assets). */
    public ?int    $cQuantity           = 1;
    public ?string $flash               = null;

    // --- Mehrfachauswahl (nur manuelle Assets, #974) ---
    /** Ausgewählte AssetItem-IDs (als Strings, wie wire:model sie liefert). */
    public array   $selected     = [];
    public bool    $selectPage   = false;
    public ?int    $bulkAssignee = null;
    public string  $bulkStatus   = '';

    /** Request-lokaler Memo des Controlling-Schalters (protected → nicht im Livewire-State). */
    protected ?bool $controllingMemo = null;

    protected $queryString = [
        'search'           => ['except' => ''],
        'filterType'       => ['except' => ''],
        'filterAssignment' => ['except' => ''],
        'filterStatus'     => ['except' => ''],
        'filterCategory'   => ['except' => null],
        'filterCostCenter' => ['except' => null],
        'perPage'          => ['except' => 25],
        'sortField'        => ['except' => 'name'],
        'sortDirection'    => ['except' => 'asc'],
    ];

    public function mount(): void
    {
        // Deep-Link von der alten /assets/create-Route (Redirect mit ?create=1) öffnet das Modal.
        if (request()->boolean('create') && Gate::allows('create', AssetItem::class)) {
            $this->showCreate = true;
        }
    }

    /**
     * Controlling-Schalter des aktiven Teams × Tenants (ADR 0008). Gated Monatskosten-Spalte/-Sortierung,
     * Kostenstellen-Filter und die Abschreibungsfelder im Anlage-Modal (#874) — die Werte bleiben in der DB.
     */
    protected function controllingEnabled(): bool
    {
        return $this->controllingMemo ??= app(ControllingContext::class)->enabledFor($this->teamId());
    }

    // Ein Filterwechsel verwirft die Auswahl: sonst wirkte die Sammelaktion auf Zeilen, die gerade
    // niemand sieht.
    public function updatingSearch(): void           { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterType(): void       { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterAssignment(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterStatus(): void     { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterCategory(): void   { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterCostCenter(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingPerPage(): void          { $this->resetPage(); }
    public function updatingPage(): void             { $this->selectPage = false; }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterType', 'filterAssignment', 'filterStatus', 'filterCategory', 'filterCostCenter']);
        $this->resetPage();
        $this->clearSelection();
    }

    /** Aktive Filter in der Form, die {@see InventoryService} erwartet. */
    protected function currentFilters(): array
    {
        return [
            'search'     => $this->search,
            'type'       => $this->filterType,
            'assignment' => $this->filterAssignment,
            'status'     => $this->filterStatus,
            'category'   => $this->filterCategory,
            // Kostenstelle ist Controlling (#874): ohne Schalter filtert sie nicht, auch nicht aus der URL.
            'costCenter' => $this->controllingEnabled() ? $this->filterCostCenter : null,
        ];
    }

    /** Sind irgendwelche Filter aktiv? (steuert „Zurücksetzen" + Empty-State-Text in der View) */
    public function getHasFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->filterType !== ''
            || $this->filterAssignment !== ''
            || $this->filterStatus !== ''
            || $this->filterCategory !== null
            || $this->filterCostCenter !== null;
    }

    // ---------------------------------------------------------------------
    // Anlage-Modal (manuelle Assets)
    // ---------------------------------------------------------------------

    public function openCreate(): void
    {
        Gate::authorize('create', AssetItem::class);
        $this->resetCreateForm();
        $this->showCreate = true;
    }

    public function updatedCCategoryId(): void
    {
        // AfA-Default aus der Kategorie übernehmen, wenn noch nicht gesetzt (wie Assets/Create).
        if ($this->cCategoryId && ! $this->cDepreciationMonths) {
            $cat = AssetCategory::find($this->cCategoryId);
            if ($cat && $cat->default_depreciation_months) {
                $this->cDepreciationMonths = $cat->default_depreciation_months;
            }
        }
    }

    public function createItem(AssetWriteService $writer): void
    {
        Gate::authorize('create', AssetItem::class);

        $teamId = $this->teamId();

        $this->validate([
            'cCategoryId'         => 'required|exists:asset_categories,id',
            'cName'               => 'required|string|max:255',
            'cManufacturer'       => 'nullable|string|max:255',
            'cModel'              => 'nullable|string|max:255',
            'cSerialNumber'       => 'nullable|string|max:255',
            // Asset-Träger MUSS zum eigenen Team gehören (sonst danglende cross-team FK).
            'cAssigneeId'         => ['nullable', 'integer', Rule::exists('asset_holders', 'id')->where('team_id', $teamId)],
            'cStatus'             => 'required|in:in_stock,assigned,retired,lost',
            'cPurchaseDate'       => 'nullable|date',
            'cPurchasePrice'      => 'nullable|numeric|min:0',
            'cDepreciationMonths' => 'nullable|integer|min:1|max:240',
            'cNotes'              => 'nullable|string',
            'cQuantity'           => 'required|integer|min:1|max:500',
        ]);

        // Eine Seriennummer gehört zu genau einem Stück — bei mehreren lieber nachfragen als still verwerfen.
        if ((int) $this->cQuantity > 1 && trim($this->cSerialNumber) !== '') {
            $this->addError('cSerialNumber', 'Bei mehreren Stücken bleibt die Seriennummer leer — sie wird je Stück auf der Detailseite ergänzt.');

            return;
        }

        // Ohne Controlling sind die Abschreibungsfelder nicht sichtbar — auch serverseitig nichts davon
        // schreiben (z. B. den AfA-Default aus der Kategorie, #874, ADR 0008).
        if (! $this->controllingEnabled()) {
            $this->cPurchaseDate = $this->cPurchasePrice = null;
            $this->cDepreciationMonths = null;
        }

        $items = $writer->createItems([
            'categoryId'         => $this->cCategoryId,
            'name'               => $this->cName,
            'manufacturer'       => $this->cManufacturer,
            'model'              => $this->cModel,
            'serialNumber'       => $this->cSerialNumber,
            'assigneeId'         => $this->cAssigneeId,
            'status'             => $this->cStatus,
            'purchaseDate'       => $this->cPurchaseDate,
            'purchasePrice'      => $this->cPurchasePrice,
            'depreciationMonths' => $this->cDepreciationMonths,
            'notes'              => $this->cNotes,
        ], (int) $this->cQuantity, $teamId, (int) Auth::id());

        $name = $items->first()->name;
        $this->showCreate = false;
        $this->resetCreateForm();
        $this->resetPage();
        $this->flash = $items->count() > 1
            ? "{$items->count()}× „{$name}“ angelegt."
            : "Asset „{$name}“ angelegt.";
    }

    protected function resetCreateForm(): void
    {
        $this->reset([
            'cCategoryId', 'cName', 'cManufacturer', 'cModel', 'cSerialNumber',
            'cAssigneeId', 'cStatus', 'cPurchaseDate', 'cPurchasePrice', 'cDepreciationMonths', 'cNotes', 'cQuantity',
        ]);
    }

    // ---------------------------------------------------------------------
    // Mehrfachauswahl + Sammelaktionen (nur manuelle Assets, #974)
    // ---------------------------------------------------------------------

    public function clearSelection(): void
    {
        $this->selected   = [];
        $this->selectPage = false;
    }

    /** Wählt alle gefilterten manuellen Assets aus (Geräte sind nicht auswählbar). */
    public function selectAllFiltered(InventoryService $inventory): void
    {
        $this->selected = array_map('strval', $inventory->filteredItemIds(
            $this->teamId(), TenantContext::scopeTenantId(), $this->currentFilters(),
        ));
    }

    /** Kopf-Checkbox: die manuellen Assets der aktuellen Seite hinzu- bzw. abwählen. */
    public function updatedSelectPage($value): void
    {
        $pageIds = $this->pageItemIds(app(InventoryService::class));

        $this->selected = $value
            ? array_values(array_unique(array_merge($this->selected, $pageIds)))
            : array_values(array_diff($this->selected, $pageIds));
    }

    public function bulkAssign(AssetWriteService $writer): void
    {
        Gate::authorize('create', AssetItem::class);

        if ($this->selected === [] || ! $this->bulkAssignee) {
            return;
        }

        $holder = AssetHolder::where('team_id', $this->teamId())->find($this->bulkAssignee);
        if (! $holder) {
            return;
        }

        $items = $this->selectedItems();
        DB::transaction(fn () => $items->each(fn (AssetItem $item) => $writer->assignItem($item, $holder)));

        $this->finishBulk($items->count() . ' Asset(s) an ' . $holder->name . ' zugeordnet.');
    }

    /** Ins Lager = Status „Lager" über dieselbe Regel wie die Detailseite (#941): Zuordnung endet jetzt. */
    public function bulkUnassign(AssetWriteService $writer): void
    {
        Gate::authorize('create', AssetItem::class);

        if ($this->selected === []) {
            return;
        }

        $items = $this->selectedItems();
        DB::transaction(fn () => $items->each(fn (AssetItem $item) => $writer->setItemStatus($item, 'in_stock')));

        $this->finishBulk($items->count() . ' Asset(s) ins Lager zurückgelegt.');
    }

    public function bulkSetStatus(AssetWriteService $writer): void
    {
        Gate::authorize('create', AssetItem::class);

        if ($this->selected === [] || ! in_array($this->bulkStatus, AssetWriteService::ITEM_STATUSES, true)) {
            return;
        }

        $items   = $this->selectedItems();
        $status  = $this->bulkStatus;
        $skipped = 0;

        DB::transaction(function () use ($items, $writer, $status, &$skipped) {
            foreach ($items as $item) {
                // Eine Regel für Sammel- und Detailweg (#941): retired/lost/in_stock lösen die Zuordnung,
                // assigned ohne Träger wird übersprungen.
                if (! $writer->setItemStatus($item, $status)) {
                    $skipped++;
                }
            }
        });

        $labels = ['in_stock' => 'Lager', 'assigned' => 'Zugeordnet', 'retired' => 'Ausgemustert', 'lost' => 'Verloren'];
        $this->finishBulk(
            ($items->count() - $skipped) . ' Asset(s) auf Status „' . $labels[$status] . '“ gesetzt.'
            . ($skipped ? " {$skipped} ohne Träger übersprungen — „Zugeordnet“ braucht einen Träger." : ''),
        );
    }

    /**
     * Löschen (soft) — nur manuelle Assets. Das Recht ist dasselbe wie AssetItemPolicy::delete
     * (Owner/Admin, eigenes Team, nicht `source=intune`), hier aber EINMAL geprüft statt je Zeile: die
     * Policy fragt je Aufruf `currentTeam` ab, das wären zwei Queries pro Asset.
     */
    public function bulkDelete(): void
    {
        Gate::authorize('create', AssetItem::class);

        if ($this->selected === []) {
            return;
        }

        $requested = count($this->selected);
        $items     = $this->selectedItems()->where('source', '!=', 'intune')->values();
        DB::transaction(fn () => $items->each(fn (AssetItem $item) => $item->delete()));

        $this->finishBulk($items->count() . ' Asset(s) gelöscht.'
            . ($requested > $items->count() ? ' (Synchronisierte Einträge werden nicht gelöscht.)' : ''));
    }

    /** Ausgewählte Assets — team- und (Global Scope) tenant-begrenzt, nie fremde IDs aus dem Client. */
    protected function selectedItems(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));

        return $ids === []
            ? collect()
            : AssetItem::where('team_id', $this->teamId())->whereIn('id', $ids)->orderBy('id')->get();
    }

    protected function finishBulk(string $message): void
    {
        $this->flash        = $message;
        $this->bulkAssignee = null;
        $this->bulkStatus   = '';
        $this->clearSelection();
    }

    /** IDs der manuellen Assets auf der aktuellen Seite (als Strings, wie $selected). */
    protected function pageItemIds(InventoryService $inventory): array
    {
        return $this->pageRows($inventory)->getCollection()
            ->where('type', 'manual')
            ->map(fn (InventoryRow $row) => (string) $row->id)
            ->values()
            ->all();
    }

    protected function pageRows(InventoryService $inventory): LengthAwarePaginator
    {
        return $inventory->page(
            $this->teamId(),
            TenantContext::scopeTenantId(),
            $this->currentFilters(),
            $this->sortField,
            $this->sortDirection,
            $this->perPage,
            $this->getPage(),
        );
    }

    // ---------------------------------------------------------------------
    // CSV-Export (#974)
    // ---------------------------------------------------------------------

    /**
     * Gefilterte Inventar-Liste als CSV. Muster: Devices\Index::exportCsv().
     *
     * Lesepfad, daher **ohne** `asset-manager.manage` (ADR 0004) — wie der Export der Trägerliste.
     */
    public function exportCsv(InventoryService $inventory): StreamedResponse
    {
        $this->clampListState();

        $rows     = $this->csvRows($inventory);
        $filename = 'inventar-' . now()->format('Y-m-d') . '.csv';

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Kopfzeile + Datenzeilen des Exports — getrennt vom Streamen, damit der Inhalt prüfbar ist.
     *
     * Folgt **genau** den Filtern und der Sortierung der Liste, über alle Seiten: die Zeilenzahl ist die
     * Trefferzahl. Kostenstelle und Monatskosten nur bei aktivem Controlling (ADR 0008, #874).
     *
     * @return \Generator<int, list<string|int|null>>
     */
    protected function csvRows(InventoryService $inventory): \Generator
    {
        $controlling = $this->controllingEnabled();

        yield array_merge(
            ['Typ', 'ID', 'Name', 'Hersteller', 'Modell', 'Seriennr.', 'Zugeordnet an', 'Status', 'Kategorie', 'Standort'],
            $controlling ? ['Kostenstelle', 'Monatskosten EUR'] : [],
        );

        $rows = $inventory->sort(
            $inventory->filteredRows($this->teamId(), TenantContext::scopeTenantId(), $this->currentFilters()),
            $this->sortField,
            $this->sortDirection,
        );

        foreach ($rows as $row) {
            /** @var InventoryRow $row */
            yield array_merge(
                [
                    $row->type === 'intune' ? 'Intune-Gerät' : 'Manuell',
                    $row->id,
                    $row->name,
                    $row->manufacturer,
                    $row->model,
                    $row->serialNumber,
                    $row->assignedTo,
                    $row->statusLabel === '—' ? '' : $row->statusLabel,
                    $row->categoryName,
                    $row->location,
                ],
                $controlling ? [
                    $row->costCenterName,
                    number_format($row->monthlyCost, 2, ',', ''),
                ] : [],
            );
        }
    }

    public function render(InventoryService $inventory)
    {
        // Vor jeder Abfrage: der rendering-Hook käme erst nach render() (#957).
        $this->clampListState();

        $teamId   = $this->teamId();
        $tenantId = TenantContext::scopeTenantId();

        // Kostenstellen-Filter ist Controlling (#874, ADR 0008): ein ?filterCostCenter= aus URL/Bookmark
        // darf ohne Schalter nicht unsichtbar weiterfiltern.
        $controllingEnabled = $this->controllingEnabled();
        if (! $controllingEnabled) {
            $this->filterCostCenter = null;
        }

        $items = $this->pageRows($inventory);

        // Kopf-Checkbox synchron halten: angehakt, wenn alle manuellen Assets der Seite gewählt sind.
        $pageIds = $items->getCollection()->where('type', 'manual')->map(fn (InventoryRow $r) => (string) $r->id)->values()->all();
        $this->selectPage = $pageIds !== [] && array_diff($pageIds, $this->selected) === [];

        // Einmal je Render statt @can je Zeile (reference_can_per_row_queries).
        $canManage = Gate::allows('asset-manager.manage');

        // Träger nur, wenn sie gebraucht werden: im offenen Anlage-Modal oder für die Sammel-Zuordnung
        // (#977). Vorher lud jede Eingabe in die Suche alle aktiven Träger fürs geschlossene Modal.
        $needsHolders = $canManage && ($this->showCreate || $this->selected !== []);

        // Für das Banner „alle gefilterten auswählen" — nur gezählt, wenn es überhaupt erscheinen kann.
        $manualFiltered = $canManage && $this->selectPage
            ? ($inventory->itemQuery($teamId, $tenantId, $this->currentFilters())?->count() ?? 0)
            : 0;

        return view('asset-manager::livewire.inventory.index', [
            'items'         => $items,
            'counts'        => $inventory->counts($teamId, $tenantId),
            'totalFiltered' => $items->total(),
            'categories'    => AssetCategory::orderBy('sort_order')->get(),
            'holders'       => $needsHolders
                ? AssetHolder::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get()
                : collect(),
            'costCenters'   => $controllingEnabled
                ? AssetCostCenter::where('team_id', $teamId)->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'controllingEnabled' => $controllingEnabled,
            'canManage'          => $canManage,
            'manualFiltered'     => $manualFiltered,
        ])->layout('platform::layouts.app');
    }

    /** Erlaubte Seitengrößen — alles andere aus URL/Client wird geklemmt (#957). */
    protected function listPerPageOptions(): array
    {
        return [25, 50, 100];
    }

    /** Erlaubte Sortierspalten (#957); die erste ist der Rückfall. Monatskosten nur mit Controlling (#874). */
    protected function listSortable(): array
    {
        $fields = ['name', 'type', 'assignedTo', 'status'];

        if ($this->controllingEnabled()) {
            $fields[] = 'monthlyCost';
        }

        return $fields;
    }
}
