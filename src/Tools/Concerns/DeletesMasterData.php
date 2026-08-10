<?php

namespace Platform\AssetManager\Tools\Concerns;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\MasterDataDeletionService;
use Platform\AssetManager\Services\TenantContext;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Gemeinsame Hülle der vier Lösch-Tools (Kostenposition, Kostenstelle, Kostenart, Kreditor).
 *
 * Die Guard-Kette ist bei allen identisch — Team → Tenant → Controlling → Schreibrecht — und genau
 * dieselbe wie bei den Schreib-Tools. Sie hier einmal zu halten verhindert, dass ein späteres
 * Lösch-Tool eine Stufe vergisst; bei einem destruktiven Pfad wäre das die teuerste Art von Copy-Paste.
 *
 * Das Löschen selbst macht der {@see MasterDataDeletionService} — dieselbe Wahrheit wie im UI.
 */
trait DeletesMasterData
{
    use ResolvesTeam;
    use ResolvesTenant;

    /** Einheitliches Schema: id + optionaler tenant_id-Parameter. */
    protected function deleteSchema(string $idDescription): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'id' => ['type' => 'integer', 'description' => $idDescription],
            ], $this->tenantSchemaProperty()),
            'required' => ['id'],
        ];
    }

    /**
     * Guards durchlaufen und dann die Lösch-Methode des Services aufrufen.
     *
     * @param  string  $method  Methodenname auf {@see MasterDataDeletionService}.
     */
    protected function runDeletion(string $method, array $arguments, ToolContext $context, string $subject): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016): "all" ist hier NICHT zulässig — ein Löschen über alle Tenants
            // hinweg gibt es nicht. resolveTenant() lehnt es mit VALIDATION_ERROR ab.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Controlling-Schicht (ADR 0008): bei deaktiviertem Controlling sind Kosten/Stammdaten gesperrt.
            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für dieses Team deaktiviert (Modul-Einstellungen). Kosten, Kostenpositionen und Stammdaten sind daher nicht verfügbar.');
            }

            // Schreibrechte (ADR 0004): kanal-übergreifend Owner/Admin — identische Grenze wie im UI.
            if (! Gate::forUser($context->user)->allows('asset-manager.manage')) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $id = (int) ($arguments['id'] ?? 0);
            if ($id <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            $result = app(MasterDataDeletionService::class)->{$method}($teamId, $id);

            if (! $result['ok']) {
                // Nicht gefunden vs. bewusst gesperrt unterscheiden: das erste ist eine falsche ID,
                // das zweite eine fachliche Sperre, die das Modell dem Nutzer erklären soll.
                $code = str_contains($result['message'], 'nicht gefunden') ? 'NOT_FOUND' : 'DELETE_BLOCKED';

                return ToolResult::error($code, $result['message']);
            }

            return ToolResult::success($result + ['tenant_id' => $tenantId]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', "Fehler beim Löschen ({$subject}): " . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        // risk_level 'destructive': das Modell soll vor dem Aufruf rückfragen, nicht einfach löschen.
        return ['read_only' => false, 'risk_level' => 'destructive', 'tags' => ['asset-manager', 'delete']];
    }
}
