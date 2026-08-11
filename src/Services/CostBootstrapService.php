<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Support\CostBootstrap;

/**
 * Legt die Kostenaufteilungs-Stammdaten (Kostenstellen-Baum, Kreditoren, Kostenarten) für einen
 * Tenant an und backfillt Kostenstellen aus den vorhandenen Träger-Strings. Vollständig idempotent.
 *
 * Seit docs/adr/0016 **tenant-scoped**: Stammdaten gehören einem Tenant, und die früheren
 * „Gesellschaften" sind Wurzelknoten von `asset_cost_centers` statt Zeilen einer eigenen Tabelle.
 *
 * ⚠️ MIGRATIONS-STABILER VERTRAG (M6/M12): Die Methoden {@see seedForTeam()} und
 * {@see backfillCostCenters()} werden von der Schema-Migration
 * 2026_06_13_000007_backfill_cost_centers_from_employees aufgerufen. Ein frischer `migrate` auf leerer
 * DB führt damit MUTABLEN Service-Code aus — Signatur UND beobachtbares Verhalten dieser beiden Methoden
 * müssen stabil bleiben, Änderungen nur additiv und rückwärtskompatibel.
 *
 * Konkret heißt das hier: die Tenant- und Baum-Felder werden nur gesetzt, wenn die Spalten **schon
 * existieren** ({@see supportsTenantScope()}/{@see supportsTree()}). Beim Lauf jener alten Migration
 * ist die Tenant-Welle (2026_08_07_*) noch nicht angewandt — sie kommt später in der Reihenfolge. Auf
 * einer leeren DB ruft die Migration den Service ohnehin nicht (kein Bestand → früher Return); die
 * Guards decken den Rest ab, damit auch ein `migrate:fresh` mit Seed-Daten nicht bricht.
 */
class CostBootstrapService
{
    /**
     * Neutrale Erst-Defaults für ein neues Team: nur generische Kostenarten, KEINE Firmenspezifika
     * (keine Gruppen-Knoten, keine Kreditoren). Idempotent (firstOrCreate nach tenant_id+key).
     *
     * MIGRATIONS-STABIL — von 2026_06_13_000007 aufgerufen (siehe Klassen-Docblock).
     */
    public function seedForTeam(int $teamId, ?int $tenantId = null): void
    {
        $this->seedCostTypes($teamId, CostBootstrap::NEUTRAL_COST_TYPES, $this->tenantFor($teamId, $tenantId));
    }

    /**
     * BROICH-spezifisches Set (Kostenstellen-Gruppen, Kreditoren, Kostenarten aus
     * Kostenaufteilung_IT.xlsx). Opt-in: wird nur vom BROICH-Excel-Import aufgerufen — fließt nie
     * automatisch in fremde Teams. Idempotent (firstOrCreate).
     */
    public function seedBroichDefaults(int $teamId, ?int $tenantId = null): void
    {
        $tenantId = $this->tenantFor($teamId, $tenantId);

        // 1. Gruppen-Knoten des Kostenstellen-Baums (früher: Gesellschaften)
        $sort = 0;
        foreach (CostBootstrap::COST_CENTER_GROUPS as $code => $name) {
            $this->firstOrCreateCostCenter($teamId, $tenantId, $code, [
                'name'       => $name,
                'sort_order' => ($sort += 10),
                'parent_id'  => null,
                'depth'      => 0,
            ]);
        }

        // 2. Kreditoren
        foreach (CostBootstrap::VENDORS as $vendorName) {
            $attributes = ['team_id' => $teamId, 'name' => $vendorName];
            if ($this->supportsTenantScope('asset_vendors')) {
                $attributes['tenant_id'] = $tenantId;
            }

            AssetVendor::withoutTenantScope()->firstOrCreate($attributes);
        }

        // 3. Kostenarten (mit Vendor-Default-Verknüpfung)
        $this->seedCostTypes($teamId, CostBootstrap::COST_TYPES, $tenantId);
    }

