<?php

namespace Platform\AssetManager\Tools\MasterData;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetCompany;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Legt eine Kostenstelle an (idempotent über Code). Optional einer Gesellschaft zuordnen.
 */
class CreateCostCenterTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    public function getName(): string
    {
        return 'asset-manager.cost-centers.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/cost-centers - Legt eine Kostenstelle an. Erforderlich: code. '
            . 'Optional: name, company_id ODER company_key (Gesellschaft), is_active, notes. '
            . 'Existiert der Code bereits, wird die bestehende Kostenstelle zurückgegeben (idempotent).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'code'        => ['type' => 'string', 'description' => 'Kostenstellen-Code (erforderlich), z.B. "2599".'],
                'name'        => ['type' => 'string', 'description' => 'Anzeigename.'],
                'company_id'  => ['type' => 'integer', 'description' => 'Gesellschaft-ID (Team).'],
                'company_key' => ['type' => 'string', 'description' => 'Gesellschaft-Key (alternativ zu company_id).'],
                'is_active'   => ['type' => 'boolean', 'description' => 'Aktiv-Status (Default true).'],
                'notes'       => ['type' => 'string', 'description' => 'Notiz.'],
            ],
            'required' => ['code'],
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

            $code = trim((string) ($arguments['code'] ?? ''));
            if ($code === '') {
                return ToolResult::error('VALIDATION_ERROR', 'code ist erforderlich.');
            }

            $companyId = null;
            if (!empty($arguments['company_id'])) {
                if (!AssetCompany::where('team_id', $teamId)->whereKey((int) $arguments['company_id'])->exists()) {
                    return ToolResult::error('VALIDATION_ERROR', 'company_id gehört nicht zum Team.');
                }
                $companyId = (int) $arguments['company_id'];
            } elseif (!empty($arguments['company_key'])) {
                $companyId = AssetCompany::where('team_id', $teamId)->where('key', $arguments['company_key'])->value('id');
                if (!$companyId) {
                    return ToolResult::error('VALIDATION_ERROR', 'company_key nicht gefunden. Nutze asset-manager.companies.GET.');
                }
            }

            $existing = AssetCostCenter::where('team_id', $teamId)->where('code', $code)->first();
            if ($existing) {
                return ToolResult::success([
                    'id'      => $existing->id,
                    'code'    => $existing->code,
                    'label'   => $existing->label,
                    'created' => false,
                    'message' => "Kostenstelle '{$code}' existiert bereits.",
                ]);
            }

            $center = AssetCostCenter::create([
                'team_id'    => $teamId,
                'code'       => $code,
                'name'       => $arguments['name'] ?? null,
                'company_id' => $companyId,
                'is_active'  => array_key_exists('is_active', $arguments) ? (bool) $arguments['is_active'] : true,
                'notes'      => $arguments['notes'] ?? null,
            ]);

            return ToolResult::success([
                'id'      => $center->id,
                'code'    => $center->code,
                'label'   => $center->label,
                'created' => true,
                'message' => "Kostenstelle '{$center->label}' angelegt.",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen der Kostenstelle: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => false, 'risk_level' => 'write', 'tags' => ['asset-manager', 'cost-centers', 'master-data']];
    }
}
