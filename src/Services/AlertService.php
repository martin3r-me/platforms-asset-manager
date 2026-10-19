<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetImportRun;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Support\LicenseContractInsights;

/**
 * „Fristen & Warnungen" (#973) — was diese Woche jemand ansehen sollte, an EINER Stelle.
 *
 * Entscheidung 2026-10-19: kein Push (keine Mail, kein Plattform-Benachrichtigungsmodul), sondern eine
 * Dashboard-Kachel und ein MCP-Tool (`asset-manager.alerts.GET`), das man im Chat abfragt. Die Quellen
 * gab es alle schon — verstreut auf fünf Seiten, und nichts davon erinnerte von selbst.
 *
 * Arbeitet im aktiven Tenant (Global Scope); kaufmännische Punkte nur mit Controlling (ADR 0008).
 */
class AlertService
{
    /** Ab so vielen Fehlläufen in Folge meldet ein Sync. */
    public const SYNC_ERROR_STREAK = 2;

    /** Älter als so viele Stunden gilt der Verzeichnis-Abgleich als veraltet. */
    public const DIRECTORY_STALE_HOURS = 48;

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, route: ?string, params: array<string, mixed>}>
     */
    public function collect(int $teamId, bool $controllingEnabled): array
    {
        $alerts = [];
        $today  = now()->startOfDay();
        $soon   = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);

        // ---- Fristen: Garantie/Leasing --------------------------------------------------------
        $expiring = AssetDevice::where('team_id', $teamId)
            ->where(fn ($q) => $q->whereBetween('warranty_until', [$today, $soon])->orWhereBetween('lease_until', [$today, $soon]))
            ->count();
        if ($expiring > 0) {
            $alerts[] = $this->alert('device_expiring', 'medium', "{$expiring} Gerät(e): Garantie/Leasing endet in 90 Tagen",
                'Verlängern, ersetzen oder zurückgeben planen.', 'asset-manager.devices.index', ['preset' => 'expiring']);
        }

        // ---- Fristen: Kündigungsfenster der Lizenzverträge (kaufmännisch) ----------------------
        if ($controllingEnabled) {
            $period = AssetLicenseContractLine::where('team_id', $teamId)->max('period_label');
            if ($period !== null) {
                $lines = AssetLicenseContractLine::where('team_id', $teamId)->forPeriod((string) $period)->get();
                $radar = LicenseContractInsights::cancellationRadar($lines, now());
                if ($radar['soon_count'] > 0) {
                    $alerts[] = $this->alert('license_cancel_window', 'high', "{$radar['soon_count']} Lizenzvertrag/-verträge: Kündigungsfenster in 90 Tagen",
                        number_format($radar['soon_amount'], 2, ',', '.') . ' € / Monat — vor dem Stichtag entscheiden, sonst verlängert sich die Jahresbindung.',
                        'asset-manager.licenses.contracts', ['filter' => 'cancel_soon']);
                }
                if ($radar['missing_count'] > 0) {
                    $alerts[] = $this->alert('license_cancel_missing', 'low', "{$radar['missing_count']} Jahresbindung(en) ohne Kündbar-Datum",
                        'Ohne Datum meldet der Radar das Fenster nicht.', 'asset-manager.licenses.contracts', ['filter' => 'cancel_missing']);
                }
            }

            // Unklar ausgegangene Rechnungsläufe (#940) blockieren ihren Monat, bis jemand nachsieht.
            $pending = AssetBillingRun::where('team_id', $teamId)->where('status', AssetBillingRun::STATUS_PENDING)
                ->where('created_at', '<', now()->subMinutes(10))->count();
            if ($pending > 0) {
                $alerts[] = $this->alert('billing_pending', 'high', "{$pending} Rechnungslauf/-läufe unklar ausgegangen",
                    'In easybill nachsehen, ob ein Beleg existiert, dann in der Historie freigeben.', 'asset-manager.billing.index', []);
            }
        }

        // ---- Syncs: wiederholte Fehler ----------------------------------------------------------
        foreach ([
            'device_sync'  => [AssetDeviceSyncLog::class, 'Geräte-Sync (Intune)'],
            'license_sync' => [AssetLicenseSyncLog::class, 'Lizenz-Sync'],
        ] as $key => [$class, $label]) {
            $last = $class::where('team_id', $teamId)->orderByDesc('started_at')->limit(self::SYNC_ERROR_STREAK)->pluck('status');
            if ($last->count() === self::SYNC_ERROR_STREAK && $last->every(fn ($s) => $s === 'error')) {
                $alerts[] = $this->alert($key . '_failing', 'high', "{$label} schlägt wiederholt fehl",
                    'Die letzten ' . self::SYNC_ERROR_STREAK . ' Läufe endeten mit Fehler — Anbindung prüfen.', 'asset-manager.setup', []);
            }
        }

        // ---- Verzeichnis-Abgleich: veraltet (die Weiterberechnung zählt is_active, #954) --------
        $hasConnector = AssetConnectorConfig::where('team_id', $teamId)->where('enabled', true)->exists();
        if ($hasConnector) {
            $lastOk = AssetImportRun::where('team_id', $teamId)->where('source', AssetImportRun::SOURCE_DIRECTORY)
                ->where('status', '!=', AssetImportRun::STATUS_ERROR)->where('dry_run', false)->max('created_at');
            if ($lastOk === null || now()->parse($lastOk)->lt(now()->subHours(self::DIRECTORY_STALE_HOURS))) {
                $alerts[] = $this->alert('directory_stale', 'medium', 'Verzeichnis-Abgleich veraltet',
                    $lastOk === null ? 'Lief noch nie — ausgeschiedene Träger werden nicht stillgelegt.'
                        : 'Letzter erfolgreicher Lauf ' . now()->parse($lastOk)->format('d.m.Y H:i') . ' — ausgeschiedene Träger zählen evtl. noch mit.',
                    'asset-manager.setup', []);
            }
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($alerts, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $alerts;
    }

    /** @return array{key: string, severity: string, title: string, detail: string, route: ?string, params: array<string, mixed>} */
    private function alert(string $key, string $severity, string $title, string $detail, ?string $route, array $params): array
    {
        return compact('key', 'severity', 'title', 'detail', 'route', 'params');
    }
}
