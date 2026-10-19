<?php

namespace Platform\AssetManager\Tools\Holders;

use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetHolderEvent;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Asset-Träger von Hand anlegen (#881).
 *
 * Bisher entstand jeder Träger aus dem Verzeichnis oder aus Sync-Daten — ein Tenant ohne
 * Microsoft-Anbindung (laut CONTEXT.md ein vollwertiger „Manuell-Kunde") konnte keinem Menschen ein
 * Gerät zuordnen. Bewusst dünn: Normalisierung, Eindeutigkeit, Klassifizierung und Kostenstellen-
 * Auflösung liegen in {@see HolderService::createManual()}, den sich das Tool mit dem Anlege-Modal
 * der Trägerliste teilt.
 */
class CreateHolderTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.holders.POST';
    }

    public function getDescription(): string
    {
        return 'POST /asset-manager/holders - Legt EINEN Asset-Träger von Hand an (source=manual), etwa '
            . 'für einen Tenant ohne Microsoft-Anbindung oder für Externe. Pflicht: user_principal_name '
            . '(Identitätsanker, eindeutig je Tenant ohne Rücksicht auf Groß-/Kleinschreibung; ein '
            . 'Papierkorb-Präfix aus Entra wird entfernt). Optional: display_name, email (Default = UPN), '
            . 'holder_type (person|function|admin|service|external — angegeben wird er festgehalten, '
            . 'sonst bestimmen ihn die Erkennungsregeln), cost_center (Code einer bestehenden '
            . 'Kostenstelle), department, job_title. Existiert die UPN schon, kommt DUPLICATE mit '
            . 'existing_id. Ein späterer Verzeichnis-Abgleich findet den Träger per UPN und reichert ihn '
            . 'an; stillgelegt wird ein manueller Träger vom Abgleich nie.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'user_principal_name' => ['type' => 'string', 'description' => 'UPN / Anmeldename (Pflicht), z. B. max.muster@firma.de.'],
                'display_name'        => ['type' => 'string', 'description' => 'Anzeigename.'],
                'email'               => ['type' => 'string', 'description' => 'E-Mail (Default: die UPN).'],
                'holder_type'         => ['type' => 'string', 'enum' => AssetHolder::TYPES, 'description' => 'Träger-Typ (ADR 0017). Weglassen = Erkennungsregeln entscheiden.'],
                'cost_center'         => ['type' => 'string', 'description' => 'Kostenstellen-Code (muss im Tenant existieren, siehe asset-manager.cost-centers.GET).'],
                'department'          => ['type' => 'string', 'description' => 'Abteilung.'],
                'job_title'           => ['type' => 'string', 'description' => 'Position.'],
            ], $this->tenantSchemaProperty()),
            'required' => ['user_principal_name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            // Tenant-Grenze (ADR 0016) vor jeder Query setzen.
            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Schreibrechte (ADR 0004): dieselbe Grenze wie im UI, unabhängig vom Kanal.
            if (! Gate::forUser($context->user)->allows('asset-manager.manage', [$teamId])) {
                return ToolResult::error('ACCESS_DENIED', 'Diese Aktion erfordert die Rolle Owner oder Admin im Team.');
            }

            $actorId = isset($context->user->id) ? (int) $context->user->id : null;

            // Urheber für den Verlauf (#897): eine Kostenstelle bei der Anlage ist die erste Buchung.
            $result = AssetHolderEvent::actingAs($actorId, fn () => app(HolderService::class)->createManual(
                $teamId,
                (int) $tenantId,
                [
                    'user_principal_name' => $arguments['user_principal_name'] ?? null,
                    'display_name'        => $arguments['display_name'] ?? null,
                    'email'               => $arguments['email'] ?? null,
                    'holder_type'         => $arguments['holder_type'] ?? null,
                    'cost_center'         => $arguments['cost_center'] ?? null,
                    'department'          => $arguments['department'] ?? null,
                    'job_title'           => $arguments['job_title'] ?? null,
                ],
            ));

            if (! $result['ok']) {
                $message = $result['message']
                    . (isset($result['existing_id']) ? " (existing_id={$result['existing_id']})" : '');

                return ToolResult::error($result['code'] ?? 'VALIDATION_ERROR', $message);
            }

            /** @var AssetHolder $holder */
            $holder = $result['holder'];

            return ToolResult::success([
                'id'                  => $holder->id,
                'name'                => $holder->name,
                'user_principal_name' => $holder->user_principal_name,
                'email'               => $holder->email,
                'holder_type'         => $holder->holder_type,
                'holder_type_pinned'  => (bool) $holder->holder_type_pinned,
                'cost_center'         => $holder->cost_center,
                'cost_center_id'      => $holder->cost_center_id,
                'department'          => $holder->department,
                'job_title'           => $holder->job_title,
                'source'              => $holder->source,
                'tenant_id'           => (int) $holder->tenant_id,
                'message'             => $result['message'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen des Asset-Trägers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category'      => 'mutation',
            'tags'          => ['asset-manager', 'holder', 'create'],
            'read_only'     => false,
            'requires_auth' => true,
            'risk_level'    => 'write',
        ];
    }
}
