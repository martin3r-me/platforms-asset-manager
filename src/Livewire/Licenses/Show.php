<?php

namespace Platform\AssetManager\Livewire\Licenses;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;

class Show extends Component
{
    use WithPagination;

    public AssetLicenseSku $sku;
    public string          $search = '';

    public function mount(AssetLicenseSku $sku): void
    {
        abort_unless($sku->team_id === Auth::user()->currentTeam->id, 403);
        $this->sku = $sku;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = AssetUserLicense::where('team_id', $this->sku->team_id)
            ->where('sku_id', $this->sku->sku_id);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('user_principal_name', 'like', '%' . $this->search . '%');
            });
        }

        $assignments = $query
            ->orderBy('display_name')
            ->paginate(50);

        return view('asset-manager::livewire.licenses.show', [
            'assignments' => $assignments,
        ])->layout('platform::layouts.app');
    }
}
