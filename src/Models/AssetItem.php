<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetItem extends Model
{
    use SoftDeletes;
    use TenantScopable;

    protected $table = 'asset_items';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'category_id',
        'source',
        'external_id',
        'name',
        'manufacturer',
        'model',
        'serial_number',
        'assignee_id',
        'assigned_at',
        'status',
        'notes',
        'purchase_price',
        'purchase_date',
        'depreciation_months',
        'raw_data',
    ];

    protected $casts = [
        'assigned_at'    => 'datetime',
        'purchase_date'  => 'date',
        'purchase_price' => 'decimal:2',
        'raw_data'       => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Tenant (Kundenkontext), zu dem dieses Asset gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AssetEmployee::class, 'assignee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_at');
    }

    public function statusBadgeColor(): string
    {
        return match($this->status) {
            'assigned' => 'emerald',
            'in_stock' => 'sky',
            'retired'  => 'gray',
            'lost'     => 'red',
            default    => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'assigned' => 'Zugewiesen',
            'in_stock' => 'Lager',
            'retired'  => 'Ausgemustert',
            'lost'     => 'Verloren',
            default    => $this->status,
        };
    }

    /**
     * Monatliche Kosten (AfA). 0 wenn keine Daten oder bereits abgeschrieben.
     */
    public function monthlyCost(): float
    {
        if (!$this->purchase_price || !$this->depreciation_months) return 0.0;

        // Wenn purchase_date vorhanden und älter als depreciation_months → 0
        if ($this->purchase_date) {
            $monthsSince = $this->purchase_date->diffInMonths(now());
            if ($monthsSince >= $this->depreciation_months) return 0.0;
        }

        return round((float) $this->purchase_price / $this->depreciation_months, 2);
    }

    /**
     * Setzt den Assignee + passt Status und assigned_at an. Schreibt Historie.
     */
    public function assignTo(?AssetEmployee $employee, ?string $notes = null): void
    {
        if ($employee && $this->assignee_id === $employee->id) return;

        // Vorherige offene Zuweisung schließen
        $this->assignments()
            ->whereNull('returned_at')
            ->update(['returned_at' => now(), 'updated_at' => now()]);

        if ($employee) {
            $this->update([
                'assignee_id' => $employee->id,
                'assigned_at' => now(),
                'status'      => 'assigned',
            ]);
            AssetAssignment::create([
                'asset_item_id' => $this->id,
                'employee_id'   => $employee->id,
                'assigned_at'   => now(),
                'notes'         => $notes,
            ]);
        } else {
            $this->update([
                'assignee_id' => null,
                'assigned_at' => null,
                'status'      => 'in_stock',
            ]);
        }
    }
}
