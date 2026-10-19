<?php

namespace Platform\AssetManager\Tools\Billing;

use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Services\BillingRunService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Rechnungsvorschau der Weiterberechnung, LLM-lesbar (#885, ADR 0021) — dieselbe Rechnung wie die
 * Seite „Weiterberechnung" ({@see BillingRunService::preview()}), ohne etwas zu senden.
 *
 * Beantwortet „wie viele User würden für Oktober abgerechnet, und warum nicht 152?" im Chat, statt dass
 * jemand die Seite öffnen und die Aufstellung abschreiben muss. Read-only: einen Entwurf legt nur die
 * Oberfläche an.
 */
class BillingPreviewTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    /** Obergrenze für die Namensliste in der Antwort. */
    private const MAX_ROWS = 500;

    public function getName(): string
    {
        return 'asset-manager.billing.preview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/billing/preview - Vorschau der Weiterberechnung (User-Pauschale) für einen '
            . 'Monat: Menge, Stückpreis und Summe (Cent und Euro), Preisquelle, Zählregel, Hinderungsgründe '
            . '(problems), Hinweise (warnings), Ausnahmen und — optional — die gezählten Träger. Ohne profile_id '
            . 'für alle aktiven Profile des Tenants. period = YYYY-MM (Default: aktueller Monat). Read-only, '
            . 'sendet nichts an easybill. Zeigt auch, ob für den Monat schon ein Entwurf existiert.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'profile_id'   => ['type' => 'integer', 'description' => 'Optional: nur dieses Abrechnungsprofil.'],
                'period'       => ['type' => 'string', 'description' => 'Abrechnungsmonat YYYY-MM (Default: aktueller Monat).'],
                'include_rows' => ['type' => 'boolean', 'description' => 'Gezählte Träger (Name, UPN, Abteilung) mitliefern (Default false, max. 500).'],
            ], $this->tenantSchemaProperty()),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            [$tenantId, $tenantError] = $this->resolveTenant($arguments, $context, $teamId);
            if ($tenantError) {
                return $tenantError;
            }
            TenantContext::forceTenant($tenantId);

            // Weiterberechnung gehört zur Controlling-Schicht (ADR 0008).
            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für diesen Tenant deaktiviert (Modul-Einstellungen).');
            }

            $period = trim((string) ($arguments['period'] ?? '')) ?: now()->format('Y-m');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                return ToolResult::error('VALIDATION_ERROR', 'period muss das Format YYYY-MM haben.');
            }

            $query = AssetBillingProfile::query()->where('team_id', $teamId);
            if (! empty($arguments['profile_id'])) {
                $query->whereKey((int) $arguments['profile_id']);
            } else {
                $query->where('active', true);
            }
            $profiles = $query->orderBy('name')->get();

            if ($profiles->isEmpty()) {
                return ToolResult::error('NOT_FOUND', empty($arguments['profile_id'])
                    ? 'Für diesen Tenant gibt es kein aktives Abrechnungsprofil.'
                    : 'Abrechnungsprofil nicht gefunden.');
            }

            $runs        = app(BillingRunService::class);
            $includeRows = (bool) ($arguments['include_rows'] ?? false);

            $result = $profiles->map(function (AssetBillingProfile $profile) use ($runs, $period, $includeRows) {
                $preview = $runs->preview($profile, $period);

                $lastRun = AssetBillingRun::query()
                    ->where('billing_profile_id', $profile->id)
                    ->where('period', $period)
                    ->latest('id')
                    ->first(['id', 'status', 'quantity', 'total_net_cents', 'easybill_document_id', 'created_at']);

                $row = [
                    'profile_id'       => $profile->id,
                    'profile'          => $profile->name,
                    'period'           => $period,
                    'period_label'     => $preview['period_label'],
                    'basis'            => $profile->basis,
                    'quantity'         => $preview['quantity'],
                    'unit_price_cents' => $preview['unit_price_cents'],
                    'total_net_cents'  => $preview['total_net_cents'],
                    'total_net_eur'    => round($preview['total_net_cents'] / 100, 2),
                    'price_source'     => $preview['price_source'],
                    'price_note'       => $preview['price_note'],
                    'skipped_count'    => count($preview['skipped']),
                    'exceptions'       => array_values($preview['exceptions']),
                    'problems'         => $preview['problems'],
                    'warnings'         => $preview['warnings'] ?? [],
                    'can_create_draft' => $preview['problems'] === [],
                    'last_run'         => $lastRun ? [
                        'id'                   => $lastRun->id,
                        'status'               => $lastRun->status,
                        'quantity'             => $lastRun->quantity,
                        'total_net_cents'      => $lastRun->total_net_cents,
                        'easybill_document_id' => $lastRun->easybill_document_id,
                        'created_at'           => $lastRun->created_at?->toIso8601String(),
                    ] : null,
                ];

                if ($includeRows) {
                    $row['rows']           = array_slice(array_values($preview['billable']), 0, self::MAX_ROWS);
                    $row['rows_truncated'] = count($preview['billable']) > self::MAX_ROWS;
                }

                return $row;
            })->values()->all();

            return ToolResult::success(['tenant_id' => $tenantId, 'profiles' => $result]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei der Rechnungsvorschau: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'billing', 'easybill']];
    }
}
