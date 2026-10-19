<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Support\LicensePriceBook;

/**
 * Lizenz-Reclaim (#970): aktive Träger mit bezahlter Lizenz, die sich seit N Tagen nicht angemeldet haben.
 *
 * Abgrenzung zu #884 (Offboarding): dort stillgelegte Träger, hier **aktive** Konten ohne Nutzung. Die
 * letzte Anmeldung kommt aus Entra (`last_sign_in_at`, gepflegt vom Verzeichnis-Abgleich) und fehlt ohne
 * AuditLog.Read.All / Entra P1 — dann ist die Liste leer und `available` false, statt zu raten.
 * Träger ohne bekanntes Anmeldedatum zählen NICHT als ungenutzt (unbekannt ≠ nie).
 */
class LicenseReclaimService
{
    public const DEFAULT_DAYS = 90;

    /**
     * @return array{available: bool, days: int, count: int, monthly: float, rows: list<array{holder_id: int, name: string, upn: string, last_sign_in_at: string, licenses: list<string>, monthly: float}>}
     */
    public function candidates(int $teamId, int $days = self::DEFAULT_DAYS): array
    {
        $available = AssetHolder::where('team_id', $teamId)->whereNotNull('last_sign_in_at')->exists();

        $stale = AssetHolder::where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('last_sign_in_at')
            ->where('last_sign_in_at', '<', now()->subDays($days))
            ->get(['id', 'display_name', 'user_principal_name', 'last_sign_in_at'])
            ->keyBy(fn (AssetHolder $h) => mb_strtolower((string) $h->user_principal_name));

        $rows = [];
        if ($stale->isNotEmpty()) {
            $book     = LicensePriceBook::for($teamId);
            $skuNames = AssetLicenseSku::where('team_id', $teamId)->get(['sku_id', 'display_name', 'sku_part_number'])
                ->mapWithKeys(fn ($s) => [(string) $s->sku_id => $s->display_name ?: $s->sku_part_number]);

            foreach ($book->seatRows()->groupBy(fn ($r) => mb_strtolower((string) $r['upn'])) as $upn => $seats) {
                $holder = $stale->get($upn);
                if ($holder === null) {
                    continue;
                }
                $rows[] = [
                    'holder_id'       => (int) $holder->id,
                    'name'            => (string) ($holder->display_name ?: $holder->user_principal_name),
                    'upn'             => (string) $holder->user_principal_name,
                    'last_sign_in_at' => $holder->last_sign_in_at->toDateString(),
                    'licenses'        => $seats->map(fn ($s) => (string) ($skuNames[$s['sku_id']] ?? $s['sku_id']))->unique()->values()->all(),
                    'monthly'         => round((float) $seats->sum('amount'), 2),
                ];
            }
            usort($rows, fn ($a, $b) => $b['monthly'] <=> $a['monthly']);
        }

        return [
            'available' => $available,
            'days'      => $days,
            'count'     => count($rows),
            'monthly'   => round(array_sum(array_column($rows, 'monthly')), 2),
            'rows'      => $rows,
        ];
    }
}
