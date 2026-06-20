<?php

namespace Platform\AssetManager\Livewire\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCompany;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\CostBootstrapService;

/**
 * Stammdaten-Sammelseite mit Master-Detail-Layout (wie Geräte/Mitarbeiter):
 * links Bereichs-Navigation + Filter, Mitte read-only Liste des aktiven Bereichs,
 * rechts das Bearbeiten-/Anlegen-Panel. Eine Komponente besitzt alle vier
 * kostenaufteilungs-relevanten Stammdaten (Gesellschaften, Kostenstellen,
 * Kostenarten, Kreditoren) inkl. CRUD — die Slots von <x-ui-page> lassen sich
 * nicht aus verschachtelten Kind-Komponenten bespielen.
 */
class Index extends Component
{
    use ResolvesCurrentTeam;

    /** Aktiver Bereich; per #[Url] deep-linkbar (?bereich=...). */
    #[Url(as: 'bereich')]
    public string $active = 'companies';

    public string $search = '';

    // bereichsspezifische Filter
    public ?int   $filterCompany = null;  // Kostenstellen: nach Gesellschaft
    public bool   $onlyActive    = false; // Kostenstellen: nur aktive
    public string $filterSource  = '';    // Kostenarten: nach Quelle (aggregation_source)

    // Auswahl / Bearbeiten (rechtes Panel)
    public ?int  $selectedId = null;
    public bool  $creating   = false;
    public array $form       = [];

    // Kostenarten-Ansicht: Sortierung
    public string $sortField = 'sort_order';
    public string $sortDir   = 'asc';

    public ?string $flash = null;

    protected const AREAS = ['companies', 'cost-centers', 'cost-types', 'vendors'];

    /** Whitelist erlaubter Sortierspalten für Kostenarten (Schutz vor beliebigem orderBy). */
    protected const CT_SORTABLE = ['sort_order', 'name', 'frequency_default', 'aggregation_source', 'cost_lines_count'];

    public function mount(): void
    {
        if (! in_array($this->active, self::AREAS, true)) {
            $this->active = 'companies';
        }
    }

    // ---- Navigation -------------------------------------------------------

    public function setActive(string $area): void
    {
        if (! in_array($area, self::AREAS, true)) {
            return;
        }
        $this->active = $area;
        $this->reset(['search', 'filterCompany', 'onlyActive', 'filterSource']);
        $this->resetSelection();
        $this->flash = null;
    }

    protected function resetSelection(): void
    {
        $this->selectedId = null;
        $this->creating   = false;
        $this->form       = [];
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->resetSelection();
    }

    // ---- Auswahl / Formular ----------------------------------------------

    public function selectRow(int $id): void
    {
        $this->resetValidation();
        $model = $this->findInActiveArea($id);
        $this->form       = $this->formFromModel($model);
        $this->selectedId = $id;
        $this->creating   = false;
        $this->dispatch('open-activity');
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->form       = $this->blankForm();
        $this->selectedId = null;
        $this->creating   = true;
        $this->dispatch('open-activity');
    }

    protected function modelClass(): string
    {
        return match ($this->active) {
            'companies'    => AssetCompany::class,
            'cost-centers' => AssetCostCenter::class,
            'cost-types'   => AssetCostType::class,
            'vendors'      => AssetVendor::class,
        };
    }

    protected function findInActiveArea(int $id): Model
    {
        $class = $this->modelClass();

        return $class::where('team_id', $this->teamId())->findOrFail($id);
    }

    protected function blankForm(): array
    {
        return match ($this->active) {
            'companies'    => ['name' => '', 'sort_order' => null],
            'cost-centers' => ['code' => '', 'name' => '', 'company_id' => null, 'is_active' => true],
            'cost-types'   => ['name' => '', 'vendor_default_id' => null, 'system_default' => '', 'frequency_default' => 'monthly', 'aggregation_source' => AssetCostType::SOURCE_COST_LINE, 'is_per_employee' => false],
            'vendors'      => ['name' => '', 'creditor_no' => ''],
        };
    }

