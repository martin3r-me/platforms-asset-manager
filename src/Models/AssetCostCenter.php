<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Kostenstelle — die Hauptachse der Kostenaufteilung, als **Baum** beliebiger Tiefe je Tenant.
 *
 * Die obersten Knoten (`parent_id IS NULL`) sind die Gesellschaften. Die frühere eigene Tabelle
 * `asset_companies` ist hier aufgegangen (siehe docs/adr/0016): strukturell war „Gesellschaft" nie
 * etwas anderes als eine Kostenstelle ohne Eltern, und zwei feste Ebenen tragen die reale Struktur
 * nicht — „Broich Verwaltung" ist ein Kind von „Broich Catering", nicht ihr Geschwister.
 *
 * `depth` ist denormalisiert (0 = Wurzel): erlaubt sortierte Baum-Ausgabe ohne recursive CTE und ist
 * über {@see recomputeDepth()} jederzeit neu herleitbar.
 */
class AssetCostCenter extends Model
{
    use HasFactory;
    use TenantScopable;

    protected $table = 'asset_cost_centers';

    /** Praktische Obergrenze der Baumtiefe — schützt Rollup/UI vor einem Zyklus in den Daten. */
    public const MAX_DEPTH = 10;

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Platform\AssetManager\Database\Factories\AssetCostCenterFactory::new();
    }

    protected $fillable = [
        'team_id',
        'tenant_id',
        'parent_id',
        'depth',
        'code',
        'name',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'depth'     => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(AssetCostLine::class, 'cost_center_id');
    }

    public function holders(): HasMany
    {
        return $this->hasMany(AssetHolder::class, 'cost_center_id');
    }

    /** @deprecated Verwende {@see holders()} — „Mitarbeiter" ist modulweit ausgemustert (ADR 0017). */
    public function employees(): HasMany
    {
        return $this->holders();
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Oberster Knoten über dieser Kostenstelle — was früher „Gesellschaft" hieß. Für Anzeigen und
     * Tool-Antworten, die den groben Zuordnungskontext brauchen, ohne den Baum zu laden.
     */
    public function root(): self
    {
        $cursor = $this;
        $guard  = 0;

        while ($cursor->parent_id !== null && $guard++ < self::MAX_DEPTH) {
            $parent = static::query()->withoutTenantScope()->find($cursor->parent_id);
            if (! $parent) {
                break; // verwaister Verweis → dieser Knoten gilt als Wurzel
            }
            $cursor = $parent;
        }

        return $cursor;
    }

    /** Anzeigename des obersten Knotens (Name, sonst Code). */
    public function rootLabel(): string
    {
        $root = $this->root();

        return $root->name ?? (string) $root->code;
    }

    /** Anzeige: "2599 — RHEIN RUHR" bzw. nur Code. */
    public function getLabelAttribute(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : (string) $this->code;
    }

    /** Einrück-Label für Baum-Dropdowns: "— — 2599 — RHEIN RUHR". */
    public function getTreeLabelAttribute(): string
    {
        return trim(str_repeat('— ', max(0, (int) $this->depth)) . $this->label);
    }

    /**
     * Ganze Kostenstellen-Ebene eines Tenants als **flache, in Baum-Reihenfolge sortierte** Liste
     * (Wurzeln nach sort_order/code, darunter rekursiv ihre Kinder). Eine Query, danach in PHP
     * gehängt — der Baum ist klein (Dutzende Knoten), ein recursive CTE wäre hier nur MySQL-Ballast.
     *
     * Knoten mit fehlendem oder tenant-fremdem Eltern-Knoten werden als Wurzeln behandelt, damit ein
     * inkonsistenter Bestand sichtbar bleibt statt aus der Liste zu fallen.
     *
     * @return Collection<int, self>
     */
    public static function treeFor(int $tenantId, bool $onlyActive = false): Collection
    {
        $nodes = static::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $byParent = $nodes->groupBy(fn (self $node) => $node->parent_id ?? 0);
        $known    = $nodes->keyBy('id');

        // Verwaiste Knoten (Eltern nicht in dieser Menge) an die Wurzel holen.
        foreach ($byParent as $parentId => $group) {
            if ($parentId !== 0 && ! $known->has($parentId)) {
                $byParent[0] = ($byParent[0] ?? new Collection())->merge($group);
                $byParent->forget($parentId);
            }
        }

        $ordered = new Collection();

        $append = function (int $parentId, int $depth) use (&$append, $byParent, $ordered): void {
            if ($depth > self::MAX_DEPTH) {
                return; // Zyklus-Bremse
            }

            foreach ($byParent[$parentId] ?? [] as $node) {
                $node->depth = $depth;
                $ordered->push($node);
                $append((int) $node->id, $depth + 1);
            }
        };

        $append(0, 0);

        return $ordered;
    }

    /**
     * Alle Nachfahren-IDs (ohne den Knoten selbst) aus einer bereits geladenen Knotenmenge.
     * Für den Pivot-Rollup: die Summe eines Knotens umfasst seine eigenen Zeilen plus die aller Kinder.
     *
     * @param  Collection<int, self>  $nodes
     * @return array<int, int>
     */
    public static function descendantIds(int $nodeId, Collection $nodes): array
    {
        $byParent = $nodes->groupBy(fn (self $node) => $node->parent_id ?? 0);
        $result   = [];
        $queue    = [$nodeId];
        $guard    = 0;

        while ($queue !== [] && $guard++ <= $nodes->count() + 1) {
            $current = array_shift($queue);

            foreach ($byParent[$current] ?? [] as $child) {
                $result[] = (int) $child->id;
                $queue[]  = (int) $child->id;
            }
        }

        return $result;
    }

    /**
     * `depth` aus der Elternkette neu berechnen und speichern — nach Umhängen aufrufen.
     * Kappt bei MAX_DEPTH, damit ein Zyklus in den Daten nicht in eine Endlosschleife läuft.
     */
    public function recomputeDepth(): int
    {
        $depth  = 0;
        $cursor = $this->parent_id;
        $seen   = [$this->id => true];

        while ($cursor !== null && $depth < self::MAX_DEPTH) {
            if (isset($seen[$cursor])) {
                break; // Zyklus
            }
            $seen[$cursor] = true;

            $depth++;
            $cursor = static::query()->withoutTenantScope()->whereKey($cursor)->value('parent_id');
        }

        if ((int) $this->depth !== $depth) {
            $this->depth = $depth;
            $this->saveQuietly();
        }

        return $depth;
    }

    /**
     * Wäre `$parentId` ein gültiger neuer Eltern-Knoten? Verhindert die drei Wege, sich den Baum
     * zu zerlegen: sich selbst, einen eigenen Nachfahren (Zyklus) und einen fremden Tenant.
     */
    public function canMoveUnder(?int $parentId): bool
    {
        if ($parentId === null) {
            return true; // zur Wurzel machen ist immer erlaubt
        }

        if ($parentId === (int) $this->id) {
            return false;
        }

        $parent = static::query()->withoutTenantScope()->find($parentId);

        if (! $parent || (int) $parent->tenant_id !== (int) $this->tenant_id) {
            return false;
        }

        // Zyklus: der Zielknoten darf kein Nachfahre von $this sein.
        $cursor = $parent;
        $guard  = 0;

        while ($cursor !== null && $guard++ <= self::MAX_DEPTH) {
            if ((int) $cursor->id === (int) $this->id) {
                return false;
            }
            $cursor = $cursor->parent_id
                ? static::query()->withoutTenantScope()->find($cursor->parent_id)
                : null;
        }

        return true;
    }
}
