<?php

namespace Platform\AssetManager\Livewire\CostLines;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\CostBootstrapService;
use Platform\AssetManager\Services\CostLineReassignService;
use Platform\AssetManager\Services\CostPlausibilityService;
use Platform\AssetManager\Services\MasterDataDeletionService;
use Platform\AssetManager\Services\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;

    /**
     * Gruppierungsachsen. Eine Stelle, an der eine weitere Achse dazukommt.
     *
     * Die Gruppierung laeuft per GROUP BY auf asset_cost_lines und NICHT ueber
     * AssetCostType::withCount()/withSum(): fuer cost_center_id IS NULL gibt es keine
     * Dimensionszeile, an die man ein withSum haengen koennte - die Gruppe "Ohne Kostenstelle"
     * waere damit prinzipiell nicht darstellbar. Sie ist aber real (der Pivot weist sie als
     * `unassigned` aus). Gruppierung ist eine Eigenschaft der ZEILEN, nicht der Dimension.
     */
    protected const AXES = [
        'cost_type'   => 'Kostenart',
        'cost_center' => 'Kostenstelle',
        'vendor'      => 'Kreditor',
        'frequency'   => 'Frequenz',
    ];

    protected const AXIS_COLUMNS = [
        'cost_type'   => 'asset_cost_lines.cost_type_id',
        'cost_center' => 'asset_cost_lines.cost_center_id',
        'vendor'      => 'asset_cost_lines.vendor_id',
        'frequency'   => 'asset_cost_lines.frequency',
    ];

    /** Schutz fuer ein kuenftiges Team mit sehr vielen Kostenstellen - real sind es ~25 Gruppen. */
    protected const MAX_GROUPS = 200;

    /** Detailzeilen je aufgeklappter Gruppe; darueber der Absprung in die flache Ansicht. */
    protected const GROUP_DETAIL_LIMIT = 50;

    /** Deckel fuer 'alles auswaehlen' - sonst waechst das Livewire-Payload ins Absurde. */
    protected const MAX_SELECTION = 500;

    public string  $view      = 'grouped';    // grouped|flat
    public string  $groupBy   = 'cost_type';
    public ?string $openGroup = null;         // Achsen-Key der EINEN offenen Gruppe ('none' = ohne Zuordnung)
    public string  $groupSort = 'amount';     // amount|count|label

    public string $search        = '';
    public ?int   $filterType     = null;
    public ?int   $filterCenter   = null;
    public ?int   $filterVendor   = null;
    public string $filterActive   = '';  // ''|'1'|'0'
    public string $filterSource   = '';  // ''|manual|excel_import|graph
    public string $filterFreq     = '';  // ''|monthly|quarterly|yearly|once
    public string $filterHolder   = '';  // ''|with|without
    public string $filterValidity = '';  // ''|current|future|expired|unlimited
    public string $filterFlagged  = '';  // ''|'1' - nur Positionen mit Plausibilitaets-Befund
    public int    $perPage        = 25;
    public string $sortField      = 'monthly_amount';
    public string $sortDirection  = 'desc';

    // Editor (Create/Update)
    public bool    $showEditor    = false;
    public ?int    $editId        = null;
    public ?int    $fCostType     = null;
    public string  $fCostCenter   = '';   // Code
    public ?int    $fVendor       = null;
    public string  $fLabel        = '';
    public string  $fAmount       = '';
    public string  $fFrequency    = 'monthly';
    public bool    $fActive       = true;
    public ?string $flash         = null;

    /** Request-lokaler Puffer fuer plausibility() - protected, also nicht Teil des Livewire-State. */
    protected ?array $plausibilityCache = null;

    /**
     * Bulk-Auswahl. IDs als Strings, damit wire:model auf Checkbox-Werten sauber vergleicht
     * (Muster: Livewire\Assets\Index). Bewusst NICHT im $queryString - ein Array von 500 IDs
     * macht die URL unbenutzbar.
     *
     * @var list<string>
     */
    public array   $selected         = [];
    public bool    $selectPage       = false;
    public ?int    $bulkCenter       = null;
    public bool    $showBulkReassign = false;
    public ?array  $bulkPreview      = null;

    protected $queryString = [
        'view'         => ['except' => 'grouped'],
        'groupBy'      => ['except' => 'cost_type'],
        'openGroup'    => ['except' => null],
        'groupSort'    => ['except' => 'amount'],
        'search'       => ['except' => ''],
        'filterType'   => ['except' => null],
        'filterCenter' => ['except' => null],
        'filterVendor' => ['except' => null],
        'filterActive' => ['except' => ''],
        'filterSource' => ['except' => ''],
        'filterFreq'   => ['except' => ''],
        'filterHolder' => ['except' => ''],
        'filterValidity' => ['except' => ''],
        'filterFlagged' => ['except' => ''],
        'sortField'    => ['except' => 'monthly_amount'],
        'sortDirection'=> ['except' => 'desc'],
    ];

    public function updatingSearch(): void       { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterType(): void   { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterCenter(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterVendor(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterActive(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterSource(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterFreq(): void   { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterHolder(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterValidity(): void { $this->resetPage(); $this->clearSelection(); }
    public function updatingFilterFlagged(): void { $this->resetPage(); $this->clearSelection(); }

    /** Spaltensortierung umschalten: gleiches Feld → Richtung kippen, sonst aufsteigend. */
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

    /** Sortierung auf die Query anwenden — verknüpfte Spalten alphabetisch per Join. */
    protected function applySort($query): void
    {
        $dir = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        match ($this->sortField) {
            'cost_type' => $query
                ->leftJoin('asset_cost_types', 'asset_cost_types.id', '=', 'asset_cost_lines.cost_type_id')
                ->orderBy('asset_cost_types.name', $dir),
            'cost_center' => $query
                ->leftJoin('asset_cost_centers', 'asset_cost_centers.id', '=', 'asset_cost_lines.cost_center_id')
                ->orderBy('asset_cost_centers.code', $dir),
            'vendor' => $query
                ->leftJoin('asset_vendors', 'asset_vendors.id', '=', 'asset_cost_lines.vendor_id')
                ->orderBy('asset_vendors.name', $dir),
            'assignee' => $query
                ->leftJoin('asset_holders', 'asset_holders.id', '=', 'asset_cost_lines.assignee_id')
                ->orderBy('asset_holders.display_name', $dir),
            'valid_from' => $query->orderBy('asset_cost_lines.valid_from', $dir),
            'label'     => $query->orderBy('asset_cost_lines.label', $dir),
            'amount'    => $query->orderBy('asset_cost_lines.amount', $dir),
            'frequency' => $query->orderBy('asset_cost_lines.frequency', $dir),
            default     => $query->orderBy('asset_cost_lines.monthly_amount', $dir),
        };
    }

    /** Filter zurücksetzen (linke Sidebar). */
    public function resetFilters(): void
    {
        $this->reset([
            'search', 'filterType', 'filterCenter', 'filterVendor', 'filterActive',
            'filterSource', 'filterFreq', 'filterHolder', 'filterValidity', 'filterFlagged',
        ]);
        $this->openGroup = null;
        $this->resetPage();
    }

    public function newLine(): void
    {
        $this->resetEditor();
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId      = $line->id;
        $this->fCostType   = $line->cost_type_id;
        $this->fCostCenter = $line->costCenter?->code ?? '';
        $this->fVendor     = $line->vendor_id;
        $this->fLabel      = $line->label;
        $this->fAmount     = (string) $line->amount;
        $this->fFrequency  = $line->frequency;
        $this->fActive     = (bool) $line->active;
        $this->showEditor  = true;
        $this->resetValidation();
    }

    public function save(CostBootstrapService $bootstrap): void
    {
        Gate::authorize('asset-manager.manage');

        $teamId = $this->teamId();

        $tenantId = TenantContext::resolveForWrite($teamId, (int) Auth::id());

        // FK-Refs TENANT-scopen: Kostenarten und Kreditoren sind seit ADR 0016 tenant-gebunden, eine
        // Fremd-Referenz wird als Validierungsfehler (422) abgelehnt statt still übernommen — sonst
        // grenzüberschreitender FK + fremde Namen in der Liste + verfälschte Kostenzuordnung.
        $this->validate([
            'fCostType'  => ['required', 'integer', Rule::exists('asset_cost_types', 'id')->where('tenant_id', $tenantId)],
            'fLabel'     => 'required|string|max:255',
            'fAmount'    => 'required|numeric',
            'fFrequency' => 'required|in:monthly,quarterly,yearly,once',
            'fVendor'    => ['nullable', 'integer', Rule::exists('asset_vendors', 'id')->where('tenant_id', $tenantId)],
        ]);

        // Team-aufgelöste Instanzen laden und deren IDs persistieren (nie die rohe Request-ID).
        $type = AssetCostType::where('team_id', $teamId)->find($this->fCostType);
        if ($type === null) {
            $this->addError('fCostType', 'Kostenart gehört nicht zum Team.');
            return;
        }
        $vendor = $this->fVendor
            ? AssetVendor::where('team_id', $teamId)->find($this->fVendor)
            : null;
        $center = $bootstrap->resolveCostCenter($teamId, $this->fCostCenter ?: null, $tenantId);

        // Betrag 0 ablehnen; negativ nur bei allow_negative-Kostenart (Gutschrift) — verhindert stilles
        // Netting durch Tippfehler-Minusbeträge. Inline-Fehler, Editor bleibt offen.
        $amount = (float) $this->fAmount;
        if ($amount == 0.0 || ($amount < 0 && ! $type?->allow_negative)) {
            $this->addError('fAmount', $amount == 0.0
                ? 'Betrag darf nicht 0,00 sein.'
                : 'Negativer Betrag ist nur für Gutschrift-Kostenarten (allow_negative) zulässig.');
            return;
        }

        $data = [
            'team_id'           => $teamId,
            'tenant_id'         => $tenantId,
            'cost_type_id'      => $type->id,
            'vendor_id'         => $vendor?->id ?: $type->vendor_default_id,
            'cost_center_id'    => $center?->id,
            'label'             => $this->fLabel,
            'amount'            => (float) $this->fAmount,
            'currency'          => 'EUR',
            'frequency'         => $this->fFrequency,
            'accounting_system' => $type?->system_default,
            'active'            => $this->fActive,
        ];

        if ($this->editId) {
            $line = AssetCostLine::where('team_id', $teamId)->findOrFail($this->editId);
            $line->update($data);
            $this->flash = 'Kostenposition aktualisiert.';
        } else {
            $data['source'] = 'manual';
            AssetCostLine::create($data);
            $this->flash = 'Kostenposition angelegt.';
        }

        $this->forgetPlausibility();
        $this->resetEditor();
        $this->showEditor = false;
    }

    public function delete(int $id): void
    {
        Gate::authorize('asset-manager.manage');

        // Über den Service, damit UI und MCP-Tool (asset-manager.cost-lines.DELETE) dasselbe tun.
        $result = app(MasterDataDeletionService::class)->deleteCostLine($this->teamId(), $id);

        // Wird gerade diese Zeile im Editor-Modal bearbeitet → Modal schließen.
        if ($result['ok'] && $this->editId === $id) {
            $this->resetEditor();
            $this->showEditor = false;
        }
        $this->forgetPlausibility();
        $this->flash = $result['message'];
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('asset-manager.manage');

        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $line->update(['active' => !$line->active]);
        $this->forgetPlausibility();
    }

    /** Editor-Modal schließen und Formularzustand verwerfen. */
    public function cancelEdit(): void
    {
        $this->resetEditor();
        $this->showEditor = false;
        $this->resetValidation();
    }

    /**
     * Das Modal ist per entangle an $showEditor gebunden — Backdrop-Klick und ESC setzen die
     * Property direkt. Ohne diesen Hook bliebe der halb ausgefüllte Editor im Zustand stehen und
     * tauchte beim nächsten Öffnen wieder auf.
     */
    public function updatedShowEditor(bool $value): void
    {
        if (! $value) {
            $this->resetEditor();
            $this->resetValidation();
        }
    }

    protected function resetEditor(): void
    {
        $this->reset(['editId', 'fCostType', 'fCostCenter', 'fVendor', 'fLabel', 'fAmount']);
        $this->fFrequency = 'monthly';
        $this->fActive    = true;
    }

    /**
     * Gefilterte Basis-Query — bewusst OHNE Sortier-Joins.
     *
     * applySort() haengt LEFT JOINs an; die duerfen NIE in eine Aggregat-/Count-Query, sonst
     * vervielfachen sie COUNT(*) und SUM(). Jeder Aufruf liefert eine frische Query, damit kein
     * clone()-Ritual noetig ist.
     */
    protected function baseQuery(): Builder
    {
        $q = AssetCostLine::query()->where('asset_cost_lines.team_id', $this->teamId());

        if ($this->search !== '')       $q->where('asset_cost_lines.label', 'like', '%' . $this->search . '%');
        if ($this->filterType)          $q->where('asset_cost_lines.cost_type_id', $this->filterType);
        if ($this->filterCenter)        $q->where('asset_cost_lines.cost_center_id', $this->filterCenter);
        if ($this->filterVendor)        $q->where('asset_cost_lines.vendor_id', $this->filterVendor);
        if ($this->filterActive !== '') $q->where('asset_cost_lines.active', $this->filterActive === '1');
        if ($this->filterSource !== '') $q->where('asset_cost_lines.source', $this->filterSource);
        if ($this->filterFreq !== '')   $q->where('asset_cost_lines.frequency', $this->filterFreq);

        match ($this->filterHolder) {
            'with'    => $q->whereNotNull('asset_cost_lines.assignee_id'),
            'without' => $q->whereNull('asset_cost_lines.assignee_id'),
            default   => null,
        };

        // Gueltigkeit: NULL heisst unbegrenzt (siehe AssetCostLine::validOn()). Der Fall
        // "abgelaufen"/"zukuenftig" ist der eigentliche Grund fuer diesen Filter — beides ist in
        // der Liste sonst unsichtbar und faellt trotzdem aus der Kostenaufteilung heraus.
        $today = now()->toDateString();
        match ($this->filterValidity) {
            'current'   => $q->validOn($today),
            'future'    => $q->whereNotNull('asset_cost_lines.valid_from')
                             ->whereDate('asset_cost_lines.valid_from', '>', $today),
            'expired'   => $q->whereNotNull('asset_cost_lines.valid_to')
                             ->whereDate('asset_cost_lines.valid_to', '<', $today),
            'unlimited' => $q->whereNull('asset_cost_lines.valid_from')
                             ->whereNull('asset_cost_lines.valid_to'),
            default     => null,
        };

        // Nur auffaellige: die Arbeitsschleife dieser Seite - Befund finden, hier filtern, korrigieren.
        if ($this->filterFlagged === '1') {
            $ids = array_keys($this->plausibility()['map']);
            $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn('asset_cost_lines.id', $ids);
        }

        return $q;
    }

    /** Ist ueberhaupt ein Filter gesetzt? Steuert Reset-Button und Empty-State-Text. */
    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->filterType !== null
            || $this->filterCenter !== null
            || $this->filterVendor !== null
            || $this->filterActive !== ''
            || $this->filterSource !== ''
            || $this->filterFreq !== ''
            || $this->filterHolder !== ''
            || $this->filterValidity !== ''
            || $this->filterFlagged !== '';
    }

    /**
     * Auswahl verwerfen.
     *
     * Aufgerufen wird das nur, wo die Auswahl ihre Bedeutung verliert - bei Filter- und
     * Suchaenderungen. NICHT beim Blaettern und nicht beim Wechsel von Ansicht oder Achse:
     * "die 7 necta-Zeilen umbuchen" soll einen Ansichtswechsel ueberleben.
     */
    public function clearSelection(): void
    {
        $this->selected         = [];
        $this->selectPage       = false;
        $this->bulkPreview      = null;
        $this->showBulkReassign = false;
    }

    public function updatingPage(): void
    {
        $this->selectPage = false;
    }

    /**
     * Kopf-Checkbox der flachen Ansicht: alle Zeilen DIESER Seite an- bzw. abwaehlen.
     *
     * Ohne diesen Hook waere die Checkbox ein Blindgaenger - sie wuerde $selectPage setzen,
     * aber $selected nie fuellen.
     */
    public function updatedSelectPage($value): void
    {
        $query = $this->baseQuery();
        $this->applySort($query);

        $pageIds = $query->forPage($this->getPage(), $this->perPage)
            ->pluck('asset_cost_lines.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->selected = $value
            ? array_values(array_unique(array_merge($this->selected, $pageIds)))
            : array_values(array_diff($this->selected, $pageIds));
    }

    /** Alle gefilterten Positionen auswaehlen (gedeckelt). */
    public function selectAllFiltered(): void
    {
        $this->selected = $this->baseQuery()
            ->limit(self::MAX_SELECTION)
            ->pluck('asset_cost_lines.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Ganze Gruppe auswaehlen - die eigentliche Bulk-Ergonomie dieser Seite.
     *
     * "alle 7 necta-Zeilen" ist ein Klick, nicht sieben. Genommen werden ALLE Positionen der
     * Gruppe, nicht nur die 50 aufgeklappt sichtbaren.
     */
    public function selectGroup(string $key): void
    {
        $ids = $this->applyAxisFilter($this->baseQuery(), $key)
            ->limit(self::MAX_SELECTION)
            ->pluck('asset_cost_lines.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
    }

    /**
     * Vorschau der Umbuchung (dry_run) - fuellt das Bestaetigungs-Modal.
     *
     * Bewusst statt eines blinden wire:confirm: die Vorschau sagt, wie viele Positionen wirklich
     * umziehen, wie viele schon am Ziel haengen und wie viele nicht gefunden wurden. Genau dafuer
     * gibt es den dry_run-Parameter im Service.
     */
    public function openBulkReassign(CostLineReassignService $service): void
    {
        Gate::authorize('asset-manager.manage');

        $result = $service->reassignCostCenter(
            $this->teamId(),
            array_map('intval', $this->selected),
            $this->bulkCenter,
            null,
            true,
        );

        if (! $result['ok']) {
            $this->flash = $result['message'];
            return;
        }

        $this->bulkPreview      = $result;
        $this->showBulkReassign = true;
    }

    /** Umbuchung ausfuehren. Dieselbe Logik wie das MCP-Tool (CostLineReassignService). */
    public function bulkReassignCenter(CostLineReassignService $service): void
    {
        Gate::authorize('asset-manager.manage');

        $result = $service->reassignCostCenter(
            $this->teamId(),
            array_map('intval', $this->selected),
            $this->bulkCenter,
            null,
            false,
        );

        $this->flash = $result['message'];

        if ($result['ok']) {
            $this->forgetPlausibility();
            $this->clearSelection();
            $this->bulkCenter = null;
        }
    }

    /** Backdrop/ESC am Bestaetigungs-Modal: Vorschau verwerfen, Auswahl behalten. */
    public function updatedShowBulkReassign(bool $value): void
    {
        if (! $value) {
            $this->bulkPreview = null;
        }
    }

    /**
     * Gefilterte Positionen als CSV. Muster: Devices\Index::exportCsv().
     *
     * Immer ueber baseQuery(), nie ueber die Aggregat-Query: die Datei muss denselben
     * Datenbestand enthalten wie der Bildschirm, von dem sie ausgeloest wurde. cursor() statt
     * get(), damit auch ein grosser Bestand nicht in den Speicher laeuft. Die ID wandert mit -
     * sie ist der Rueckweg (Deep-Link, MCP-Tools, Bulk-Umbuchung).
     */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->baseQuery()->with(['costType', 'costCenter', 'vendor', 'assignee']);
        $this->applySort($rows);

        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM fuer Excel

            fputcsv($out, [
                'ID', 'Bezeichnung', 'Kostenart', 'Bezugsebene', 'Kostenstelle', 'KSt-Bezeichnung',
                'Asset-Traeger', 'Kreditor', 'Betrag', 'Waehrung', 'FX-Kurs', 'Frequenz', 'EUR/Monat',
                'Herkunft', 'Gueltig ab', 'Gueltig bis', 'Aktiv', 'Buchhaltung', 'Periode',
            ], ';');

            foreach ($rows->cursor() as $line) {
                fputcsv($out, [
                    $line->id,
                    $line->label,
                    $line->costType?->name,
                    $line->costType?->levelLabel(),
                    $line->costCenter?->code,
                    $line->costCenter?->name,
                    $line->assignee?->name,
                    $line->vendor?->name,
                    number_format((float) $line->amount, 2, ',', ''),
                    $line->currency,
                    $line->fx_rate !== null ? number_format((float) $line->fx_rate, 6, ',', '') : '',
                    $line->frequency,
                    number_format((float) $line->monthly_amount, 2, ',', ''),
                    $line->source,
                    $line->valid_from?->format('Y-m-d'),
                    $line->valid_to?->format('Y-m-d'),
                    $line->active ? 'ja' : 'nein',
                    $line->accounting_system,
                    $line->period_label,
                ], ';');
            }

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kostenpositionen-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'flat' ? 'flat' : 'grouped';
        $this->resetPage();
    }

    /** Achse wechseln. Die offene Gruppe gehoert zur alten Achse und wird verworfen. */
    public function setGroupBy(string $axis): void
    {
        if (! isset(self::AXES[$axis])) {
            return;
        }

        $this->groupBy   = $axis;
        $this->openGroup = null;
        $this->resetPage();
    }

    public function sortGroupsBy(string $key): void
    {
        $this->groupSort = in_array($key, ['amount', 'count', 'label'], true) ? $key : 'amount';
    }

    /**
     * Genau EINE Gruppe ist gleichzeitig offen.
     *
     * Damit ist die Ansicht deep-linkbar (?groupBy=cost_type&openGroup=70 ist "zeig mir
     * Mobilfunk" - die Voraussetzung fuer Abspruenge IN diese Seite) und die Renderkosten
     * bleiben konstant bei genau einer Detail-Query, unabhaengig vom Klickverhalten.
     */
    public function toggleGroup(string $key): void
    {
        $this->openGroup = $this->openGroup === $key ? null : $key;
    }

    /** Gruppe gefiltert in der flachen Ansicht oeffnen (Fussnote "Alle anzeigen"). */
    public function drillDown(string $key): void
    {
        match ($this->groupBy) {
            'cost_center' => $this->filterCenter = $key === 'none' ? null : (int) $key,
            'vendor'      => $this->filterVendor = $key === 'none' ? null : (int) $key,
            'frequency'   => $this->filterFreq   = $key,
            default       => $this->filterType   = $key === 'none' ? null : (int) $key,
        };

        // "Ohne Zuordnung" laesst sich mit den vorhandenen Filtern nicht abbilden (sie kennen nur
        // "eine bestimmte ID" und "alle"). Statt still die ganze Liste zu zeigen bleibt die
        // Gruppe aufgeklappt und die Ansicht gruppiert.
        if ($key === 'none' && $this->groupBy !== 'frequency') {
            $this->openGroup = 'none';
            return;
        }

        $this->view      = 'flat';
        $this->openGroup = null;
        $this->resetPage();
    }

    /** Achsen-Filter fuer Detail-Query und Drilldown. 'none' -> IS NULL. */
    protected function applyAxisFilter(Builder $query, string $key): Builder
    {
        $col = self::AXIS_COLUMNS[$this->groupBy];

        if ($this->groupBy === 'frequency') {
            return $query->where($col, $key);
        }

        return $key === 'none' ? $query->whereNull($col) : $query->where($col, (int) $key);
    }

    /**
     * Gruppenzeilen: EINE Aggregat-Query, keine Models, kein N+1.
     *
     * Die Label-Spalten werden mit-gruppiert, nicht nur mit-selektiert - MySQL laeuft mit
     * ONLY_FULL_GROUP_BY und wuerde sonst abbrechen (auf SQLite faellt das nicht auf).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function groupRows(): \Illuminate\Support\Collection
    {
        $q = $this->baseQuery();

        $aggregates = 'COUNT(*) as lines_count, '
            . 'SUM(asset_cost_lines.monthly_amount) as monthly, '
            // once-Zeilen haben monthly_amount = 0 (FREQUENCY_FACTORS) und fliessen damit korrekt
            // NICHT in `monthly`. Der Rohbetrag als eigener Topf, damit er nicht still verschwindet.
            . "SUM(CASE WHEN asset_cost_lines.frequency = 'once' THEN asset_cost_lines.amount ELSE 0 END) as once_amount, "
            . 'SUM(CASE WHEN asset_cost_lines.active = 0 THEN 1 ELSE 0 END) as inactive_count';

        switch ($this->groupBy) {
            case 'cost_center':
                $q->leftJoin('asset_cost_centers', 'asset_cost_centers.id', '=', 'asset_cost_lines.cost_center_id')
                  ->groupBy('asset_cost_lines.cost_center_id', 'asset_cost_centers.code', 'asset_cost_centers.name')
                  ->selectRaw('asset_cost_lines.cost_center_id as gkey, asset_cost_centers.code as glabel, '
                      . 'asset_cost_centers.name as gsub, NULL as glevel, NULL as gsource, ' . $aggregates);
                break;

            case 'vendor':
                $q->leftJoin('asset_vendors', 'asset_vendors.id', '=', 'asset_cost_lines.vendor_id')
                  ->groupBy('asset_cost_lines.vendor_id', 'asset_vendors.name')
                  ->selectRaw('asset_cost_lines.vendor_id as gkey, asset_vendors.name as glabel, '
                      . 'NULL as gsub, NULL as glevel, NULL as gsource, ' . $aggregates);
                break;

            case 'frequency':
                $q->groupBy('asset_cost_lines.frequency')
                  ->selectRaw('asset_cost_lines.frequency as gkey, asset_cost_lines.frequency as glabel, '
                      . 'NULL as gsub, NULL as glevel, NULL as gsource, ' . $aggregates);
                break;

            default:
                $q->leftJoin('asset_cost_types', 'asset_cost_types.id', '=', 'asset_cost_lines.cost_type_id')
                  ->groupBy('asset_cost_lines.cost_type_id', 'asset_cost_types.name',
                            'asset_cost_types.allocation_level', 'asset_cost_types.aggregation_source')
                  ->selectRaw('asset_cost_lines.cost_type_id as gkey, asset_cost_types.name as glabel, '
                      . 'NULL as gsub, asset_cost_types.allocation_level as glevel, '
                      . 'asset_cost_types.aggregation_source as gsource, ' . $aggregates);
        }

        // Vorsortierung in SQL, damit bei einem Overflow die groessten Gruppen drin bleiben;
        // die Feinsortierung passiert danach in PHP (nur ~25 Zeilen, dafuer dialektfrei).
        return collect(
            $q->toBase()
                ->orderByRaw('SUM(asset_cost_lines.monthly_amount) DESC')
                ->limit(self::MAX_GROUPS + 1)
                ->get()
        );
    }

    /** Detailzeilen der EINEN offenen Gruppe. */
    protected function openGroupLines(): \Illuminate\Support\Collection
    {
        if ($this->view !== 'grouped' || $this->openGroup === null) {
            return collect();
        }

        return $this->applyAxisFilter($this->baseQuery(), $this->openGroup)
            ->with(['costType', 'costCenter', 'vendor', 'assignee'])
            ->select('asset_cost_lines.*')
            // Nach Betrag OHNE Vorzeichen: eine Gutschrift von -430 EUR gehoert neben die
            // Position, die sie gegenrechnet, nicht ans Listenende.
            ->orderByRaw('ABS(asset_cost_lines.monthly_amount) DESC')
            ->orderBy('asset_cost_lines.label')
            ->limit(self::GROUP_DETAIL_LIMIT)
            ->get();
    }

    /**
     * Rohe Aggregat-Zeilen zu Anzeigezeilen machen: Label, Anteil, Befund-Anzahl.
     *
     * Bezugsgroesse des Anteils ist die Summe der POSITIVEN Gruppensummen, nicht das Netto:
     * bei einem Netto von 16.080 EUR und einer Gutschriftgruppe von -1.196 EUR waere der Anteil
     * jeder Gruppe sonst systematisch ueberzeichnet - und bei einem Netto <= 0 (moeglich, wenn
     * nur auf Rabatte gefiltert wird) gaebe es ueberhaupt keinen sinnvollen Bezug.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function decorateGroups(\Illuminate\Support\Collection $rows, array $flaggedMap): \Illuminate\Support\Collection
    {
        $freqLabels = ['monthly' => 'Monatlich', 'quarterly' => 'Quartal', 'yearly' => 'Jährlich', 'once' => 'Einmalig'];
        $emptyLabel = match ($this->groupBy) {
            'cost_center' => 'Ohne Kostenstelle',
            'vendor'      => 'Ohne Kreditor',
            'frequency'   => 'Ohne Frequenz',
            default       => 'Ohne Kostenart',
        };

        $grossBase = $rows->sum(fn ($r) => max((float) $r->monthly, 0));
        $perGroup  = $this->flaggedPerGroup(array_keys($flaggedMap));

        $groups = $rows->map(function ($r) use ($freqLabels, $emptyLabel, $grossBase, $perGroup): array {
            $key     = $r->gkey === null ? 'none' : (string) $r->gkey;
            $monthly = round((float) $r->monthly, 2);   // DECIMAL kommt als String aus PDO

            return [
                'key'      => $key,
                'label'    => $r->gkey === null
                    ? $emptyLabel
                    : ($this->groupBy === 'frequency' ? ($freqLabels[$r->glabel] ?? $r->glabel) : (string) $r->glabel),
                'sublabel' => $r->gsub ?: null,
                'count'    => (int) $r->lines_count,
                'monthly'  => $monthly,
                'once'     => round((float) $r->once_amount, 2),
                'inactive' => (int) $r->inactive_count,
                'flagged'  => $perGroup[$key] ?? 0,
                'level'    => $r->glevel ?: null,
                // Positionen auf einer Kostenart, die ihren Wert aus einer ANDEREN Quelle zieht
                // (ms_license, hardware_afa), fallen aus normalizedLines() und erscheinen damit in
                // KEINER Auswertung. Heute sind diese Kostenarten leer, aber ein Import kann dort
                // jederzeit Geld parken. Diese Warnung gibt es sonst nirgends im UI.
                'offPivot' => $this->groupBy === 'cost_type'
                    && $r->gsource !== null
                    && $r->gsource !== AssetCostType::SOURCE_COST_LINE,
                'sharePct' => $grossBase > 0 ? min(100.0, round(abs($monthly) / $grossBase * 100, 1)) : 0.0,
            ];
        });

        return match ($this->groupSort) {
            'count' => $groups->sortByDesc('count')->values(),
            'label' => $groups->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            // Betrag OHNE Vorzeichen: sonst landet die groesste Gutschrift als kleinster Wert am
            // Ende der Liste, weit weg von der Position, die sie gegenrechnet.
            default => $groups->sortByDesc(fn (array $g) => abs($g['monthly']))->values(),
        };
    }

    /**
     * Anzahl auffaelliger Positionen je Gruppe - eine schlanke Query ueber die ~25 geflaggten IDs.
     *
     * @param  list<int>  $flaggedIds
     * @return array<string, int>
     */
    protected function flaggedPerGroup(array $flaggedIds): array
    {
        if ($flaggedIds === []) {
            return [];
        }

        $col    = self::AXIS_COLUMNS[$this->groupBy];
        $counts = [];

        foreach ($this->baseQuery()->whereIn('asset_cost_lines.id', $flaggedIds)->pluck($col) as $value) {
            $key = $value === null ? 'none' : (string) $value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Plausibilitaets-Befunde als line_id => [Titel, ...] plus die Bestands-Kennzahl.
     *
     * CostPlausibilityService::check() liefert pro Befund die VOLLSTAENDIGEN line_ids (nicht nur
     * MAX_EXAMPLES) - ein Aufruf genuegt fuer Warn-Icons in jeder Zeile, kein N+1. Die Regeln sind
     * kreuz-zeilig (z. B. Einzelpreis-Modus der ganzen Kostenart), lassen sich also nicht auf die
     * aktuelle Seite eingrenzen: ganz oder gar nicht.
     *
     * Der Cache-Key enthaelt die tenant_id - check() laeuft unter dem TenantScope, ein team-only-Key
     * wuerde Ergebnisse ueber Tenants hinweg vermischen. Gecacht wird nur die kleine Map, nicht das
     * ganze Befund-Bundle.
     *
     * @return array{map: array<int, list<string>>, monthly: float}
     */
    protected function plausibility(): array
    {
        if ($this->plausibilityCache !== null) {
            return $this->plausibilityCache;
        }

        $teamId   = $this->teamId();
        $tenantId = TenantContext::scopeTenantId() ?? 0;

        return $this->plausibilityCache = Cache::remember(
            "am:cost-plausibility:{$teamId}:{$tenantId}",
            now()->addSeconds(120),
            function () use ($teamId): array {
                $result = app(CostPlausibilityService::class)->check($teamId);

                $map = [];
                foreach ($result['findings'] as $finding) {
                    foreach ($finding['line_ids'] as $lineId) {
                        $map[(int) $lineId][] = $finding['title'];
                    }
                }

                return ['map' => $map, 'monthly' => (float) $result['total_monthly_flagged']];
            },
        );
    }

    /** Nach jeder Korrektur verwerfen - eine veraltete Warnung ist schlimmer als keine. */
    protected function forgetPlausibility(): void
    {
        $tenantId = TenantContext::scopeTenantId() ?? 0;
        Cache::forget("am:cost-plausibility:{$this->teamId()}:{$tenantId}");
        $this->plausibilityCache = null;
    }

    /**
     * Kennzahlen der gefilterten Menge in EINER Query.
     *
     * Aufwand und Gutschriften werden getrennt ausgewiesen: die alte Kopfzeile zeigte nur das Netto
     * und nannte es "Summe" - dass darin eine Gutschrift verrechnet ist, war nirgends sichtbar.
     * once-Positionen haben monthly_amount = 0 und bleiben ein eigener Topf.
     *
     * @return array{count:int, gross:float, credits:float, net:float, once:float}
     */
    protected function kpis(): array
    {
        $row = $this->baseQuery()->selectRaw(
            'COUNT(*) as cnt, '
            . 'SUM(CASE WHEN asset_cost_lines.monthly_amount > 0 THEN asset_cost_lines.monthly_amount ELSE 0 END) as gross, '
            . 'SUM(CASE WHEN asset_cost_lines.monthly_amount < 0 THEN asset_cost_lines.monthly_amount ELSE 0 END) as credits, '
            . "SUM(CASE WHEN asset_cost_lines.frequency = 'once' THEN asset_cost_lines.amount ELSE 0 END) as once_sum"
        )->first();

        // DECIMAL kommt als String aus PDO -> explizit casten.
        $gross   = round((float) ($row->gross ?? 0), 2);
        $credits = round((float) ($row->credits ?? 0), 2);

        return [
            'count'   => (int) ($row->cnt ?? 0),
            'gross'   => $gross,
            'credits' => $credits,
            'net'     => round($gross + $credits, 2),
            'once'    => round((float) ($row->once_sum ?? 0), 2),
        ];
    }

    /**
     * Filter auf die Bezugsgroesse der Kostenaufteilung setzen (aktiv + heute gueltig).
     *
     * CostAggregationService::normalizedLines() zaehlt nur active()->validOn(now()); ohne diese
     * Einschraenkung zeigt diese Seite eine andere Summe als /costs, ohne dass man es merkt.
     */
    public function applyReconcilePreset(): void
    {
        $this->filterActive   = '1';
        $this->filterValidity = 'current';
        $this->resetPage();
    }

    /** Steht die Liste gerade auf der Bezugsgroesse der Kostenaufteilung? */
    public function isReconciled(): bool
    {
        return $this->filterActive === '1' && $this->filterValidity === 'current';
    }

    public function render()
    {
        $teamId   = $this->teamId();
        $tenantId = TenantContext::scopeTenantId();

        $kpi     = $this->kpis();
        $flagged = $this->plausibility();

        $groups         = collect();
        $groupsOverflow = false;
        $openLines      = collect();
        $openLinesTotal = 0;
        $lines          = null;

        if ($this->view === 'grouped') {
            $raw            = $this->groupRows();
            $groupsOverflow = $raw->count() > self::MAX_GROUPS;
            $groups         = $this->decorateGroups($raw, $flagged['map'])->take(self::MAX_GROUPS);

            $openLines = $this->openGroupLines();
            if ($this->openGroup !== null) {
                $openLinesTotal = $this->applyAxisFilter($this->baseQuery(), $this->openGroup)->count();
            }
        } else {
            $query = $this->baseQuery()
                ->with(['costType', 'costCenter', 'vendor', 'assignee'])
                ->select('asset_cost_lines.*');
            $this->applySort($query);

            $lines = $query->paginate($this->perPage);
        }

        return view('asset-manager::livewire.cost-lines.index', [
            'lines'          => $lines,
            'groups'         => $groups,
            'groupsOverflow' => $groupsOverflow,
            'openLines'      => $openLines,
            'openLinesTotal' => $openLinesTotal,
            'axes'           => self::AXES,
            'detailLimit'    => self::GROUP_DETAIL_LIMIT,
            'costTypes'     => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->get(),
            // Baum-sortiert mit tree_label: die numerischen Kostenstellen haben kein `name`, als
            // reine Code-Liste ("gf-gl, 1000, 1900, …") war der Filter nicht benutzbar.
            'costCenters'   => $tenantId !== null
                ? AssetCostCenter::treeFor($tenantId)
                : AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get(),
            'vendors'       => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
            'kpi'            => $kpi,
            'flaggedMap'     => $flagged['map'],
            'flaggedMonthly' => $flagged['monthly'],
            'totalFiltered'  => $kpi['count'],
            'hasFilters'     => $this->hasFilters(),
            'isReconciled'   => $this->isReconciled(),
        ])->layout('platform::layouts.app');
    }
}
