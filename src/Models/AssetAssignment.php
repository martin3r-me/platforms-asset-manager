<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAssignment extends Model
{
    protected $table = 'asset_assignments';

    protected $fillable = [
        'asset_item_id',
        'employee_id',
        'assigned_at',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    public function employee()
    {
        return $this->belongsTo(AssetEmployee::class, 'employee_id');
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }
}
