<?php

namespace Platform\AssetManager\Livewire\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\CostBootstrapService;
use Platform\AssetManager\Services\TenantContext;

/**
 * Stammdaten-Sammelseite mit Master-Detail-Layout (wie Geräte/Asset-Träger):
 * links Bereichs-Navigation + Filter, Mitte read-only Liste des aktiven Bereichs,
 * rechts das Bearbeiten-/Anlegen-Panel. Eine Komponente besitzt alle drei
 * kostenaufteilungs-relevanten Stammdaten-Bereiche (Kostenstellen, Kostenarten,
 * Kreditoren) inkl. CRUD — die Slots von <x-ui-page> lassen sich nicht aus
 * verschachtelten Kind-Komponenten bespielen.
 *
 * Seit docs/adr/0016: der Bereich „Gesellschaften" ist entfallen. Gesellschaften sind die
 * **Wurzelknoten** des Kostenstellen-Baums; die Kostenstellen-Liste zeigt daher einen Baum mit
 * Einrückung, Umhängen (`moveUnder`) und Reihenfolge je Ebene. Alle Stammdaten gehören dem aktiven
 * Tenant — der Global Scope filtert, hier steht kein `tenant_id`-Filter mehr im Code.
 */
class Index extends Component
{
    use ResolvesCurrentTeam;

    /** Aktiver Bereich; per #[Url] deep-linkbar (?bereich=...). */
    #[Url(as: 'bereich')]
    public string $active = 'cost-centers';

    public string $search = '';

    // bereichsspezifische Filter
    public ?int   $filterParent = null;  // Kostenstellen: Teilbaum unter diesem Knoten
    public bool   $onlyActive   = false; // Kostenstellen: nur aktive
    public string $filterSource = '';    // Kostenarten: nach Quelle (aggregation_source)

    // Auswahl / Bearbeiten (rechtes Panel)
    public ?int  $selectedId = null;
    public bool  $creating   = false;
    /** Sichtbarkeit des Editor-Modals (S8b) — von selectRow()/startCreate() gesetzt. */
    public bool  $showEditor = false;
    public array $form       = [];

    // Kostenarten-Ansicht: Sortierung
    public string $sortField = 'sort_order';
    public string $sortDir   = 'asc';

    public ?string $flash = null;

    protected const AREAS = ['cost-centers', 'cost-types', 'vendors'];

    /** Whitelist erlaubter Sortierspalten für Kostenarten (Schutz vor beliebigem orderBy). */
    protected const CT_SORTABLE = ['sort_order', 'name', 'frequency_default', 'aggregation_source', 'cost_lines_count'];

    public function mount(): void
    {
        // 'companies' war bis ADR 0016 ein eigener Bereich — alte Deep-Links (?bereich=companies)
        // landen jetzt auf dem Kostenstellen-Baum, in dem die Gesellschaften aufgegangen sind.
        if (! in_array($this->active, self::AREAS, true)) {
            $this->active = 'cost-centers';
        }
    }

    // ---- Navigation -------------------------------------------------------

    public function setActive(string $area): void
    {
        if (! in_array($area, self::AREAS, true)) {
            return;
        }
        $this->active = $area;
        $this->reset(['search', 'filterParent', 'onlyActive', 'filterSource']);
        $this->resetSelection();
        $this->flash = null;
    }

    protected function resetSelection(): void
    {
        $this->selectedId = null;
        $this->creating   = false;
        $this->showEditor = false;
        $this->form       = [];
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->resetSelection();
    }

    /**
     * Das Editor-Modal ist per entangle an $showEditor gebunden — Backdrop-Klick und ESC setzen die
     * Property direkt. Ohne diesen Hook bliebe die Auswahl (und damit ein halb gefülltes Formular)
     * unsichtbar bestehen.
     */
    public function updatedShowEditor(bool $value): void
    {
        if (! $value) {
            $this->resetSelection();
        }
    }

    // ---- Auswahl / Formular ----------------------------------------------

    public function selectRow(int $id): void
    {
        $this->resetValidation();
        $model = $this->findInActiveArea($id);
        $this->form       = $this->formFromModel($model);
        $this->selectedId = $id;
        $this->creating   = false;
        $this->showEditor = true;
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->form       = $this->blankForm();
        $this->selectedId = null;
        $this->creating   = true;
        $this->showEditor = true;
    }

    protected function modelClass(): string
    {
        return match ($this->active) {
            'cost-centers' => AssetCostCenter::class,
            'cost-types'   => AssetCostType::class,
            'vendors'      => AssetVendor::class,
        };
    }

