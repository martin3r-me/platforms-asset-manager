<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gespeicherte Tabellen-Ansicht je (User × Team × Tabelle): Spaltenbreiten, Spaltenreihenfolge,
 * aus-/eingeblendete Spalten und Zeilen, eingeklappte Gruppen (docs/adr/0020).
 *
 * Genau eine Zeile je Schlüssel-Tripel (unique). `payload` ist ein ungetypter Blob — die
 * Struktur verantwortet {@see \Platform\AssetManager\Support\TableViewState}, das beim Lesen
 * jeden Wert prüft. Nie ungeprüft ins Blade geben.
 *
 * Trägt bewusst KEIN {@see \Platform\AssetManager\Concerns\TenantScopable}: eine Ansicht gehört
 * zum Blick des Users, nicht zu tenant-gebundenen Daten. Auto-increment-IDs (Modul-Konvention für
 * Präferenz-Tabellen, wie AssetTenantSelection).
 */
class AssetTableView extends Model
{
    protected $table = 'asset_table_views';

    protected $fillable = [
        'user_id',
        'team_id',
        'table_key',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