    /**
     * Legt fehlende Kostenarten an (firstOrCreate, NICHT updateOrCreate): Bestehende bleiben
     * unangetastet, damit UI-Pflege (Name, frequency_default, aggregation_source …) jeden weiteren
     * Seed-/Import-Lauf überlebt. Die Konstanten sind reine Erst-Defaults, nicht die Wahrheit.
     * sort_order zählt ab dem aktuellen Maximum weiter, damit neutrales + BROICH-Set nicht kollidieren.
     *
     * @param array<int,array<string,mixed>> $types
     */
    protected function seedCostTypes(int $teamId, array $types, ?int $tenantId): void
    {
        $vendorQuery = AssetVendor::withoutTenantScope()->where('team_id', $teamId);
        if ($tenantId !== null && $this->supportsTenantScope('asset_vendors')) {
            $vendorQuery->where('tenant_id', $tenantId);
        }
        $vendorIds = $vendorQuery->pluck('id', 'name');

        $sortQuery = AssetCostType::withoutTenantScope()->where('team_id', $teamId);
        if ($tenantId !== null && $this->supportsTenantScope('asset_cost_types')) {
            $sortQuery->where('tenant_id', $tenantId);
        }
        $sort = (int) ($sortQuery->max('sort_order') ?? 0);

        foreach ($types as $type) {
            $attributes = ['team_id' => $teamId, 'key' => $type['key']];
            if ($tenantId !== null && $this->supportsTenantScope('asset_cost_types')) {
                $attributes['tenant_id'] = $tenantId;
            }

            AssetCostType::withoutTenantScope()->firstOrCreate(
                $attributes,
                [
                    'name'               => $type['name'],
                    'sort_order'         => ($sort += 10),
                    'vendor_default_id'  => $type['vendor'] ? ($vendorIds[$type['vendor']] ?? null) : null,
                    'system_default'     => $type['system'],
                    'frequency_default'  => $type['frequency'],
                    'allocation_level'   => $type['level'],
                    'aggregation_source' => $type['aggregation_source'],
                    'allow_negative'     => $type['allow_negative'],
                ]
            );
        }
    }

    /**
     * Kostenstellen aus den vorhandenen Träger-Strings (cost_center) ableiten, unter den passenden
     * Gruppen-Knoten hängen und holder.cost_center_id setzen.
     *
     * MIGRATIONS-STABIL — von 2026_06_13_000007 aufgerufen (siehe Klassen-Docblock).
     *
     * @return array{centers_created:int, holders_linked:int, unmapped:array<string>}
     */
    public function backfillCostCenters(int $teamId, ?int $tenantId = null): array
    {
        $tenantId = $this->tenantFor($teamId, $tenantId);

        $codes = AssetHolder::withoutTenantScope()
            ->where('team_id', $teamId)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotNull('cost_center')
            ->where('cost_center', '!=', '')
            ->distinct()
            ->pluck('cost_center');

        $centersCreated  = 0;
        $holdersLinked = 0;
        $unmapped        = [];

        foreach ($codes as $rawCode) {
            $code = trim((string) $rawCode);
            if ($code === '') {
                continue;
            }

            $parentCode = CostBootstrap::parentForCostCenter($code);
            if ($parentCode === null) {
                $unmapped[] = $code;
            }

            $parentId = $parentCode !== null
                ? $this->resolveGroupNode($teamId, $tenantId, $parentCode)?->id
                : null;

            $center = $this->findCostCenter($teamId, $tenantId, $code);

            if (! $center) {
                $center = $this->firstOrCreateCostCenter($teamId, $tenantId, $code, [
                    'parent_id' => $parentId,
                    'depth'     => $parentId !== null ? 1 : 0,
                ]);
                $centersCreated++;
            } elseif ($this->supportsTree() && $center->parent_id === null && $parentId !== null) {
                // Nur ergänzen, nie umhängen: eine manuell gesetzte Einordnung ist die bessere
                // Information als das Bootstrap-Mapping und darf nicht überschrieben werden.
                $center->parent_id = $parentId;
                $center->depth     = 1;
                $center->save();
            }

            $holdersLinked += AssetHolder::withoutTenantScope()
                ->where('team_id', $teamId)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('cost_center', $rawCode)
                ->whereNull('cost_center_id')
                ->update(['cost_center_id' => $center->id]);
        }

        return [
            'centers_created'  => $centersCreated,
            'holders_linked' => $holdersLinked,
            'unmapped'         => array_values(array_unique($unmapped)),
        ];
    }

