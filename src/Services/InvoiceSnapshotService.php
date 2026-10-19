<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Carbon;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;

/**
 * Schreibt den Monatsstand einer Rechnung fest ({@see AssetInvoiceSnapshot}, Discussion #26).
 *
 * Aufgerufen **aus dem Importer, innerhalb seiner Transaktion** — anders als das Import-Protokoll soll
 * ein Probelauf hier gerade nichts hinterlassen: der Rollback nimmt den Stand mit.
 *
 * Regeln:
 * - Identität ist Quelle + Rechnungskonto + Rechnungsnummer. Ein erneuter Import derselben Rechnung
 *   ersetzt ihren Stand (z. B. nach Korrektur einer Kostenstelle), legt aber keinen zweiten an.
 * - Ein Stand, der schon in einem DATEV-Stapel gebucht ist, bleibt unverändert; der Import meldet das.
 * - Buchungsmonat ist der **Leistungsmonat** = Monat, in dem der Leistungszeitraum endet (Hendric,
 *   2026-10-16: alle Rechnungen werden nach Leistungsdatum gebucht). So benennt auch Vodafone selbst:
 *   26.08.–25.09. ist „Abrechnungsmonat 202609". Der Importer übergibt ihn als `booking_month`. Fehlt der
 *   Leistungszeitraum, bleibt der Monat leer und der Import meldet es — die Rechnung taucht dann in
 *   keinem Stapel auf, statt in einem falschen.
 */
class InvoiceSnapshotService
{
    /**
     * @param array{source: string, account: string, invoice: string, invoice_date: ?\DateTimeInterface,
     *              booking_month: ?\DateTimeInterface, period_label: ?string, total_net: float} $document
     *        booking_month: ein Tag im Leistungsmonat (Ende des Leistungszeitraums); null = unbekannt
     *        distribution_is_key: die Verteilung ist nur Schlüssel (Monatswert), keine Summenprobe
     * @param iterable<array{cost_type_id: int, cost_center_id: ?int, amount: float}> $rows
     * @return array{snapshot: ?AssetInvoiceSnapshot, findings: list<string>}
     */
    public function capture(int $teamId, int $tenantId, array $document, iterable $rows): array
    {
        $findings = [];
        $identity = [
            'tenant_id' => $tenantId,
            'source'    => $document['source'],
            'account'   => (string) $document['account'],
            'invoice'   => (string) $document['invoice'],
        ];

        $existing = AssetInvoiceSnapshot::withoutTenantScope()->where($identity)->first();
        if ($existing?->isBooked()) {
            return ['snapshot' => $existing, 'findings' => [
                "Rechnung {$identity['invoice']} ist bereits per DATEV umgebucht — ihr Monatsstand bleibt unverändert.",
            ]];
        }

        $month = $document['booking_month'] ?? null;
        if ($month === null) {
            $findings[] = "Rechnung {$identity['invoice']} ohne Leistungszeitraum — kein Buchungsmonat, sie kann nicht umgebucht werden.";
        }

        $distribution = $this->distribution($teamId, $rows);
        $sum          = round(array_sum(array_column($distribution, 'amount')), 2);
        $total        = round((float) $document['total_net'], 2);
        if (! ($document['distribution_is_key'] ?? false) && abs($sum - $total) >= 0.005) {
            // Kein Fehler: verteilt wird der Rechnungsbetrag nach Anteilen (Largest Remainder). Aber eine
            // größere Abweichung heißt, dass Positionen fehlen — das soll vor dem Stapel auffallen.
            $findings[] = sprintf('Monatsstand: Verteilung %s € gegen Rechnungsnetto %s €.',
                number_format($sum, 2, ',', '.'), number_format($total, 2, ',', '.'));
        }

        $snapshot = AssetInvoiceSnapshot::withoutTenantScope()->updateOrCreate($identity, [
            'team_id'       => $teamId,
            'invoice_date'  => $document['invoice_date'] ?? null,
            'booking_month' => $month !== null ? Carbon::instance($month)->startOfMonth()->toDateString() : null,
            'period_label'  => $document['period_label'] ?? null,
            'total_net'     => $total,
            'distribution'  => $distribution,
            'captured_at'   => now(),
        ]);

        return ['snapshot' => $snapshot, 'findings' => $findings];
    }

