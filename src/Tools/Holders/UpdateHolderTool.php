<?php

namespace Platform\AssetManager\Tools\Holders;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\BillingExclusionService;
use Platform\AssetManager\Services\CostBootstrapService;
use Platform\AssetManager\Services\HolderTypeService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Einzel-Update eines Asset-Trägers (Kostenstelle, Abteilung, Aktiv-Status, Jobtitel, Kontotyp).
 * Für Massenänderungen der Kostenstelle → asset-manager.holders.cost-center.bulk.PUT, für die
 * Abrechnungs-Ausnahme → asset-manager.holders.billing-exclusion.bulk.PUT.
 */
class UpdateHolderTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.holder.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/employee - Aktualisiert EINEN Asset-Träger (per id ODER '
            . 'user_principal_name). Optionale Felder: cost_center (Kostenstellen-Code; setzt Code + '
            . 'cost_center_id konsistent; "" leert die Zuordnung), department, is_active (boolean), '
            . 'job_title, holder_type (person|function|admin|service|external), billing_excluded '
            . '(boolean; true nimmt den Träger von der Weiterberechnung aus und verlangt dann '
            . 'billing_exclusion_reason, ADR 0024). Unbekannter Kostenstellen-Code wird nur mit '
            . 'create_missing=true angelegt, sonst Fehler. Hinweis: is_active kommt aus Entra und '
            . 'wird beim nächsten Sync überschrieben — wer jemanden dauerhaft von der Rechnung '
            . 'nehmen will, nutzt billing_excluded.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id'                  => ['type' => 'integer', 'description' => 'Asset-Träger-ID (alternativ zu user_principal_name).'],
                'user_principal_name' => ['type' => 'string', 'description' => 'UPN (alternativ zu id).'],
                'cost_center'         => ['type' => 'string', 'description' => 'Kostenstellen-Code (z.B. "2599"). "" zum Leeren.'],
                'department'          => ['type' => 'string', 'description' => 'Abteilung.'],
                'is_active'           => ['type' => 'boolean', 'description' => 'Aktiv-Status.'],
                'job_title'           => ['type' => 'string', 'description' => 'Jobtitel.'],
                'holder_type'         => ['type' => 'string', 'enum' => ['person', 'function', 'admin', 'service', 'external'], 'description' => 'Traeger-Typ (ADR 0017). Nicht-Personen werden gelabelt, nicht gefiltert.'],
                'billing_excluded'    => ['type' => 'boolean', 'description' => 'true = von der Weiterberechnung ausnehmen (ADR 0024), false = Ausnahme aufheben.'],
                'billing_exclusion_reason' => ['type' => 'string', 'description' => 'Grund der Ausnahme. Pflicht bei billing_excluded=true.'],
                'account_type'        => ['type' => 'string', 'description' => 'DEPRECATED - Alias von holder_type.'],
                'create_missing'      => ['type' => 'boolean', 'description' => 'Wenn true: fehlende Kostenstelle anlegen (Default false).'],
            ], $this->tenantSchemaProperty()),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016): Default ist die gespeicherte Auswahl des Users. forceTenant()
            // setzt den Kontext fuer den Global Scope, damit JEDE Query unten tenant-rein ist, ohne
            // dass jede einzelne Query angefasst werden muss.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $query = AssetHolder::where('team_id', $teamId);
            if (!empty($arguments['id'])) {
                $query->where('id', (int) $arguments['id']);
            } elseif (!empty($arguments['user_principal_name'])) {
                $query->where('user_principal_name', (string) $arguments['user_principal_name']);
            } else {
                return ToolResult::error('VALIDATION_ERROR', 'Bitte id ODER user_principal_name angeben.');
            }

            /** @var AssetHolder|null $emp */
            $emp = $query->first();
            if (!$emp) {
                return ToolResult::error('NOT_FOUND', 'Asset-Träger nicht gefunden.');
            }

            // Kostenstelle (setzt cost_center-Code UND cost_center_id konsistent)
            if (array_key_exists('cost_center', $arguments)) {
                $code = trim((string) $arguments['cost_center']);
                if ($code === '') {
                    $emp->cost_center    = null;
                    $emp->cost_center_id = null;
                } else {
                    $center = AssetCostCenter::where('team_id', $teamId)->where('code', $code)->first();
                    if (!$center) {
                        if (!empty($arguments['create_missing'])) {
                            $center = app(CostBootstrapService::class)->resolveCostCenter($teamId, $code);
                        } else {
                            return ToolResult::error('COST_CENTER_NOT_FOUND', "Kostenstelle '{$code}' existiert nicht. create_missing=true zum Anlegen, oder asset-manager.cost-centers.GET für gültige Codes.");
                        }
                    }
                    $emp->cost_center    = $center->code;
                    $emp->cost_center_id = $center->id;
                }
            }

            // account_type als Alias von holder_type akzeptieren (ADR 0017), damit bestehende
            // LLM-Aufrufe weiter wirken.
            if (array_key_exists('account_type', $arguments) && ! array_key_exists('holder_type', $arguments)) {
                $arguments['holder_type'] = $arguments['account_type'];
            }

            if (array_key_exists('holder_type', $arguments)
                && ! in_array($arguments['holder_type'], \Platform\AssetManager\Models\AssetHolder::TYPES, true)) {
                return ToolResult::error('VALIDATION_ERROR', 'holder_type muss einer von person|function|admin|service|external sein.');
            }

            foreach (['department', 'job_title'] as $field) {
                if (array_key_exists($field, $arguments)) {
                    $emp->{$field} = $arguments[$field] !== '' ? $arguments[$field] : null;
                }
            }
            if (array_key_exists('is_active', $arguments)) {
                $emp->is_active = (bool) $arguments['is_active'];
            }

            $emp->save();

            // Die Abrechnungs-Ausnahme läuft NICHT über die Feldschleife: an ihr hängen drei Felder
            // (Zeitstempel, Grund, Urheber), die nur gemeinsam einen Sinn haben, plus die
            // Pflichtprüfung des Grundes. Der Service ist die gemeinsame Wahrheit von Detailseite,
            // Sammelaktion und Tool (ADR 0024).
            if (array_key_exists('billing_excluded', $arguments)) {
                $userId = $context->user->id ?? null;

                $result = app(BillingExclusionService::class)->setExclusion(
                    $teamId,
                    (int) $emp->tenant_id,
                    [$emp->id],
                    (bool) $arguments['billing_excluded'],
                    $arguments['billing_exclusion_reason'] ?? null,
                    $userId !== null ? (int) $userId : null,
                );

                if (! $result['ok']) {
                    return ToolResult::error($result['code'] ?? 'VALIDATION_ERROR', $result['message']);
                }

                $emp->refresh();
            }

            // Der Typ läuft NICHT über die Feldschleife: an ihm hängt die Lizenz-Verteilungskaskade
            // (ADR 0019). Ein direktes Setzen ließ hier bis zur Sammelaktion ein totes
            // `function_system` und eine Verteilung zurück, die die Stammdaten nicht mehr hergaben.
            // Der Service ist die gemeinsame Wahrheit von UI, Detailseite und Sammelaktion.
            if (array_key_exists('holder_type', $arguments) && $arguments['holder_type'] !== '') {
                $result = app(HolderTypeService::class)->setType(
                    $teamId,
                    (int) $emp->tenant_id,
                    [$emp->id],
                    (string) $arguments['holder_type'],
                );

                if (! $result['ok']) {
                    return ToolResult::error($result['code'] ?? 'VALIDATION_ERROR', $result['message']);
                }

                $emp->refresh();
            }

            $emp->load('costCenter');

            return ToolResult::success([
                'id'                => $emp->id,
                'name'              => $emp->name,
                'cost_center'       => $emp->cost_center,
                'cost_center_id'    => $emp->cost_center_id,
                'cost_center_label' => $emp->costCenter?->label,
                'department'        => $emp->department,
                'is_active'         => (bool) $emp->is_active,
                'billing_excluded'  => $emp->isBillingExcluded(),
                'billing_excluded_reason' => $emp->billing_excluded_reason,
                'job_title'         => $emp->job_title,
                'holder_type'       => $emp->holder_type,
                'account_type'      => $emp->holder_type, // DEPRECATED - Alias
                'message'           => "Asset-Träger '{$emp->name}' aktualisiert.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Asset-Trägers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'holder']];
    }
}