    protected function findInActiveArea(int $id): Model
    {
        $class = $this->modelClass();

        // Team-Check bleibt; der Tenant-Filter kommt vom Global Scope (ADR 0016) → ein Datensatz
        // eines fremden Tenants ist hier nicht auffindbar und läuft in findOrFail (404).
        return $class::where('team_id', $this->teamId())->findOrFail($id);
    }

    protected function blankForm(): array
    {
        return match ($this->active) {
            'cost-centers' => ['code' => '', 'name' => '', 'parent_id' => null, 'is_active' => true],
            'cost-types'   => ['name' => '', 'vendor_default_id' => null, 'system_default' => '', 'frequency_default' => 'monthly', 'aggregation_source' => AssetCostType::SOURCE_COST_LINE, 'is_per_employee' => false],
            'vendors'      => ['name' => '', 'creditor_no' => ''],
        };
    }

    protected function formFromModel(Model $m): array
    {
        return match ($this->active) {
            'cost-centers' => ['code' => $m->code, 'name' => $m->name ?? '', 'parent_id' => $m->parent_id, 'is_active' => (bool) $m->is_active],
            'cost-types'   => ['name' => $m->name, 'vendor_default_id' => $m->vendor_default_id, 'system_default' => $m->system_default ?? '', 'frequency_default' => $m->frequency_default, 'aggregation_source' => $m->aggregation_source, 'is_per_employee' => (bool) $m->is_per_employee],
            'vendors'      => ['name' => $m->name, 'creditor_no' => $m->creditor_no ?? ''],
        };
    }

    // ---- Validierung ------------------------------------------------------

    protected function rulesFor(string $area): array
    {
        return match ($area) {
            'cost-centers' => [
                'form.code' => 'required|string|max:50',
                'form.name' => 'nullable|string|max:255',
                // parent_id muss eine Kostenstelle DESSELBEN Tenants sein (kein tenant-fremder oder
                // danglender FK, der den Knoten aus dem Pivot-Baum fallen lassen würde — siehe den
                // Auffangblock „Unbekannte Kostenstelle" in costCenterByType).
                'form.parent_id' => [
                    'nullable', 'integer',
                    Rule::exists('asset_cost_centers', 'id')->where('tenant_id', $this->activeTenantId()),
                ],
                'form.is_active' => 'boolean',
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
        Gate::authorize('asset-manager.manage');

        // Leeren Select-Wert ('') zu null normalisieren, damit nullable|integer|exists greift
        // (ein '' würde sonst an der integer-Regel scheitern, obwohl „kein Eltern-Knoten" gültig ist).
        if (array_key_exists('parent_id', $this->form) && $this->form['parent_id'] === '') {
            $this->form['parent_id'] = null;
        }

        $this->validate($this->rulesFor($this->active));

        // Single-Source-Guard: pro Tenant nur EINE hardware_afa- bzw. ms_license-Kostenart.
        // normalizedLines nutzt firstWhere(aggregation_source) → eine zweite würde stillschweigend
        // nie ausgewertet. Bei Verstoß abbrechen (Panel bleibt offen). asset_device ist erlaubt.
        if ($this->active === 'cost-types' && ! $this->assertSingleSourceCostType()) {
            return;
        }

        match ($this->active) {
            'cost-centers' => $this->saveCostCenter(),
            'cost-types'   => $this->saveCostType(),
            'vendors'      => $this->saveVendor(),
        };

        $this->resetSelection();
    }

    /** Verhindert eine zweite hardware_afa/ms_license-Kostenart pro Tenant. false = Verstoß (mit Flash). */
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
            $this->flash = "Es gibt bereits eine Kostenart mit Quelle '{$source}'. Pro Tenant ist nur EINE {$source}-Kostenart zulässig (eine zweite würde nie ausgewertet) — bitte die bestehende bearbeiten.";

            return false;
        }

        return true;
    }