    /**
     * Bricht ab, wenn die Rechnung zu einem **früheren** Leistungsmonat gehört als die zuletzt
     * importierte desselben Kontos (#948).
     *
     * Die Vodafone-Importer richten die Kostenpositionen auf die Datei aus und räumen alles ab, was
     * darin fehlt. Eine nachträglich hochgeladene Vormonatsrechnung hätte deshalb den aktuellen Stand
     * still zurückgedreht (Beträge, gekündigte Nummern gelöscht) — und den aktuellen Monatsstand
     * dauerhaft vom „Neu festschreiben" ausgeschlossen. Dieselbe Rechnung noch einmal (Korrektur) und
     * jeder neuere Monat bleiben erlaubt.
     */
    public function assertNotOlderThanLatest(int $tenantId, string $source, string $account, string $invoice, ?\DateTimeInterface $bookingMonth): void
    {
        if ($bookingMonth === null) {
            return;
        }

        $latest = AssetInvoiceSnapshot::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('source', $source)
            ->where('account', $account)
            ->where('invoice', '!=', $invoice)
            ->whereNotNull('booking_month')
            ->orderByDesc('booking_month')
            ->first(['invoice', 'booking_month']);

        if ($latest === null) {
            return;
        }

        $month = Carbon::instance($bookingMonth)->startOfMonth();
        if ($month->lt($latest->booking_month->copy()->startOfMonth())) {
            throw new \RuntimeException(sprintf(
                'Diese Rechnung (%s, Leistungsmonat %s) ist älter als die bereits importierte %s (%s). '
                . 'Ein Import würde den aktuellen Kostenstand auf den alten Monat zurückdrehen — abgebrochen.',
                $invoice,
                $month->format('m/Y'),
                $latest->invoice,
                $latest->booking_month->format('m/Y'),
            ));
        }
    }