    protected function formFromModel(Model $m): array
    {
        return match ($this->active) {
            'companies'    => ['name' => $m->name, 'sort_order' => $m->sort_order],
            'cost-centers' => ['code' => $m->code, 'name' => $m->name ?? '', 'company_id' => $m->company_id, 'is_active' => (bool) $m->is_active],
            'cost-types'   => ['name' => $m->name, 'vendor_default_id' => $m->vendor_default_id, 'system_default' => $m->system_default ?? '', 'frequency_default' => $m->frequency_default, 'aggregation_source' => $m->aggregation_source, 'is_per_employee' => (bool) $m->is_per_employee],
            'vendors'      => ['name' => $m->name, 'creditor_no' => $m->creditor_no ?? ''],
        };
    }

    // ---- Validierung ------------------------------------------------------

    protected function rulesFor(string $area): array
    {
        return match ($area) {
            'companies' => [
                'form.name'       => 'required|string|max:255',
                'form.sort_order' => 'nullable',
            ],
            'cost-centers' => [
                'form.code'       => 'required|string|max:50',
                'form.name'       => 'nullable|string|max:255',
                // company_id muss zum eigenen Team gehören (kein fremder/danglender FK, der die
                // Kostenstelle aus dem Pivot fallen lassen würde — s. costCenterByType-Auffangblock).
                'form.company_id' => ['nullable', 'integer', Rule::exists('asset_companies', 'id')->where('team_id', $this->teamId())],
                'form.is_active'  => 'boolean',
            ],
            'cost-types' => [
                'form.name'               => 'required|string|max:255',
                'form.vendor_default_id'  => 'nullable',
                'form.system_default'     => 'nullable|in:HGK,Moss',
                'form.frequency_default'  => 'required|in:monthly,quarterly,yearly,once',
                'form.aggregation_source' => ['required', Rule::in(AssetCostType::SOURCES)],
                'form.is_per_employee'    => 'boolean',
            ],
            'vendors' => [
                'form.name'        => 'required|string|max:255',
                'form.creditor_no' => 'nullable|string|max:255',
            ],
        };
    }

    // ---- Speichern --------------------------------------------------------

    public function save(): void
    {
        // Leeren Select-Wert ('') zu null normalisieren, damit nullable|integer|exists greift
        // (ein '' würde sonst an der integer-Regel scheitern, obwohl „keine Gesellschaft" gültig ist).
        if (array_key_exists('company_id', $this->form) && $this->form['company_id'] === '') {
            $this->form['company_id'] = null;
        }

        $this->validate($this->rulesFor($this->active));

        // Single-Source-Guard: pro Team nur EINE hardware_afa- bzw. ms_license-Kostenart. normalizedLines
        // nutzt firstWhere(aggregation_source) → eine zweite würde stillschweigend nie ausgewertet. Bei
        // Verstoß abbrechen (Panel bleibt offen). asset_device ist erlaubt (Aggregation nutzt pluck).
        if ($this->active === 'cost-types' && ! $this->assertSingleSourceCostType()) {
            return;
        }

        match ($this->active) {
            'companies'    => $this->saveCompany(),
            'cost-centers' => $this->saveCostCenter(),
            'cost-types'   => $this->saveCostType(),
            'vendors'      => $this->saveVendor(),
        };

        $this->resetSelection();
    }

    /** Verhindert eine zweite hardware_afa/ms_license-Kostenart pro Team. false = Verstoß (mit Flash). */
    protected function assertSingleSourceCostType(): bool
    {
        $source = $this->form['aggregation_source'] ?? null;
        if (! in_array($source, [AssetCostType::SOURCE_HARDWARE_AFA, AssetCostType::SOURCE_MS_LICENSE], true)) {
            return true;
        }

        $exists = AssetCostType::where('team_id', $this->teamId())
            ->where('aggregation_source', $source)
            ->when($this->selectedId, fn ($q) => $q->where('id', '!=', $this->selectedId))
            ->exists();

        if ($exists) {
            $this->flash = "Es gibt bereits eine Kostenart mit Quelle '{$source}'. Pro Team ist nur EINE {$source}-Kostenart zulässig (eine zweite würde nie ausgewertet) — bitte die bestehende bearbeiten.";
            return false;
        }

        return true;
    }

