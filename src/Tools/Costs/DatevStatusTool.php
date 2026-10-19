<?php

namespace Platform\AssetManager\Tools\Costs;

use Platform\AssetManager\Models\AssetDatevBatch;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\DatevReallocationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\AssetManager\Tools\Concerns\ResolvesTenant;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Stand der DATEV-Umbuchung je Monat (#975, ADR 0027) — „wurde September schon umgebucht?".
 *
 * Liefert je Leistungsmonat die Monatsstände der Rechnungen (gebucht, direkt auf eine Kostenstelle, offen)
 * und die erzeugten Stapel. Die Datei selbst wird nicht ausgeliefert. Read-only.
 */
class DatevStatusTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;
    use ResolvesTenant;

    public function getName(): string
    {
        return 'asset-manager.datev.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/datev - Stand der DATEV-Umbuchung je Leistungsmonat: Monatsstände der '
            . 'Rechnungen (Quelle, Konto, Rechnungsnummer, Netto, Status booked|direct|open) und erzeugte Stapel '
            . '(Bezeichnung, Buchungen, Summe, verworfen). Optional month=YYYY-MM, sonst die letzten 6 Monate.';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => array_merge([
                'month' => ['type' => 'string', 'description' => 'Optional: Leistungsmonat YYYY-MM.'],
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

            if (! app(ControllingContext::class)->enabledFor($teamId)) {
                return ToolResult::error('CONTROLLING_DISABLED', 'Die Controlling-/Kosten-Schicht ist für diesen Tenant deaktiviert (Modul-Einstellungen).');
            }

            $month = trim((string) ($arguments['month'] ?? ''));
            if ($month !== '' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                return ToolResult::error('VALIDATION_ERROR', 'month muss das Format YYYY-MM haben.');
            }

            $from = $month !== '' ? $month . '-01' : now()->subMonths(5)->startOfMonth()->toDateString();
            $to   = $month !== '' ? now()->parse($month . '-01')->endOfMonth()->toDateString() : now()->endOfMonth()->toDateString();

            $snapshots = AssetInvoiceSnapshot::query()
                ->where('team_id', $teamId)
                ->whereBetween('booking_month', [$from, $to])
                ->orderBy('booking_month')->orderBy('source')->orderBy('invoice')
                ->get();

            $batches = AssetDatevBatch::query()
                ->where('team_id', $teamId)
                ->whereBetween('booking_month', [$from, $to])
                ->orderBy('booking_month')->orderBy('id')
                ->get();

            $months = [];
            foreach ($snapshots as $s) {
                $key = $s->booking_month->format('Y-m');
                $status = $s->isBooked() ? 'booked' : (DatevReallocationService::directCostCenter($s) !== null ? 'direct' : 'open');
                $months[$key]['invoices'][] = [
                    'source'    => $s->source,
                    'account'   => $s->account,
                    'invoice'   => $s->invoice,
                    'total_net' => (float) $s->total_net,
                    'status'    => $status,
                    'batch_id'  => $s->datev_batch_id,
                ];
            }
            foreach ($batches as $b) {
                $months[$b->booking_month->format('Y-m')]['batches'][] = [
                    'id'             => $b->id,
                    'label'          => $b->label,
                    'file_name'      => $b->file_name,
                    'bookings_count' => $b->bookings_count,
                    'total_eur'      => round($b->total_cents / 100, 2),
                    'voided'         => $b->isVoided(),
                    'void_reason'    => $b->void_reason,
                    'created_at'     => $b->created_at?->toIso8601String(),
                ];
            }

            ksort($months);
            $result = [];
            foreach ($months as $key => $m) {
                $invoices = $m['invoices'] ?? [];
                $result[] = [
                    'month'          => $key,
                    'open_invoices'  => count(array_filter($invoices, fn ($i) => $i['status'] === 'open')),
                    'fully_booked'   => $invoices !== [] && ! in_array('open', array_column($invoices, 'status'), true),
                    'invoices'       => $invoices,
                    'batches'        => $m['batches'] ?? [],
                ];
            }

            return ToolResult::success(['tenant_id' => $tenantId, 'months' => $result]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des DATEV-Stands: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'datev', 'costs']];
    }
}