    /**
     * Verteilung aus den Kostenpositionen einer importierten Rechnung (Vodafone Mobilfunk/Festnetz):
     * alle aktiven Zeilen der Quelle, deren `raw_data` Rechnungskonto und -nummer trägt.
     *
     * @return list<array{cost_type_id: int, cost_center_id: ?int, amount: float}>
     */
    public function rowsFromCostLines(int $teamId, int $tenantId, string $source, string $account, string $invoice): array
    {
        return AssetCostLine::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->where('source', $source)
            ->active()
            ->get(['cost_type_id', 'cost_center_id', 'amount', 'raw_data'])
            ->filter(function (AssetCostLine $line) use ($account, $invoice) {
                $raw = (array) $line->raw_data;

                return (string) ($raw['rechnungskonto'] ?? '') === $account && (string) ($raw['rechnung'] ?? '') === $invoice;
            })
            ->map(fn (AssetCostLine $line) => [
                'cost_type_id'   => (int) $line->cost_type_id,
                'cost_center_id' => $line->cost_center_id !== null ? (int) $line->cost_center_id : null,
                'amount'         => (float) $line->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Stand einer Rechnung aus den heutigen Kostenpositionen neu festschreiben — für den Fall, dass nach
     * dem Import eine Kostenstelle nachgetragen wurde. Geht nur, solange die Rechnung der aktuelle Stand
     * ihres Kontos ist (danach gehören die Zeilen der nächsten Rechnung) und nicht gebucht ist.
     *
     * @return array{snapshot: ?AssetInvoiceSnapshot, findings: list<string>}
     */
    public function recapture(AssetInvoiceSnapshot $snapshot): array
    {
        if ($snapshot->source === LicenseInvoiceImportService::SOURCE) {
            return ['snapshot' => $snapshot, 'findings' => ['Lizenz-Abrechnung: Monatsstand nur durch erneuten Import der Abrechnung.']];
        }

        $rows = $this->rowsFromCostLines($snapshot->team_id, $snapshot->tenant_id, $snapshot->source, $snapshot->account, $snapshot->invoice);
        if ($rows === []) {
            return ['snapshot' => $snapshot, 'findings' => [
                "Rechnung {$snapshot->invoice} ist nicht mehr der aktuelle Stand ihres Kontos — Monatsstand bleibt.",
            ]];
        }

        return $this->capture($snapshot->team_id, $snapshot->tenant_id, [
            'source'        => $snapshot->source,
            'account'       => $snapshot->account,
            'invoice'       => $snapshot->invoice,
            'invoice_date'  => $snapshot->invoice_date,
            // Monat neu aus dem Leistungszeitraum — so zieht ein Stand, der nach einer alten Regel
            // festgeschrieben wurde, beim Neu-Festschreiben in seinen Leistungsmonat.
            'booking_month' => self::serviceMonth($snapshot->period_label) ?? $snapshot->booking_month,
            'period_label'  => $snapshot->period_label,
            'total_net'     => (float) $snapshot->total_net,
        ], $rows);
    }

    /**
     * Leistungsmonat aus dem gespeicherten Zeitraum-Text: „26.08.2026–25.09.2026" → September
     * (Ende des Zeitraums), „2026-07" → Juli (Abrechnungsmonat der Lizenz-Abrechnung). null = nicht lesbar.
     */
    public static function serviceMonth(?string $periodLabel): ?\DateTimeImmutable
    {
        $label = trim((string) $periodLabel);

        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s*$/u', $label, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? new \DateTimeImmutable("{$m[3]}-{$m[2]}-01") : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $label, $m)) {
            return checkdate((int) $m[2], 1, (int) $m[1]) ? new \DateTimeImmutable("{$m[1]}-{$m[2]}-01") : null;
        }

        return null;
    }

    /**
     * Monatsstand zurücksetzen (löschen). Der nächste scharfe Import derselben Rechnung schreibt ihn neu
     * fest. Ein gebuchter Stand ist gesperrt — dafür erst den Stapel verwerfen, sonst verlöre das Protokoll
     * seine Rechnung.
     */
    public function reset(AssetInvoiceSnapshot $snapshot): void
    {
        if ($snapshot->isBooked()) {
            throw new \RuntimeException("Rechnung {$snapshot->invoice} ist in einem DATEV-Stapel gebucht — erst den Stapel verwerfen.");
        }

        $snapshot->delete();
    }

    /**
     * Alle offenen Monatsstände eines Buchungsmonats zurücksetzen; gebuchte bleiben.
     *
     * @return int Anzahl gelöschter Stände
     */
    public function resetMonth(int $teamId, int $tenantId, \DateTimeInterface $month): int
    {
        $first = \DateTimeImmutable::createFromInterface($month)->modify('first day of this month');

        return AssetInvoiceSnapshot::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->whereDate('booking_month', $first->format('Y-m-d'))
            ->whereNull('datev_batch_id')
            ->delete();
    }

    /**
     * Kurzform für das Import-Ergebnis (Vorschau, Import-Protokoll).
     *
     * @param array{snapshot: ?AssetInvoiceSnapshot, findings: list<string>} $captured
     * @return array{month: ?string, total: ?float, rows: int, findings: list<string>}
     */
    public static function summary(array $captured): array
    {
        $s = $captured['snapshot'];

        return [
            'month'    => $s?->booking_month?->format('Y-m'),
            'total'    => $s !== null ? (float) $s->total_net : null,
            'rows'     => count((array) $s?->distribution),
            'findings' => $captured['findings'],
        ];
    }

    /**
     * Verdichtet auf Kostenart × Kostenstelle, mit Schlüssel und Code im Klartext.
     *
     * @param iterable<array{cost_type_id: int, cost_center_id: ?int, amount: float}> $rows
     * @return list<array{cost_type_id: int, cost_type_key: ?string, cost_center_id: ?int, cost_center_code: ?string, amount: float, lines: int}>
     */
    protected function distribution(int $teamId, iterable $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['cost_type_id'] . '|' . ($row['cost_center_id'] ?? '');
            $grouped[$key] ??= ['cost_type_id' => (int) $row['cost_type_id'], 'cost_center_id' => $row['cost_center_id'], 'amount' => 0.0, 'lines' => 0];
            $grouped[$key]['amount'] += (float) $row['amount'];
            $grouped[$key]['lines']++;
        }

        $types   = AssetCostType::withoutTenantScope()->where('team_id', $teamId)->pluck('key', 'id');
        $centers = AssetCostCenter::withoutTenantScope()->where('team_id', $teamId)->pluck('code', 'id');

        $out = array_map(fn ($g) => [
            'cost_type_id'     => $g['cost_type_id'],
            'cost_type_key'    => $types[$g['cost_type_id']] ?? null,
            'cost_center_id'   => $g['cost_center_id'],
            'cost_center_code' => $g['cost_center_id'] !== null ? ($centers[$g['cost_center_id']] ?? null) : null,
            'amount'           => round($g['amount'], 2),
            'lines'            => $g['lines'],
        ], array_values($grouped));

        usort($out, fn ($a, $b) => [(string) $a['cost_type_key'], (string) $a['cost_center_code']] <=> [(string) $b['cost_type_key'], (string) $b['cost_center_code']]);

        return $out;
    }
}