    protected function saveCompany(): void
    {
        $teamId  = $this->teamId();
        $name    = trim((string) $this->form['name']);
        $sortRaw = $this->form['sort_order'] ?? null;
        $sort    = ($sortRaw === null || $sortRaw === '') ? null : (int) $sortRaw;

        if ($this->selectedId) {
            $co = AssetCompany::where('team_id', $teamId)->findOrFail($this->selectedId);
            $co->update([
                'name'       => $name,
                'sort_order' => $sort ?? 100,
            ]);
            $this->flash = 'Gesellschaft gespeichert.';

            return;
        }

        AssetCompany::create([
            'team_id'    => $teamId,
            'key'        => $this->uniqueCompanyKey($teamId, $name),
            'name'       => $name,
            'sort_order' => $sort ?? (int) ((AssetCompany::where('team_id', $teamId)->max('sort_order') ?? 0) + 10),
        ]);
        $this->flash = 'Gesellschaft angelegt.';
    }

    protected function saveCostCenter(): void
    {
        $teamId = $this->teamId();

        if ($this->selectedId) {
            $cc = AssetCostCenter::where('team_id', $teamId)->findOrFail($this->selectedId);
            $cc->update([
                'name'       => ($this->form['name'] ?? '') ?: null,
                'company_id' => $this->form['company_id'] ?: null,
                'is_active'  => (bool) ($this->form['is_active'] ?? true),
            ]);
            $this->flash = 'Kostenstelle gespeichert.';

            return;
        }

        AssetCostCenter::firstOrCreate(
            ['team_id' => $teamId, 'code' => trim((string) $this->form['code'])],
            [
                'name'       => ($this->form['name'] ?? '') ?: null,
                'company_id' => $this->form['company_id'] ?: null,
                'is_active'  => (bool) ($this->form['is_active'] ?? true),
            ]
        );
        $this->flash = 'Kostenstelle angelegt.';
    }

    protected function saveCostType(): void
    {
        $teamId = $this->teamId();
        $name   = trim((string) $this->form['name']);

        $attrs = [
            'name'               => $name,
            'vendor_default_id'  => $this->form['vendor_default_id'] ?: null,
            'system_default'     => ($this->form['system_default'] ?? '') ?: null,
            'frequency_default'  => $this->form['frequency_default'],
            'aggregation_source' => $this->form['aggregation_source'],
            'is_per_employee'    => (bool) ($this->form['is_per_employee'] ?? false),
        ];

        if ($this->selectedId) {
            $t = AssetCostType::where('team_id', $teamId)->findOrFail($this->selectedId);
            $t->update($attrs);
            $this->flash = 'Kostenart gespeichert.';

            return;
        }

        AssetCostType::create(array_merge($attrs, [
            'team_id'    => $teamId,
            'key'        => $this->uniqueCostTypeKey($teamId, $name),
            'sort_order' => (int) ((AssetCostType::where('team_id', $teamId)->max('sort_order') ?? 0) + 10),
        ]));
        $this->flash = 'Kostenart angelegt.';
    }

    protected function saveVendor(): void
    {
        $teamId = $this->teamId();
        $name   = trim((string) $this->form['name']);

        if ($this->selectedId) {
            $v = AssetVendor::where('team_id', $teamId)->findOrFail($this->selectedId);
            $v->update([
                'name'        => $name,
                'creditor_no' => ($this->form['creditor_no'] ?? '') ?: null,
            ]);
            $this->flash = 'Kreditor gespeichert.';

            return;
        }

        $v = AssetVendor::firstOrCreate(
            ['team_id' => $teamId, 'name' => $name],
            ['creditor_no' => ($this->form['creditor_no'] ?? '') ?: null]
        );
        $this->flash = 'Kreditor angelegt.';
    }

