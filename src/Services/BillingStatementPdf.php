<?php

namespace Platform\AssetManager\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Models\AssetHolder;

/**
 * Die User-Pauschale als PDF — die Aufstellung der abgerechneten Plätze, die der Rechnung beiliegt.
 *
 * Als Service und nicht im Controller, weil es zwei Abnehmer gibt: den Download in der Oberfläche
 * und den Rechnungslauf, der dasselbe Dokument an den easybill-Beleg hängt. Zwei Erzeugungswege
 * hießen zwei Dokumente, die irgendwann auseinanderlaufen — und ausgerechnet der angehängte wäre
 * der, den niemand nachprüft.
 *
 * Erzeugt aus dem **Snapshot** des Laufs, nicht aus dem heutigen Bestand: der Nachweis gehört zu
 * einer Rechnung, und die ist ein Vorgang mit Datum.
 */
class BillingStatementPdf
{
    /** Monatsnamen ausgeschrieben — nicht über die Carbon-Lokalisierung, die nicht gesetzt sein muss. */
    protected const MONTHS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    /** Die fertigen PDF-Bytes. */
    public function render(AssetBillingRun $run): string
    {
        $run->loadMissing('profile');

        $html = view('asset-manager::pdf.billing-attachment', [
            'profileName'  => $run->profile?->name ?? 'Weiterberechnung',
            'periodLabel'  => $this->periodLabel($run->period),
            'customerName' => $run->profile?->easybill_customer_name,
            'quantity'     => $run->quantity,
            'unitPrice'    => $run->unitPrice(),
            'totalNet'     => $run->totalNet(),
            'basisLabel'   => $run->profile?->basisLabel() ?? '',
            'rows'         => $this->enrichRows($run),
            'skippedCount' => $run->skipped_count,
            'documentId'   => $run->easybill_document_id,
            'countedAt'    => $this->countedAt($run),
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * Dateiname im Schema, das bisher von Hand vergeben wurde (`User_pauschale_07_2026.pdf`) — der
     * Anhang soll in easybill neben den älteren stehen, ohne dass jemand zweimal hinsehen muss.
     */
    public function filename(AssetBillingRun $run): string
    {
        [$year, $month] = array_pad(explode('-', $run->period), 2, '');

        return sprintf('User_pauschale_%s_%s.pdf', $month, $year);
    }

    /**
     * Fehlende Namen aus dem Trägerbestand ergänzen.
     *
     * **Wer** abgerechnet wurde, steht unverrückbar im Snapshot — das ist die Menge, auf der die
     * Rechnung beruht, und daran ändert sich hier nichts. Der Name ist dagegen eine bloße Lesehilfe:
     * er macht aus einer Liste von Benutzerkonten ein lesbares Dokument.
     *
     * Nötig ist das für Läufe von vor der Snapshot-Erweiterung — die führen nur UPNs, und ohne
     * Nachschlagen stünde in der Namensspalte eine E-Mail-Adresse.
     *
     * @return array<int, array{upn: string, name: string, department: string|null}>
     */
    public function enrichRows(AssetBillingRun $run): array
    {
        $rows = $run->snapshotRows();

        // Zeilen, denen ein Name fehlt — erkennbar daran, dass er der UPN entspricht (Fallback).
        $needsLookup = array_filter(
            $rows,
            fn (array $row) => ($row['name'] ?? '') === ($row['upn'] ?? ''),
        );

        if ($needsLookup === []) {
            return $rows;
        }

        // Kleingeschrieben vergleichen, und zwar in SQL: die UPN steht im Snapshot so, wie die
        // Quelle sie geliefert hat, in der Trägertabelle womöglich anders. Ein schlichtes `whereIn`
        // fände sie nur auf MySQL (das ohne Rücksicht auf Groß-/Kleinschreibung vergleicht), auf
        // SQLite dagegen nicht — dasselbe PDF käme lokal und live verschieden heraus.
        $needles = array_values(array_unique(array_map(
            fn (array $row) => mb_strtolower((string) $row['upn']),
            $needsLookup,
        )));

        $placeholders = implode(',', array_fill(0, count($needles), '?'));

        $holders = AssetHolder::withoutTenantScope()
            ->forTenant((int) $run->tenant_id)
            ->where('team_id', $run->team_id)
            ->whereRaw("LOWER(user_principal_name) IN ({$placeholders})", $needles)
            ->get(['user_principal_name', 'display_name'])
            ->keyBy(fn ($holder) => mb_strtolower((string) $holder->user_principal_name));

        return array_map(function (array $row) use ($holders) {
            $holder = $holders->get(mb_strtolower((string) $row['upn']));

            if ($holder !== null && ($row['name'] ?? '') === $row['upn'] && filled($holder->display_name)) {
                $row['name'] = $holder->display_name;
            }

            return $row;
        }, $rows);
    }

    /** 'YYYY-MM' → 'September 2026'. */
    public function periodLabel(string $period): string
    {
        [$year, $month] = array_pad(explode('-', $period), 2, null);

        return (self::MONTHS[(int) $month] ?? $month) . ' ' . $year;
    }

    protected function countedAt(AssetBillingRun $run): string
    {
        $raw = $run->snapshot['counted_at'] ?? null;

        try {
            return $raw ? Carbon::parse($raw)->format('d.m.Y H:i') : $run->created_at?->format('d.m.Y H:i') ?? '—';
        } catch (\Throwable) {
            return $run->created_at?->format('d.m.Y H:i') ?? '—';
        }
    }
}
