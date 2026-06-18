<?php

namespace Platform\AssetManager\Livewire\Assets;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\TenantContext;

class Create extends Component
{
    public ?int    $categoryId    = null;
    public string  $name          = '';
    public string  $manufacturer  = '';
    public string  $model         = '';
    public string  $serialNumber  = '';
    public ?int    $assigneeId    = null;
    public string  $status        = 'in_stock';
    public ?string $purchaseDate  = null;
    public ?string $purchasePrice = null;
    public ?int    $depreciationMonths = null;
    public string  $notes         = '';

    public function mount(): void
    {
        Gate::authorize('create', AssetItem::class);
    }

    public function updatedCategoryId(): void
    {
        // AfA-Default aus Kategorie übernehmen, wenn noch nicht gesetzt
        if ($this->categoryId && !$this->depreciationMonths) {
            $cat = AssetCategory::find($this->categoryId);
            if ($cat && $cat->default_depreciation_months) {
                $this->depreciationMonths = $cat->default_depreciation_months;
            }
        }
    }

    public function save()
    {
        Gate::authorize('create', AssetItem::class);

        $teamId = Auth::user()->currentTeam->id;

        $validated = $this->validate([
            'categoryId'         => 'required|exists:asset_categories,id',
            'name'               => 'required|string|max:255',
            'manufacturer'       => 'nullable|string|max:255',
            'model'              => 'nullable|string|max:255',
            'serialNumber'       => 'nullable|string|max:255',
            // Assignee MUSS zum eigenen Team gehören (sonst danglende cross-team FK).
            'assigneeId'         => ['nullable', 'integer', Rule::exists('asset_employees', 'id')->where('team_id', $teamId)],
            'status'             => 'required|in:in_stock,assigned,retired,lost',
            'purchaseDate'       => 'nullable|date',
            'purchasePrice'      => 'nullable|numeric|min:0',
            'depreciationMonths' => 'nullable|integer|min:1|max:240',
            'notes'              => 'nullable|string',
        ]);

        $team = Auth::user()->currentTeam;

        // Wenn assignee gesetzt: Status auf assigned forcieren
        $status     = $this->assigneeId ? 'assigned' : $this->status;
        $assignedAt = $this->assigneeId ? now()      : null;

        $item = AssetItem::create([
            'team_id'             => $team->id,
            'tenant_id'           => TenantContext::resolveForWrite($team->id, (int) Auth::id()),
            'category_id'         => $this->categoryId,
            'source'              => 'manual',
            'name'                => $this->name,
            'manufacturer'        => $this->manufacturer ?: null,
            'model'               => $this->model ?: null,
            'serial_number'       => $this->serialNumber ?: null,
            'assignee_id'         => $this->assigneeId,
            'assigned_at'         => $assignedAt,
            'status'              => $status,
            'purchase_date'       => $this->purchaseDate ?: null,
            'purchase_price'      => $this->purchasePrice ?: null,
            'depreciation_months' => $this->depreciationMonths,
            'notes'               => $this->notes ?: null,
        ]);

        if ($this->assigneeId) {
            \Platform\AssetManager\Models\AssetAssignment::create([
                'asset_item_id' => $item->id,
                'employee_id'   => $this->assigneeId,
                'assigned_at'   => now(),
            ]);
        }

        return redirect()->route('asset-manager.assets.show', $item);
    }

    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;

        return view('asset-manager::livewire.assets.create', [
            'categories' => AssetCategory::orderBy('sort_order')->get(),
            'employees'  => AssetEmployee::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get(),
        ])->layout('platform::layouts.app');
    }
}