    // ---- Löschen ----------------------------------------------------------

    public function delete(int $id): void
    {
        match ($this->active) {
            'companies'    => $this->deleteCompany($id),
            'cost-centers' => $this->deleteCostCenter($id),
            'cost-types'   => $this->deleteCostType($id),
            'vendors'      => $this->deleteVendor($id),
        };
    }

    protected function deleteCompany(int $id): void
    {
        $co   = AssetCompany::where('team_id', $this->teamId())->findOrFail($id);
        $name = $co->name;
        // company_id an Kostenstellen ist nullOnDelete → Zuordnungen werden entfernt, Kostenstellen bleiben.
        $co->delete();
        if ($this->selectedId === $id) {
            $this->resetSelection();
        }
        $this->flash = "Gesellschaft {$name} gelöscht (Kostenstellen-Zuordnungen entfernt).";
    }

    protected function deleteCostCenter(int $id): void
    {
        $cc   = AssetCostCenter::where('team_id', $this->teamId())->findOrFail($id);
        $code = $cc->code;
        // FKs sind nullOnDelete → Zuordnungen an Mitarbeitern/Kostenpositionen werden entfernt, nicht gelöscht.
        $cc->delete();
        if ($this->selectedId === $id) {
            $this->resetSelection();
        }
        $this->flash = "Kostenstelle {$code} gelöscht (Zuordnungen wurden entfernt).";
    }

    /** Löschen nur, wenn keine Positionen dranhängen — cost_type_id ist cascadeOnDelete (sonst stiller Datenverlust). */
    protected function deleteCostType(int $id): void
    {
        $t = AssetCostType::where('team_id', $this->teamId())->withCount('costLines')->findOrFail($id);
        if ($t->cost_lines_count > 0) {
            $this->flash = "Kostenart {$t->name} hat {$t->cost_lines_count} Position(en) — erst dort umbuchen oder löschen, dann ist die Kostenart löschbar.";

            return;
        }

        // Virtuelle Quellen (hardware_afa/ms_license/asset_device) haben NIE cost_lines (cost_lines_count=0),
        // tragen ihre Kosten aber aus Inventar-AfA / bepreisten Lizenz-Zuweisungen / Geräten. Löschen würde
        // diese Beträge still aus dem Pivot kippen → blockieren, solange die Kostenart aktiv Kosten trägt.
        if (in_array($t->aggregation_source, [AssetCostType::SOURCE_HARDWARE_AFA, AssetCostType::SOURCE_MS_LICENSE, AssetCostType::SOURCE_ASSET_DEVICE], true)) {
            $contributes = app(CostAggregationService::class)
                ->normalizedLines($this->teamId())
                ->contains(fn ($l) => (int) $l['cost_type_id'] === (int) $t->id && (float) $l['amount'] != 0.0);
            if ($contributes) {
                $this->flash = "Kostenart {$t->name} (Quelle: {$t->aggregation_source}) trägt aktuell Kosten aus Geräten/Inventar/Lizenzen — diese erst entkoppeln oder umbuchen, dann ist die Kostenart löschbar.";

                return;
            }
        }

        $name = $t->name;
        $t->delete();
        if ($this->selectedId === $id) {
            $this->resetSelection();
        }
        $this->flash = "Kostenart {$name} gelöscht.";
    }

    protected function deleteVendor(int $id): void
    {
        $v    = AssetVendor::where('team_id', $this->teamId())->findOrFail($id);
        $name = $v->name;
        // vendor_id (Kostenpositionen) und vendor_default_id (Kostenarten) sind nullOnDelete →
        // Zuordnungen werden entfernt, Positionen/Kostenarten bleiben erhalten.
        $v->delete();
        if ($this->selectedId === $id) {
            $this->resetSelection();
        }
        $this->flash = "Kreditor {$name} gelöscht (Zuordnungen wurden entfernt).";
    }

