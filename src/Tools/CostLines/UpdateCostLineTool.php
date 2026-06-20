<?php

namespace Platform\AssetManager\Tools\CostLines;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Aktualisiert eine Kostenposition. Wird die Kostenart gewechselt, wird erneut strikt geprüft, dass
 * sie aggregation_source='cost_line' hat (sonst würde der Betrag aus der Aufteilung verschwinden).
 */
class UpdateCostLineTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    private const FREQUENCIES = ['monthly', 'quarterly', 'yearly', 'once'];

    public function getName(): string
    {
        return 'asset-manager.cost-lines.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /asset-manager/cost-lines - Aktualisiert eine Kostenposition (per id). Optionale '
            . 'Felder: cost_type_id (muss cost_line sein), amount, frequency, currency, fx_rate, label, '
            . 'vendor_id, cost_center_id, assignee_id, valid_from, valid_to, active. monthly_amount wird '
            . 'automatisch neu berechnet.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'             => ['type' => 'integer', 'description' => 'Kostenpositions-ID (erforderlich).'],
                'cost_type_id'   => ['type' => 'integer', 'description' => 'Neue Kostenart-ID (aggregation_source=cost_line).'],
                'amount'         => ['type' => 'number', 'description' => 'Betrag.'],
                'frequency'      => ['type' => 'string', 'enum' => self::FREQUENCIES, 'description' => 'Frequenz.'],
                'currency'       => ['type' => 'string', 'description' => 'Währung.'],
                'fx_rate'        => ['type' => 'number', 'description' => 'Umrechnungskurs.'],
                'label'          => ['type' => 'string', 'description' => 'Bezeichnung.'],
                'vendor_id'      => ['type' => 'integer', 'description' => 'Kreditor-ID (Team).'],
                'cost_center_id' => ['type' => 'integer', 'description' => 'Kostenstellen-ID (Team).'],
                'assignee_id'    => ['type' => 'integer', 'description' => 'Mitarbeiter-ID (Team).'],
                'valid_from'     => ['type' => 'string', 'description' => 'Gültig ab YYYY-MM-DD.'],
                'valid_to'       => ['type' => 'string', 'description' => 'Gültig bis YYYY-MM-DD.'],
                'active'         => ['type' => 'boolean', 'description' => 'Aktiv-Status.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (!Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }
            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            /** @var AssetCostLine|null $line */
            $line = AssetCostLine::where('team_id', $teamId)->find((int) $arguments['id']);
            if (!$line) {
                return ToolResult::error('NOT_FOUND', 'Kostenposition nicht gefunden.');
            }

            if (array_key_exists('cost_type_id', $arguments) && $arguments['cost_type_id']) {
                $costType = AssetCostType::where('team_id', $teamId)->find((int) $arguments['cost_type_id']);
                if (!$costType) {
                    return ToolResult::error('VALIDATION_ERROR', 'cost_type_id gehört nicht zum Team.');
                }
                if ($costType->aggregation_source !== AssetCostType::SOURCE_COST_LINE) {
                    return ToolResult::error('INVALID_COST_TYPE', "Kostenart '{$costType->name}' ist nicht cost_line — sie würde aus der Aufteilung fallen.");
                }
                $line->cost_type_id = $costType->id;
            }
            if (array_key_exists('frequency', $arguments)) {
                if (!in_array($arguments['frequency'], self::FREQUENCIES, true)) {
                    return ToolResult::error('VALIDATION_ERROR', 'frequency ungültig.');
                }
                $line->frequency = $arguments['frequency'];
            }
            foreach (['vendor_id' => AssetVendor::class, 'cost_center_id' => AssetCostCenter::class, 'assignee_id' => AssetEmployee::class] as $field => $class) {
                if (array_key_exists($field, $arguments)) {
                    $val = $arguments[$field];
                    if ($val) {
                        if (!$class::where('team_id', $teamId)->whereKey((int) $val)->exists()) {
                            return ToolResult::error('VALIDATION_ERROR', "{$field} gehört nicht zum Team.");
                        }
                        $line->{$field} = (int) $val;
                    } else {
                        $line->{$field} = null;
                    }
                }
            }
            foreach (['amount', 'currency', 'fx_rate', 'label', 'valid_from', 'valid_to'] as $f) {
                if (array_key_exists($f, $arguments)) {
                    $line->{$f} = ($arguments[$f] === '' ) ? null : $arguments[$f];
                }
            }
            if (array_key_exists('active', $arguments)) {
                $line->active = (bool) $arguments['active'];
            }

            // Betrag prüfen, wenn er geändert wurde: 0 ablehnen; negativ nur bei allow_negative-Kostenart
            // (Gutschrift) — verhindert stilles Netting durch Tippfehler-Minusbeträge.
            if (array_key_exists('amount', $arguments)) {
                $amount = (float) $line->amount;
                if ($amount == 0.0) {
                    return ToolResult::error('VALIDATION_ERROR', 'amount darf nicht 0,00 sein.');
                }
                if ($amount < 0 && ! (bool) AssetCostType::where('team_id', $teamId)->whereKey($line->cost_type_id)->value('allow_negative')) {
                    return ToolResult::error('VALIDATION_ERROR', 'Negativer Betrag ist nur für Kostenarten mit allow_negative (Gutschrift) zulässig.');
                }
            }

            // Effektive Währung/Kurs prüfen (nach Anwenden der Argumente): Nicht-EUR ohne positiven
            // fx_rate ablehnen, sonst bewertet computeMonthlyAmount den Betrag still 1:1 als EUR.
            $effCurrency = strtoupper(trim((string) $line->currency)) ?: 'EUR';
            if ($effCurrency !== 'EUR' && (!is_numeric($line->fx_rate) || (float) $line->fx_rate <= 0)) {
                return ToolResult::error('VALIDATION_ERROR', "Nicht-EUR-Position (currency={$effCurrency}) benötigt einen positiven fx_rate (Umrechnungskurs zu EUR) — sonst würde der Betrag still 1:1 als EUR gewertet.");
            }

            $line->save();

            return ToolResult::success([
                'id'             => $line->id,
                'label'          => $line->label,
                'amount'         => (float) $line->amount,
                'frequency'      => $line->frequency,
                'monthly_amount' => (float) $line->monthly_amount,
                'active'         => (bool) $line->active,
                'message'        => 'Kostenposition aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Kostenposition: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'cost-lines']];
    }
}
