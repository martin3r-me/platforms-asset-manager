<?php

namespace Platform\AssetManager\Jobs;

use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\IntuneGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncLicensesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    protected array $skuNames = [
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM'    => 'Microsoft 365 Business Standard',
        'SPB'                      => 'Microsoft 365 Business Premium',
        'ENTERPRISEPACK'           => 'Office 365 E3',
        'ENTERPRISEPREMIUM'        => 'Office 365 E5',
        'POWER_AUTOMATE_PER_USER'  => 'Power Automate Premium',
        'Copilot_For_M365'         => 'Microsoft 365 Copilot',
        'EXCHANGESTANDARD'         => 'Exchange Online Plan 1',
        'EXCHANGEENTERPRISE'       => 'Exchange Online Plan 2',
    ];

    public function __construct(
        public readonly int $teamId
    ) {}

    public function handle(IntuneGraphService $service): void
    {
        $config = AssetConnectorConfig::where('team_id', $this->teamId)
            ->where('enabled', true)
            ->first();

        if (!$config || !$config->isConfigured()) {
            Log::warning('AssetManager: Lizenz-Sync übersprungen — Connector nicht konfiguriert', ['team_id' => $this->teamId]);
            return;
        }

        $startedAt = now();
        $log = AssetLicenseSyncLog::create([
            'team_id'    => $this->teamId,
            'status'     => 'started',
            'started_at' => $startedAt,
        ]);

        try {
            // 1. SKUs abrufen
            $skus = $service->getSubscribedSkus($config);
            if ($skus === null) {
                $this->markFailed($log, $startedAt, $service->lastError ?? 'SKU-Abruf fehlgeschlagen.');
                return;
            }

            foreach ($skus as $sku) {
                $purchased = $sku['prepaidUnits']['enabled'] ?? 0;
                $consumed  = $sku['consumedUnits'] ?? 0;
                $available = max(0, $purchased - $consumed);
                $partNum   = $sku['skuPartNumber'] ?? '';

                $existing = AssetLicenseSku::where('team_id', $this->teamId)
                    ->where('sku_id', $sku['skuId'])
                    ->first();

                $data = [
                    'sku_part_number' => $partNum,
                    'display_name'    => $this->skuNames[$partNum] ?? $partNum,
                    'purchased_units' => $purchased,
                    'consumed_units'  => $consumed,
                    'available_units' => $available,
                    'synced_at'       => now(),
                    'raw_data'        => $sku,
                ];

                if ($existing) {
                    // unit_price NIEMALS überschreiben
                    $existing->update($data);
                } else {
                    AssetLicenseSku::create(array_merge($data, [
                        'team_id'    => $this->teamId,
                        'sku_id'     => $sku['skuId'],
                        'unit_price' => null,
                    ]));
                }
            }

            // SKU-Lookup für Part Numbers
            $skuPartMap = collect($skus)->keyBy('skuId')->map(fn($s) => $s['skuPartNumber'] ?? '');

            // 2. User-Lizenzen abrufen
            $users = $service->getUsersWithLicenses($config);
            if ($users === null) {
                $this->markFailed($log, $startedAt, $service->lastError ?? 'User-Abruf fehlgeschlagen.');
                return;
            }

            $added     = 0;
            $seenKeys  = [];

            foreach ($users as $user) {
                $upn         = $user['userPrincipalName'] ?? null;
                $displayName = $user['displayName']       ?? null;

                if (!$upn) continue;

                foreach (($user['assignedLicenses'] ?? []) as $license) {
                    $skuId   = $license['skuId'] ?? null;
                    if (!$skuId) continue;

                    $partNum    = $skuPartMap[$skuId] ?? null;
                    $seenKeys[] = $upn . '|' . $skuId;

                    $existing = AssetUserLicense::where('team_id', $this->teamId)
                        ->where('user_principal_name', $upn)
                        ->where('sku_id', $skuId)
                        ->first();

                    $data = [
                        'sku_part_number'     => $partNum,
                        'display_name'        => $displayName,
                        'raw_data'            => $license,
                    ];

                    if (!$existing) {
                        AssetUserLicense::create(array_merge($data, [
                            'team_id'             => $this->teamId,
                            'sku_id'              => $skuId,
                            'user_principal_name' => $upn,
                            'assigned_at'         => now(),
                        ]));
                        $added++;
                    } else {
                        $existing->update($data);
                    }
                }
            }

            // Einträge entfernen, die nicht mehr in Graph vorhanden sind
            $allExisting = AssetUserLicense::where('team_id', $this->teamId)->get();
            $removed = 0;
            foreach ($allExisting as $entry) {
                $key = $entry->user_principal_name . '|' . $entry->sku_id;
                if (!in_array($key, $seenKeys)) {
                    $entry->delete();
                    $removed++;
                }
            }

            $totalAssignments = AssetUserLicense::where('team_id', $this->teamId)->count();
            $durationMs       = (int) ($startedAt->diffInMilliseconds(now()));

            $log->update([
                'status'              => 'success',
                'skus_synced'         => count($skus),
                'assignments_synced'  => $totalAssignments,
                'assignments_added'   => $added,
                'assignments_removed' => $removed,
                'duration_ms'         => $durationMs,
                'completed_at'        => now(),
            ]);

            Log::info('AssetManager: Lizenz-Sync erfolgreich', [
                'team_id'  => $this->teamId,
                'skus'     => count($skus),
                'total'    => $totalAssignments,
                'added'    => $added,
                'removed'  => $removed,
                'duration' => $durationMs . 'ms',
            ]);

        } catch (\Throwable $e) {
            $this->markFailed($log, $startedAt, $e->getMessage());
            Log::error('AssetManager: Lizenz-Sync Exception', [
                'team_id' => $this->teamId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function markFailed(AssetLicenseSyncLog $log, \Carbon\Carbon $startedAt, string $message): void
    {
        $log->update([
            'status'        => 'error',
            'error_message' => $message,
            'duration_ms'   => (int) ($startedAt->diffInMilliseconds(now())),
            'completed_at'  => now(),
        ]);
    }
}
