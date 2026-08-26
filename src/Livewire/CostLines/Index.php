<?php

namespace Platform\AssetManager\Livewire\CostLines;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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
use Platform\AssetManager\Services\MasterDataDeletionService;
use Platform\AssetManager\Services\TenantContext;

class Index extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;

    public string $search        = '';
    public ?int   $filterType     = null;
    public ?int   $filterCenter   = null;
    public ?int   $filterVendor   = null;
    public string $filterActive   = '';  // ''|'1'|'0'
    public string $filterSource   = '';  // ''|manual|excel_import|graph
    public string $filterFreq     = '';  // ''|monthly|quarterly|yearly|once
    public string $filterHolder   = '';  // ''|with|without
    public string $filterValidity = '';  // ''|current|future|expired|unlimited
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

    protected $queryString = [
        'search'       => ['except' => ''],
        'filterType'   => ['except' => null],
        'filterCenter' => ['except' => null],
        'filterVendor' => ['except' => null],
        'filterActive' => ['except' => ''],
        'filterSource' => ['except' => ''],
        'filterFreq'   => ['except' => ''],
        'filterHolder' => ['except' => ''],
        'filterValidity' => ['except' => ''],
        'sortField'    => ['except' => 'monthly_amount'],
        'sortDirection'=> ['except' => 'desc'],
    ];

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterType(): void   { $this->resetPage(); }
    public function updatingFilterCenter(): void { $this->resetPage(); }
    public function updatingFilterVendor(): void { $this->resetPage(); }
    public function updatingFilterActive(): void { $this->resetPage(); }
    public function updatingFilterSource(): void { $this->resetPage(); }
    public function updatingFilterFreq(): void   { $this->resetPage(); }
    public function updatingFilterHolder(): void { $this->resetPage(); }
    public function updatingFilterValidity(): void { $this->resetPage(); }

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
            'filterSource', 'filterFreq', 'filterHolder', 'filterValidity',
        ]);
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
        $this->flash = $result['message'];
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('asset-manager.manage');

        $line = AssetCostLine::where('team_id', $this->teamId())->findOrFail($id);
        $line->update(['active' => !$line->active]);
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
            || $this->filterValidity !== '';
    }

    public function render()
    {
        $teamId   = $this->teamId();
        $tenantId = TenantContext::scopeTenantId();

        $monthlySum    = round((float) $this->baseQuery()->sum('monthly_amount'), 2);
        // Einmalkosten separat (auditierbar): once-Positionen haben monthly_amount=0 und fliessen NICHT
        // in die Monatssumme — ihr Rohbetrag wuerde sonst still verschwinden. Hier als eigener Topf.
        $oneTimeSum    = round((float) $this->baseQuery()->where('asset_cost_lines.frequency', 'once')->sum('amount'), 2);
        $totalFiltered = $this->baseQuery()->count();

        $query = $this->baseQuery()
            ->with(['costType', 'costCenter', 'vendor', 'assignee'])
            ->select('asset_cost_lines.*');
        $this->applySort($query);

        $lines = $query->paginate($this->perPage);

        return view('asset-manager::livewire.cost-lines.index', [
            'lines'         => $lines,
            'costTypes'     => AssetCostType::where('team_id', $teamId)->orderBy('sort_order')->get(),
            // Baum-sortiert mit tree_label: die numerischen Kostenstellen haben kein `name`, als
            // reine Code-Liste ("gf-gl, 1000, 1900, …") war der Filter nicht benutzbar.
            'costCenters'   => $tenantId !== null
                ? AssetCostCenter::treeFor($tenantId)
                : AssetCostCenter::where('team_id', $teamId)->orderBy('code')->get(),
            'vendors'       => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
            'monthlySum'    => $monthlySum,
            'oneTimeSum'    => $oneTimeSum,
            'totalFiltered' => $totalFiltered,
            'hasFilters'    => $this->hasFilters(),
        ])->layout('platform::layouts.app');
    }
}
