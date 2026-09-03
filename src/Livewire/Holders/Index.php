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
use Platform\AssetManager\Services\BillingExclusionService;
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

    /**
     * Sentinel für „Alle anzeigen". Bewusst `0` und keine große Zahl: ein Grenzwert wie 99999 wäre
     * eine stille Lüge, sobald ein Team mehr Träger hat. Die tatsächliche Seitengröße rechnet
     * {@see effectivePerPage()} aus dem Treffer-Zähler aus.
     */
    public const PER_PAGE_ALL = 0;

    /** Auswahlmöglichkeiten des Seitengrößen-Umschalters. */
    public const PER_PAGE_OPTIONS = [25, 50, 100, self::PER_PAGE_ALL];

    public string $preset           = 'active'; // all|active|with_license|with_device|with_asset|unassigned|inactive|billing_excluded
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
     * Abrechnungs-Ausnahme als Sammelaktion (ADR 0024).
     *
     * `$bulkExclude` trägt die Richtung (ausnehmen / Ausnahme aufheben), damit dasselbe Modal beide
     * Wege zeigen kann — die Vorschau unterscheidet sich nur im Vorzeichen der Mengenwirkung.
     * `$bulkReason` ist beim Ausnehmen Pflicht; das erzwingt der Service, nicht nur das Formular.
     */
    public string  $bulkReason      = '';
    public bool    $bulkExclude     = true;
    public bool    $showBulkBilling = false;
    public ?array  $bulkBillingPreview = null;

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

    /**
     * Seitengröße wechseln. Die Auswahl bleibt bewusst stehen — es ändert sich nur, wie viel man
     * gleichzeitig sieht, nicht *welche* Träger die Filter treffen. Das Kopf-Häkchen fällt zurück,
     * weil es sich auf die nun anders geschnittene Seite bezieht.
     */
    public function updatingPerPage(): void
    {
        $this->resetPage();
        $this->selectPage = false;
    }

    /**
     * Tatsächliche Seitengröße. Bei „Alle" so groß wie die Treffermenge — die Zahl kommt vom
     * Aufrufer, der sie ohnehin zählen muss.
     */
    protected function effectivePerPage(?int $total = null): int
    {
        if ($this->perPage !== self::PER_PAGE_ALL) {
            return $this->perPage;
        }

        return max($total ?? 0, 1);
    }

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
            // Ausnahmen bewusst OHNE is_active-Filter: eine Ausnahme an einem stillgelegten Träger
            // wirkt zwar nicht mehr auf die Rechnung, aber sie steht noch da — und diese Ansicht ist
            // die einzige Stelle, an der man sie aufräumen kann.
            case 'billing_excluded':
                $query->billingExcluded();
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
        $this->selected           = [];
        $this->selectPage         = false;
        $this->bulkPreview        = null;
        $this->showBulkType       = false;
        $this->bulkBillingPreview = null;
        $this->showBulkBilling    = false;
    }

    /**
     * Kopf-Häkchen: fügt die IDs der aktuellen Seite hinzu oder entfernt sie wieder — additiv, nicht
     * überschreibend. Ohne diesen Hook wäre die Checkbox ein Blindgänger: Livewire setzt nur das
     * Flag, nicht die Auswahl.
     */
    public function updatedSelectPage($value): void
    {
        $query = $this->filteredQuery($this->teamId())
            ->orderBy($this->sortField, $this->sortDirection);

        // Bei „Alle" ist die Seite die ganze Treffermenge — dann greift derselbe Deckel wie beim
        // ausdrücklichen „alle gefilterten auswählen". Sonst könnte ein einziges Häkchen tausende
        // IDs in den Livewire-State schieben.
        $pageIds = ($this->perPage === self::PER_PAGE_ALL
                ? (clone $query)->limit(self::BULK_MAX)
                : (clone $query)->forPage($this->getPage(), $this->perPage))
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

    // ---- Abrechnungs-Ausnahme als Sammelaktion (ADR 0024) -----------------------------------

    /**
     * Vorschau rechnen und Modal oeffnen — schreibt nichts.
     *
     * Der Weg fuehrt hier zwingend ueber eine Vorschau und nicht ueber `wire:confirm`: die Aktion
     * veraendert die Menge auf einer Kundenrechnung. Ein Ja/Nein-Dialog koennte die einzige Zahl
     * nicht nennen, auf die es ankommt — „Menge 152 → 140, −960 € netto".
     */
    public function openBulkBilling(bool $exclude): void
    {
        Gate::authorize('asset-manager.manage');

        $this->bulkExclude = $exclude;

        // Der Service kommt aus dem Container statt aus der Signatur: Livewire fuellt die Argumente
        // einer Action positionsweise aus dem Aufruf, und ein gemischtes „erst $exclude, dann eine
        // Abhaengigkeit" ist genau die Stelle, an der das schiefgeht.
        $result = app(BillingExclusionService::class)->setExclusion(
            $this->teamId(),
            $this->activeTenantId(),
            array_map('intval', $this->selected),
            $exclude,
            $exclude ? $this->bulkReason : null,
            (int) Auth::id(),
            true,
        );

        if (! $result['ok']) {
            $this->flash = $result['message'];

            return;
        }

        $this->bulkBillingPreview = $result;
        $this->showBulkBilling    = true;
    }

    public function bulkSetBillingExclusion(BillingExclusionService $service): void
    {
        Gate::authorize('asset-manager.manage');

        $result = $service->setExclusion(
            $this->teamId(),
            $this->activeTenantId(),
            array_map('intval', $this->selected),
            $this->bulkExclude,
            $this->bulkExclude ? $this->bulkReason : null,
            (int) Auth::id(),
            false,
        );

        $this->flash = $result['message'];

        // Bei einer Ablehnung bleibt die Auswahl stehen: der Grund kann zwischen Vorschau und
        // Bestaetigung geleert worden sein, und die Auswahl noch einmal zusammenzuklicken waere
        // die Strafe fuer einen Tippfehler.
        if (! $result['ok']) {
            $this->showBulkBilling = false;

            return;
        }

        $this->clearSelection();
        $this->bulkReason = '';
    }

    /** Backdrop/ESC verwirft nur die Vorschau — die Auswahl und der eingetippte Grund bleiben. */
    public function updatedShowBulkBilling(bool $value): void
    {
        if (! $value) {
            $this->bulkBillingPreview = null;
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
            // Die Zahl gehört sichtbar in die Liste: eine Ausnahmeliste, die man nur über den
            // Rechnungslauf findet, verrottet unbemerkt (ADR 0024).
            'billing_excluded' => (clone $base)->billingExcluded()->count(),
        ];

        // Nicht-Personen zaehlen — fuer den Hinweis „x Funktions-/Admin-Accounts ausgeblendet".
        $nonPersonCount = (clone $base)->nonPersons()->count();

        $listQuery = $this->filteredQuery($teamId)
            ->orderBy($this->sortField, $this->sortDirection);

        // Bei „Alle" braucht die Seitengröße die Treffermenge. Der Zähler kostet eine eigene Query —
        // aber nur in diesem Fall, und `paginate()` zählt anschließend ohnehin.
        $holders = $listQuery->paginate(
            $this->effectivePerPage($this->perPage === self::PER_PAGE_ALL ? (clone $listQuery)->count() : null)
        );

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
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ])->layout('platform::layouts.app');
    }
}
