<?php

namespace Platform\AssetManager\Livewire\Companies;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCompany;

class Index extends Component
{
    public ?int    $editId = null;
    public string  $eName  = '';
    public ?int    $eSort  = null;

    // Anlage
    public string  $newName = '';
    public ?string $flash   = null;

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function edit(int $id): void
    {
        $co = AssetCompany::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId = $co->id;
        $this->eName  = $co->name;
        $this->eSort  = $co->sort_order;
    }

    public function saveEdit(): void
    {
        $this->validate(['eName' => 'required|string|max:255']);
        $co = AssetCompany::where('team_id', $this->teamId())->findOrFail($this->editId);
        $co->update([
            'name'       => trim($this->eName),
            'sort_order' => (int) ($this->eSort ?? 100),
        ]);
        $this->editId = null;
        $this->flash  = 'Gesellschaft gespeichert.';
        $this->dispatch('companies-changed');
    }

    public function delete(int $id): void
    {
        $co = AssetCompany::where('team_id', $this->teamId())->findOrFail($id);
        $name = $co->name;
        // company_id an Kostenstellen ist nullOnDelete → Zuordnungen werden entfernt, Kostenstellen bleiben.
        $co->delete();
        if ($this->editId === $id) $this->editId = null;
        $this->flash = "Gesellschaft {$name} gelöscht (Kostenstellen-Zuordnungen entfernt).";
        $this->dispatch('companies-changed');
    }

    public function create(): void
    {
        $this->validate(['newName' => 'required|string|max:255']);
        $teamId = $this->teamId();

        AssetCompany::create([
            'team_id'    => $teamId,
            'key'        => $this->uniqueKey($teamId, $this->newName),
            'name'       => trim($this->newName),
            'sort_order' => (int) ((AssetCompany::where('team_id', $teamId)->max('sort_order') ?? 0) + 10),
        ]);
        $this->reset(['newName']);
        $this->flash = 'Gesellschaft angelegt.';
        $this->dispatch('companies-changed');
    }

    /** Eindeutigen Slug-Key je Team aus dem Namen ableiten (key ist intern, NOT NULL). */
    protected function uniqueKey(int $teamId, string $name): string
    {
        $base = Str::slug($name) ?: 'gesellschaft';
        $key  = $base;
        $i    = 2;
        while (AssetCompany::where('team_id', $teamId)->where('key', $key)->exists()) {
            $key = $base . '-' . $i++;
        }
        return $key;
    }

    public function render()
    {
        $companies = AssetCompany::where('team_id', $this->teamId())
            ->withCount('costCenters')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return view('asset-manager::livewire.companies.index', [
            'companies' => $companies,
        ])->layout('platform::layouts.app');
    }
}
