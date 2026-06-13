<?php

namespace Platform\AssetManager\Livewire\CostTypes;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;

class Index extends Component
{
    public ?int   $editId       = null;
    public string $eName        = '';
    public ?int   $eVendor      = null;
    public string $eSystem      = '';
    public string $eFrequency   = 'monthly';
    public bool   $ePerEmployee = false;
    public string $eAggSource   = 'cost_line';

    // Anlage
    public string $newName      = '';
    public string $newFrequency = 'monthly';
    public string $newAggSource = 'cost_line';

    // Ansicht-Sortierung
    public string $sortField = 'sort_order';
    public string $sortDir   = 'asc';

    public ?string $flash       = null;

    /** Whitelist erlaubter Sortierspalten (Schutz vor beliebigem orderBy). */
    protected const SORTABLE = ['sort_order', 'name', 'frequency_default', 'aggregation_source', 'cost_lines_count'];

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function sortBy(string $field): void
    {
        if (!in_array($field, self::SORTABLE, true)) return;
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir   = 'asc';
        }
    }

    public function create(): void
    {
        $this->validate([
            'newName'      => 'required|string|max:255',
            'newFrequency' => 'required|in:monthly,quarterly,yearly,once',
            'newAggSource' => 'required|in:cost_line,hardware_afa,ms_license,asset_device',
        ]);
        $teamId = $this->teamId();

        AssetCostType::create([
            'team_id'            => $teamId,
            'key'                => $this->uniqueKey($teamId, $this->newName),
            'name'               => trim($this->newName),
            'sort_order'         => (int) ((AssetCostType::where('team_id', $teamId)->max('sort_order') ?? 0) + 10),
            'frequency_default'  => $this->newFrequency,
            'aggregation_source' => $this->newAggSource,
            'is_per_employee'    => false,
        ]);
        $this->reset(['newName', 'newFrequency', 'newAggSource']);
        $this->flash = 'Kostenart angelegt.';
    }

    public function edit(int $id): void
    {
        $t = AssetCostType::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId       = $t->id;
        $this->eName        = $t->name;
        $this->eVendor      = $t->vendor_default_id;
        $this->eSystem      = $t->system_default ?? '';
        $this->eFrequency   = $t->frequency_default;
        $this->ePerEmployee = (bool) $t->is_per_employee;
        $this->eAggSource   = $t->aggregation_source;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'eName'      => 'required|string|max:255',
            'eFrequency' => 'required|in:monthly,quarterly,yearly,once',
            'eAggSource' => 'required|in:cost_line,hardware_afa,ms_license,asset_device',
        ]);
        $t = AssetCostType::where('team_id', $this->teamId())->findOrFail($this->editId);
        $t->update([
            'name'               => $this->eName,
            'vendor_default_id'  => $this->eVendor ?: null,
            'system_default'     => $this->eSystem ?: null,
            'frequency_default'  => $this->eFrequency,
            'is_per_employee'    => $this->ePerEmployee,
            'aggregation_source' => $this->eAggSource,
        ]);
        $this->editId = null;
        $this->flash  = 'Kostenart gespeichert.';
    }

    /** Löschen nur, wenn keine Positionen dranhängen — cost_type_id ist cascadeOnDelete (sonst stiller Datenverlust). */
    public function delete(int $id): void
    {
        $t = AssetCostType::where('team_id', $this->teamId())->withCount('costLines')->findOrFail($id);
        if ($t->cost_lines_count > 0) {
            $this->flash = "Kostenart {$t->name} hat {$t->cost_lines_count} Position(en) — erst dort umbuchen oder löschen, dann ist die Kostenart löschbar.";
            return;
        }
        $name = $t->name;
        $t->delete();
        if ($this->editId === $id) $this->editId = null;
        $this->flash = "Kostenart {$name} gelöscht.";
    }

    public function moveUp(int $id): void
    {
        $this->move($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->move($id, +1);
    }

    /** Verschiebt eine Kostenart in der manuellen Reihenfolge und renummeriert sort_order sauber (10,20,30…). */
    protected function move(int $id, int $dir): void
    {
        $teamId = $this->teamId();
        $ids = AssetCostType::where('team_id', $teamId)
            ->orderBy('sort_order')->orderBy('id')
            ->pluck('id')->all();

        $pos = array_search($id, $ids, true);
        if ($pos === false) return;
        $new = $pos + $dir;
        if ($new < 0 || $new >= count($ids)) return;

        [$ids[$pos], $ids[$new]] = [$ids[$new], $ids[$pos]];
        foreach ($ids as $i => $tid) {
            AssetCostType::where('id', $tid)->update(['sort_order' => ($i + 1) * 10]);
        }
        $this->flash = 'Reihenfolge aktualisiert.';
    }

    /** Eindeutigen Slug-Key je Team aus dem Namen ableiten (key ist intern, NOT NULL). */
    protected function uniqueKey(int $teamId, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'kostenart';
        $key  = $base;
        $i    = 2;
        while (AssetCostType::where('team_id', $teamId)->where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }
        return $key;
    }

    public function render()
    {
        $teamId = $this->teamId();

        $types = AssetCostType::where('team_id', $teamId)
            ->withCount('costLines')
            ->orderBy($this->sortField, $this->sortDir)
            ->orderBy('id')
            ->get();

        return view('asset-manager::livewire.cost-types.index', [
            'types'       => $types,
            'vendors'     => AssetVendor::where('team_id', $teamId)->orderBy('name')->get(),
            'manualOrder' => $this->sortField === 'sort_order' && $this->sortDir === 'asc',
        ])->layout('platform::layouts.app');
    }
}
