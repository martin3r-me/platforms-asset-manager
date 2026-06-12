<?php

namespace Platform\AssetManager\Livewire\Assets;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;

class Show extends Component
{
    public AssetItem $item;

    public string  $name          = '';
    public string  $manufacturer  = '';
    public string  $model         = '';
    public string  $serialNumber  = '';
    public ?int    $assigneeId    = null;
    public string  $status        = 'in_stock';
    public ?int    $categoryId    = null;
    public ?string $purchaseDate  = null;
    public ?string $purchasePrice = null;
    public ?int    $depreciationMonths = null;
    public string  $notes         = '';

    public bool    $saved         = false;
    public bool    $showRawData   = false;

    public function mount(AssetItem $item): void
    {
        Gate::authorize('view', $item);

        $this->item               = $item;
        $this->name               = $item->name ?? '';
        $this->manufacturer       = $item->manufacturer ?? '';
        $this->model              = $item->model ?? '';
        $this->serialNumber       = $item->serial_number ?? '';
        $this->assigneeId         = $item->assignee_id;
        $this->status             = $item->status;
        $this->categoryId         = $item->category_id;
        $this->purchaseDate       = $item->purchase_date?->format('Y-m-d');
        $this->purchasePrice      = $item->purchase_price !== null ? (string) $item->purchase_price : null;
        $this->depreciationMonths = $item->depreciation_months;
        $this->notes              = $item->notes ?? '';
    }

    public function save(): void
    {
        Gate::authorize('update', $this->item);

        $this->validate([
            'name'               => 'required|string|max:255',
            'manufacturer'       => 'nullable|string|max:255',
            'model'              => 'nullable|string|max:255',
            'serialNumber'       => 'nullable|string|max:255',
            'assigneeId'         => 'nullable|exists:asset_employees,id',
            'status'             => 'required|in:in_stock,assigned,retired,lost',
            'categoryId'         => 'required|exists:asset_categories,id',
            'purchaseDate'       => 'nullable|date',
            'purchasePrice'      => 'nullable|numeric|min:0',
            'depreciationMonths' => 'nullable|integer|min:1|max:240',
            'notes'              => 'nullable|string',
        ]);

        $isIntune = $this->item->source === 'intune';

        // Bei Intune-Items nur Kosten/Notes/Assignee editierbar — Name/Hersteller etc. read-only
        $data = [
            'assignee_id'         => $this->assigneeId,
            'status'              => $this->assigneeId ? 'assigned' : $this->status,
            'assigned_at'         => $this->assigneeId ? ($this->item->assigned_at ?? now()) : null,
            'purchase_date'       => $this->purchaseDate ?: null,
            'purchase_price'      => $this->purchasePrice !== '' ? $this->purchasePrice : null,
            'depreciation_months' => $this->depreciationMonths,
            'notes'               => $this->notes ?: null,
        ];

        if (!$isIntune) {
            $data = array_merge($data, [
                'name'          => $this->name,
                'manufacturer'  => $this->manufacturer ?: null,
                'model'         => $this->model ?: null,
                'serial_number' => $this->serialNumber ?: null,
                'category_id'   => $this->categoryId,
            ]);
        }

        // Bei Assignee-Wechsel: Historie schreiben
        if ($this->assigneeId !== $this->item->assignee_id) {
            $employee = $this->assigneeId ? AssetEmployee::find($this->assigneeId) : null;
            $this->item->assignTo($employee);
            // assignTo hat schon gespeichert — wir holen das Item frisch
            $this->item->refresh();
            // Restliche Felder noch updaten
            $this->item->update(array_intersect_key($data, array_flip([
                'purchase_date', 'purchase_price', 'depreciation_months', 'notes',
                'name', 'manufacturer', 'model', 'serial_number', 'category_id',
            ])));
        } else {
            $this->item->update($data);
        }

        $this->item->refresh();
        $this->saved = true;
    }

    public function delete()
    {
        Gate::authorize('delete', $this->item);

        $this->item->delete();
        return redirect()->route('asset-manager.assets.index');
    }

    public function toggleRawData(): void
    {
        $this->showRawData = !$this->showRawData;
    }

    public function render()
    {
        $teamId = $this->item->team_id;

        $activities = $this->item->assignments()
            ->with('employee')
            ->orderByDesc('assigned_at')
            ->limit(10)
            ->get();

        return view('asset-manager::livewire.assets.show', [
            'item'       => $this->item,
            'categories' => AssetCategory::orderBy('sort_order')->get(),
            'employees'  => AssetEmployee::where('team_id', $teamId)->where('is_active', true)->orderBy('display_name')->get(),
            'activities' => $activities,
        ])->layout('platform::layouts.app');
    }
}