    protected function saveCostCenter(): void
    {
        $teamId   = $this->teamId();
        $parentId = $this->form['parent_id'] ?: null;

        if ($this->selectedId) {
            $cc = AssetCostCenter::where('team_id', $teamId)->findOrFail($this->selectedId);

            // Umhängen prüfen: sich selbst oder einen eigenen Nachfahren als Eltern zu setzen würde
            // einen Zyklus erzeugen und Rollup wie Baum-Ausgabe in eine Endlosschleife schicken.
            if ($parentId !== (int) $cc->parent_id && ! $cc->canMoveUnder($parentId)) {
                $this->flash = 'Diese Einordnung würde einen Zyklus erzeugen (der Ziel-Knoten liegt unterhalb dieser Kostenstelle) — bitte einen anderen Eltern-Knoten wählen.';

                return;
            }

            $cc->update([
                'name'      => ($this->form['name'] ?? '') ?: null,
                'parent_id' => $parentId,
                'is_active' => (bool) ($this->form['is_active'] ?? true),
            ]);

            $this->recomputeSubtreeDepths($cc);
            $this->flash = 'Kostenstelle gespeichert.';

            return;
        }

        $center = AssetCostCenter::firstOrCreate(
            ['team_id' => $teamId, 'tenant_id' => $this->activeTenantId(), 'code' => trim((string) $this->form['code'])],
            [
                'name'      => ($this->form['name'] ?? '') ?: null,
                'parent_id' => $parentId,
                'is_active' => (bool) ($this->form['is_active'] ?? true),
            ]
        );

        $center->recomputeDepth();
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
            'tenant_id'  => $this->activeTenantId(),
            'key'        => $this->uniqueCostTypeKey($name),
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

        AssetVendor::firstOrCreate(
            ['team_id' => $teamId, 'tenant_id' => $this->activeTenantId(), 'name' => $name],
            ['creditor_no' => ($this->form['creditor_no'] ?? '') ?: null]
        );
        $this->flash = 'Kreditor angelegt.';
    }

    // ---- Baum: Umhängen ---------------------------------------------------

    /**
     * Kostenstelle unter einen anderen Knoten hängen (Drag&Drop im Baum bzw. „nach oben"-Aktion).
     * `null` macht sie zur Wurzel — das ist der Weg, aus einer Kostenstelle eine Gesellschaft zu machen.
     */
    public function moveUnder(int $id, ?int $parentId = null): void
    {
        Gate::authorize('asset-manager.manage');

        $node = AssetCostCenter::where('team_id', $this->teamId())->findOrFail($id);

        if (! $node->canMoveUnder($parentId)) {
            $this->flash = 'Umhängen nicht möglich: das Ziel liegt unterhalb dieser Kostenstelle (Zyklus) oder gehört zu einem anderen Tenant.';

            return;
        }

        $node->parent_id = $parentId;
        $node->save();

        $this->recomputeSubtreeDepths($node);

        $this->flash = $parentId === null
            ? "Kostenstelle {$node->code} ist jetzt ein oberster Knoten."
            : "Kostenstelle {$node->code} umgehängt.";
    }

    /**
     * `depth` des Knotens und aller Nachfahren neu berechnen. Nach dem Umhängen wandert ein ganzer
     * Teilbaum — würde nur der Knoten selbst aktualisiert, stünden seine Kinder mit falscher Einrückung
     * und falscher Rollup-Zuordnung da.
     */
    protected function recomputeSubtreeDepths(AssetCostCenter $node): void
    {
        $node->recomputeDepth();

        $nodes = AssetCostCenter::treeFor((int) $node->tenant_id);

        foreach (AssetCostCenter::descendantIds((int) $node->id, $nodes) as $descendantId) {
            AssetCostCenter::where('id', $descendantId)->first()?->recomputeDepth();
        }
    }

    // ---- Löschen ----------------------------------------------------------

    public function delete(int $id): void
    {
        Gate::authorize('asset-manager.manage');

        match ($this->active) {
            'cost-centers' => $this->deleteCostCenter($id),
            'cost-types'   => $this->deleteCostType($id),
            'vendors'      => $this->deleteVendor($id),
        };
    }

