<?php

namespace Platform\AssetManager\Livewire\Vendors;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetVendor;

class Index extends Component
{
    public string  $newName  = '';
    public ?int    $editId   = null;
    public string  $eName    = '';
    public string  $eCreditor = '';
    public ?string $flash    = null;

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function create(): void
    {
        $this->validate(['newName' => 'required|string|max:255']);
        AssetVendor::firstOrCreate(['team_id' => $this->teamId(), 'name' => trim($this->newName)]);
        $this->reset('newName');
        $this->flash = 'Kreditor angelegt.';
        $this->dispatch('vendors-changed');
    }

    public function edit(int $id): void
    {
        $v = AssetVendor::where('team_id', $this->teamId())->findOrFail($id);
        $this->editId    = $v->id;
        $this->eName     = $v->name;
        $this->eCreditor = $v->creditor_no ?? '';
    }

    public function saveEdit(): void
    {
        $this->validate(['eName' => 'required|string|max:255']);
        $v = AssetVendor::where('team_id', $this->teamId())->findOrFail($this->editId);
        $v->update(['name' => trim($this->eName), 'creditor_no' => $this->eCreditor ?: null]);
        $this->editId = null;
        $this->flash  = 'Kreditor gespeichert.';
        $this->dispatch('vendors-changed');
    }

    public function delete(int $id): void
    {
        $v = AssetVendor::where('team_id', $this->teamId())->findOrFail($id);
        $name = $v->name;
        // vendor_id (Kostenpositionen) und vendor_default_id (Kostenarten) sind nullOnDelete →
        // Zuordnungen werden entfernt, Positionen/Kostenarten bleiben erhalten.
        $v->delete();
        if ($this->editId === $id) $this->editId = null;
        $this->flash = "Kreditor {$name} gelöscht (Zuordnungen wurden entfernt).";
        $this->dispatch('vendors-changed');
    }

    public function render()
    {
        $vendors = AssetVendor::where('team_id', $this->teamId())
            ->withCount('costLines')
            ->orderBy('name')->get();

        return view('asset-manager::livewire.vendors.index', compact('vendors'))
            ->layout('platform::layouts.app');
    }
}
