<?php

namespace Platform\AssetManager\Tools\CostLines;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Services\CostLineWriteService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Legt eine manuelle Kostenposition an (source='manual'). monthly_amount wird vom Model automatisch
 * aus amount/fx_rate/frequency abgeleitet.
 *
 * STRIKTE Kostenart-Prüfung: nur Kostenarten mit aggregation_source='cost_line' sind zulässig — andere
 * fallen still aus der Kostenaufteilung (normalizedLines zählt nur cost_line-Typen).
 */
class CreateCostLineTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    private const FREQUENCIES = ['monthly', 'quarterly', 'yearly', 'once'];

    public function getName(): string
    {
        return 'asset-manager.cost-lines.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/cost-lines - Legt eine manuelle Kostenposition an. Erforderlich: '
            . 'cost_type_id (Kostenart MUSS aggregation_source=cost_line haben), amount, frequency '
            . '(monthly|quarterly|yearly|once), label. Optional: currency (Default EUR), fx_rate, '
            . 'vendor_id, cost_center_id, assignee_id, valid_from, valid_to. monthly_amount wird '
            . 'automatisch berechnet. Für gültige Kostenarten → asset-manager.cost-types.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'cost_type_id'   => ['type' => 'integer', 'description' => 'Kostenart-ID (aggregation_source=cost_line). Erforderlich.'],
                'amount'         => ['type' => 'number', 'description' => 'Betrag in der angegebenen Frequenz/Währung. Erforderlich.'],
                'frequency'      => ['type' => 'string', 'enum' => self::FREQUENCIES, 'description' => 'Frequenz. Erforderlich.'],
                'label'          => ['type' => 'string', 'description' => 'Bezeichnung der Position. Erforderlich.'],
                'currency'       => ['type' => 'string', 'description' => 'Währung (Default EUR).'],
                'fx_rate'        => ['type' => 'number', 'description' => 'Umrechnungskurs zu EUR (Default 1).'],
                'vendor_id'      => ['type' => 'integer', 'description' => 'Kreditor-ID (Team).'],
                'cost_center_id' => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'assignee_id'    => ['type' => 'integer', 'description' => 'Asset-Träger-ID (Team).'],
                'valid_from'     => ['type' => 'string', 'description' => 'Gültig ab YYYY-MM-DD.'],
                'valid_to'       => ['type' => 'string', 'description' => 'Gültig bis YYYY-MM-DD.'],
            ], $this->tenantSchemaProperty()),
            'required' => ['cost_type_id', 'amount', 'frequency', 'label'],
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

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (!app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage', [$teamId])) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            // Alle fachlichen Regeln im Service — dieselben wie im Editor (#938). Vorher fehlte hier
            // u. a. tenant_id, und jeder Aufruf scheiterte an der NOT-NULL-Spalte.
            $result = app(CostLineWriteService::class)->create($teamId, (int) $tenantId, $arguments, requireCostLineSource: true);
            if (! $result['ok']) {
                return ToolResult::error(
                    isset($result['errors']['cost_type_id']) ? 'INVALID_COST_TYPE' : 'VALIDATION_ERROR',
                    implode(' ', $result['errors']) . ' Gültige Kostenarten → asset-manager.cost-types.GET.'
                );
            }
            $line     = $result['line'];
            $costType = $line->costType;

            return ToolResult::success([
                'id'             => $line->id,
                'label'          => $line->label,
                'cost_type'      => $costType->name,
                'amount'         => (float) $line->amount,
                'frequency'      => $line->frequency,
                'monthly_amount' => (float) $line->monthly_amount,
                'message'        => 'Kostenposition angelegt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen der Kostenposition: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'cost-lines']];
    }
}