    protected function deleteCostCenter(int $id): void
    {
        $cc   = AssetCostCenter::where('team_id', $this->teamId())->findOrFail($id);
        $code = $cc->code;

        $childCount = AssetCostCenter::where('parent_id', $cc->id)->count();

        // parent_id ist nullOnDelete → Kinder werden zu obersten Knoten statt mitgelöscht zu werden.
        // Die übrigen FKs (Träger, Kostenpositionen) sind ebenfalls nullOnDelete → Zuordnungen werden
        // entfernt, die Datensätze bleiben.
        $cc->delete();

        // Die hochgerutschten Kinder tragen noch die alte depth.
        AssetCostCenter::whereNull('parent_id')->where('depth', '>', 0)->get()
            ->each(fn (AssetCostCenter $node) => $node->recomputeDepth());

        if ($this->selectedId === $id) {
            $this->resetSelection();
        }

        $this->flash = $childCount > 0
            ? "Kostenstelle {$code} gelöscht — {$childCount} untergeordnete Kostenstelle(n) sind jetzt oberste Knoten, Zuordnungen wurden entfernt."
            : "Kostenstelle {$code} gelöscht (Zuordnungen wurden entfernt).";
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
        // E1/ADR 0004: Reihenfolge ist ein Schreibpfad → Owner/Admin. (UI blendet den Drag-Handle
        // für reine Member ohnehin aus; der Gate schützt zusätzlich gegen direkte Livewire-Calls.)
        Gate::authorize('asset-manager.manage');

        $teamId = $this->teamId();
        $class  = $this->active === 'cost-centers' ? AssetCostCenter::class : AssetCostType::class;
        $pos    = 0;

        foreach ($this->flattenOrder($order) as $id) {
            $class::where('team_id', $teamId)->where('id', (int) $id)
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
        // E1/ADR 0004: Seeden legt Kostenarten an → Schreibpfad → Owner/Admin.
        Gate::authorize('asset-manager.manage');

        $bootstrap->seedForTeam($this->teamId(), $this->activeTenantId());
        $this->flash = 'Standard-Kostenarten geladen.';
    }

    // ---- Slug-Keys --------------------------------------------------------

    /** Eindeutigen Slug-Key je Tenant aus dem Namen ableiten (key ist intern, NOT NULL). */
    protected function uniqueCostTypeKey(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'kostenart';
        $key  = $base;
        $i    = 2;
        while (AssetCostType::where('team_id', $this->teamId())->where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }

    // ---- Tenant -----------------------------------------------------------

    /** Tenant, in dem Neuanlagen landen (ADR 0016). */
    protected function activeTenantId(): int
    {
        return TenantContext::resolveForWrite($this->teamId(), (int) \Illuminate\Support\Facades\Auth::id());
    }

    // ---- Daten (Computed) -------------------------------------------------

    /**
     * Kostenstellen als **flacher Baum** in Baum-Reihenfolge (Einrückung über `depth`).
     *
     * Bei aktiver Suche bzw. Teilbaum-Filter fällt die Ansicht auf eine flache, gefilterte Liste
     * zurück: eine gefilterte Baum-Ansicht müsste entweder Treffer aus dem Kontext reißen oder
     * Nicht-Treffer als Pfad mitzeigen — beides verwirrt mehr, als es hilft.
     */
    #[Computed]
    public function costCenters()
    {
        $tenantId = TenantContext::scopeTenantId();
        $filtered = $this->search !== '' || $this->filterParent !== null || $this->onlyActive;

        if (! $filtered && $tenantId !== null) {
            return AssetCostCenter::treeFor($tenantId)
                ->each(fn (AssetCostCenter $node) => $node->loadCount('holders'));
        }

        $subtreeIds = null;
        if ($this->filterParent !== null && $tenantId !== null) {
            $nodes      = AssetCostCenter::treeFor($tenantId);
            $subtreeIds = array_merge(
                [$this->filterParent],
                AssetCostCenter::descendantIds($this->filterParent, $nodes),
            );
        }

        return AssetCostCenter::where('team_id', $this->teamId())
            ->withCount('holders')
            ->with('parent')
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', '%' . $this->search . '%')
                ->orWhere('name', 'like', '%' . $this->search . '%')))
            ->when($subtreeIds !== null, fn ($q) => $q->whereIn('id', $subtreeIds))
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
            'cost-centers' => AssetCostCenter::where('team_id', $teamId)->count(),
            'cost-types'   => AssetCostType::where('team_id', $teamId)->count(),
            'vendors'      => AssetVendor::where('team_id', $teamId)->count(),
        ];
    }

    /**
     * Auswahl-Optionen für den Eltern-Knoten — der ganze Baum mit Einrückung. Beim Bearbeiten fallen
     * der Knoten selbst und seine Nachfahren heraus: sie als Eltern zu wählen wäre ein Zyklus, und ein
     * Dropdown-Eintrag, der nur eine Fehlermeldung erzeugt, ist eine Falle statt einer Option.
     */
    #[Computed]
    public function parentOptions()
    {
        $tenantId = TenantContext::scopeTenantId();

        if ($tenantId === null) {
            return collect();
        }

        $nodes = AssetCostCenter::treeFor($tenantId);

        if ($this->selectedId === null) {
            return $nodes;
        }

        $excluded = array_merge(
            [$this->selectedId],
            AssetCostCenter::descendantIds($this->selectedId, $nodes),
        );

        return $nodes->reject(fn (AssetCostCenter $node) => in_array((int) $node->id, $excluded, true))->values();
    }

    /** Oberste Knoten — für den Teilbaum-Filter (früher: Gesellschaft-Filter). */
    #[Computed]
    public function rootOptions()
    {
        return AssetCostCenter::where('team_id', $this->teamId())
            ->whereNull('parent_id')
            ->orderBy('sort_order')->orderBy('code')
            ->get();
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