    /**
     * Findet/erstellt eine Kostenstelle anhand des Codes (für Import & manuelle Pflege).
     * Hängt eine neu angelegte Kostenstelle gleich unter ihren Gruppen-Knoten, falls das
     * Bootstrap-Mapping einen kennt.
     */
    public function resolveCostCenter(int $teamId, ?string $code, ?int $tenantId = null): ?AssetCostCenter
    {
        $code = $code !== null ? trim($code) : '';
        if ($code === '') {
            return null;
        }

        $tenantId = $this->tenantFor($teamId, $tenantId);

        if ($existing = $this->findCostCenter($teamId, $tenantId, $code)) {
            return $existing;
        }

        $parentCode = CostBootstrap::parentForCostCenter($code);
        $parentId   = $parentCode !== null
            ? $this->resolveGroupNode($teamId, $tenantId, $parentCode)?->id
            : null;

        return $this->firstOrCreateCostCenter($teamId, $tenantId, $code, [
            'parent_id' => $parentId,
            'depth'     => $parentId !== null ? 1 : 0,
        ]);
    }

    /** Gruppen-Knoten (Wurzel) per Code holen oder anlegen. */
    protected function resolveGroupNode(int $teamId, ?int $tenantId, string $code): ?AssetCostCenter
    {
        if (! $this->supportsTree()) {
            return null;
        }

        return $this->findCostCenter($teamId, $tenantId, $code)
            ?? $this->firstOrCreateCostCenter($teamId, $tenantId, $code, [
                'name'      => CostBootstrap::COST_CENTER_GROUPS[$code] ?? $code,
                'parent_id' => null,
                'depth'     => 0,
            ]);
    }

    protected function findCostCenter(int $teamId, ?int $tenantId, string $code): ?AssetCostCenter
    {
        return AssetCostCenter::withoutTenantScope()
            ->where('team_id', $teamId)
            ->when(
                $tenantId !== null && $this->supportsTenantScope('asset_cost_centers'),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->where('code', $code)
            ->first();
    }

    /**
     * Kostenstelle anlegen — Tenant- und Baum-Felder nur, wenn das Schema sie kennt (siehe
     * Klassen-Docblock zum Migrations-Vertrag).
     *
     * @param  array<string,mixed>  $values
     */
    protected function firstOrCreateCostCenter(int $teamId, ?int $tenantId, string $code, array $values): AssetCostCenter
    {
        if ($existing = $this->findCostCenter($teamId, $tenantId, $code)) {
            return $existing;
        }

        $attributes = ['team_id' => $teamId, 'code' => $code];

        if ($tenantId !== null && $this->supportsTenantScope('asset_cost_centers')) {
            $attributes['tenant_id'] = $tenantId;
        }

        if (! $this->supportsTree()) {
            unset($values['parent_id'], $values['depth']);
        }

        $center = new AssetCostCenter();
        $center->forceFill(array_merge($attributes, $values));
        $center->save();

        return $center;
    }

    /**
     * Tenant für einen Bootstrap-Lauf: der **aktive** Tenant, sonst der Default (wird bei Bedarf
     * angelegt). Null nur, wenn das Schema noch keine Tenants kennt — dann läuft der Aufruf aus der
     * alten Migration heraus (siehe Klassen-Docblock).
     *
     * Der aktive Tenant hat Vorrang, weil Stammdaten dort entstehen sollen, wo der Nutzer gerade
     * arbeitet — nicht im Default-Tenant des Teams.
     */
    protected function tenantFor(int $teamId, ?int $tenantId): ?int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        if (! Schema::hasTable('asset_tenants')) {
            return null;
        }

        return TenantContext::scopeTenantId() ?? TenantContext::defaultTenantId($teamId);
    }

    protected function supportsTenantScope(string $table): bool
    {
        static $cache = [];

        return $cache[$table] ??= Schema::hasColumn($table, 'tenant_id');
    }

    protected function supportsTree(): bool
    {
        static $cache = null;

        return $cache ??= Schema::hasColumn('asset_cost_centers', 'parent_id');
    }
}