    // ---- Kostenarten-Extras ----------------------------------------------

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::CT_SORTABLE, true)) {
            return;
        }
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir   = 'asc';
        }
    }

    /** Drag&Drop-Reihenfolge (wotz/livewire-sortablejs). Renummeriert sort_order sauber (10,20,30…). */
    public function reorder(array $order): void
    {
        $teamId = $this->teamId();
        $pos    = 0;
        foreach ($this->flattenOrder($order) as $id) {
            AssetCostType::where('team_id', $teamId)->where('id', (int) $id)
                ->update(['sort_order' => (++$pos) * 10]);
        }
        $this->flash = 'Reihenfolge aktualisiert.';
    }

    /** wotz/livewire-sortablejs liefert ggf. [['order'=>0,'value'=>'5'], …] oder flach. */
    protected function flattenOrder(array $order): array
    {
        $flat = array_map(fn ($i) => is_array($i) ? ($i['value'] ?? null) : $i, $order);

        return array_values(array_filter($flat, fn ($v) => $v !== null && $v !== ''));
    }

    /** Neutrale Standard-Kostenarten als Starthilfe laden (idempotent, keine Firmenspezifika). */
    public function seedDefaults(CostBootstrapService $bootstrap): void
    {
        $bootstrap->seedForTeam($this->teamId());
        $this->flash = 'Standard-Kostenarten geladen.';
    }

    // ---- Slug-Keys --------------------------------------------------------

    /** Eindeutigen Slug-Key je Team aus dem Namen ableiten (key ist intern, NOT NULL). */
    protected function uniqueCompanyKey(int $teamId, string $name): string
    {
        $base = Str::slug($name) ?: 'gesellschaft';
        $key  = $base;
        $i    = 2;
        while (AssetCompany::where('team_id', $teamId)->where('key', $key)->exists()) {
            $key = $base . '-' . $i++;
        }

        return $key;
    }

    /** Eindeutigen Slug-Key je Team aus dem Namen ableiten (key ist intern, NOT NULL). */
    protected function uniqueCostTypeKey(int $teamId, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'kostenart';
        $key  = $base;
        $i    = 2;
        while (AssetCostType::where('team_id', $teamId)->where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }

    // ---- Daten (Computed) -------------------------------------------------

    #[Computed]
    public function companies()
    {
        return AssetCompany::where('team_id', $this->teamId())
            ->withCount('costCenters')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    #[Computed]
    public function costCenters()
    {
        return AssetCostCenter::where('team_id', $this->teamId())
            ->withCount('employees')
            ->with('company')
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', '%' . $this->search . '%')
                ->orWhere('name', 'like', '%' . $this->search . '%')))
            ->when($this->filterCompany, fn ($q) => $q->where('company_id', $this->filterCompany))
            ->when($this->onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function costTypes()
    {
        return AssetCostType::where('team_id', $this->teamId())
            ->withCount('costLines')
            ->with('vendorDefault')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->when($this->filterSource, fn ($q) => $q->where('aggregation_source', $this->filterSource))
            ->orderBy($this->sortField, $this->sortDir)
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function vendors()
    {
        return AssetVendor::where('team_id', $this->teamId())
            ->withCount('costLines')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $teamId = $this->teamId();

        return [
            'companies'    => AssetCompany::where('team_id', $teamId)->count(),
            'cost-centers' => AssetCostCenter::where('team_id', $teamId)->count(),
            'cost-types'   => AssetCostType::where('team_id', $teamId)->count(),
            'vendors'      => AssetVendor::where('team_id', $teamId)->count(),
        ];
    }

    #[Computed]
    public function companiesForSelect()
    {
        return AssetCompany::where('team_id', $this->teamId())
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function vendorsForSelect()
    {
        return AssetVendor::where('team_id', $this->teamId())->orderBy('name')->get();
    }

    #[Computed]
    public function manualOrder(): bool
    {
        return $this->sortField === 'sort_order' && $this->sortDir === 'asc';
    }

    public function render()
    {
        return view('asset-manager::livewire.master-data.index')
            ->layout('platform::layouts.app');
    }
}
