<?php

namespace Platform\AssetManager\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Platform\AssetManager\Concerns\GuardsTenantBoundary;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Models\AssetHolder;

/**
 * Leistungsnachweis eines Rechnungslaufs als PDF — die Aufstellung der abgerechneten Plätze, die
 * der Rechnung beiliegt.
 *
 * Erzeugt aus dem **Snapshot** des Laufs, nicht aus dem heutigen Bestand: der Nachweis gehört zu
 * einer Rechnung, und die ist ein Vorgang mit Datum. Würde er live gerechnet, zeigte er Monate
 * später eine andere Belegschaft als die, für die abgerechnet wurde.
 *
 * Owner/Admin ist nicht nötig (Lesen genügt), aber strikt gescoped: ein Lauf eines fremden **Teams**
 * wird mit 403 abgewiesen, einer eines fremden **Tenants** mit 404 (ADR 0016). Ein Export ist genau
 * der Pfad, auf dem ein Fremd-Datensatz das Haus verlassen würde.
 */
class BillingAttachmentPdfController
{
    use GuardsTenantBoundary;

    public function __invoke(AssetBillingRun $run)
    {
        if (! auth()->check()) {
            abort(401, 'Nicht authentifiziert');
        }

        $this->assertTenantBoundary($run);

        $run->load('profile');

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

        // Namensschema wie bisher von Hand vergeben (`User_pauschale_07_2026.pdf`) — der Anhang soll
        // in easybill neben den älteren stehen, ohne dass jemand zweimal hinsehen muss.
        [$year, $month] = array_pad(explode('-', $run->period), 2, '');

        $filename = sprintf('User_pauschale_%s_%s.pdf', $month, $year);

        return Pdf::loadHTML($html)->setPaper('a4')->download($filename);
    }

    /**
     * Fehlende Namen und Abteilungen aus dem Trägerbestand ergänzen.
     *
     * **Wer** abgerechnet wurde, steht unverrückbar im Snapshot — das ist die Menge, auf der die
     * Rechnung beruht, und daran ändert sich hier nichts. Name und Abteilung sind dagegen bloße
     * Lesehilfen: sie machen aus einer Liste von Benutzerkonten ein lesbares Dokument.
     *
     * Nötig ist das für Läufe von vor der Snapshot-Erweiterung — die führen nur UPNs, und ohne
     * Nachschlagen stünde in der Namensspalte eine E-Mail-Adresse und bei der Abteilung ein
     * Strich. Gefunden wird über die UPN, kleingeschrieben verglichen, weil sie aus verschiedenen
     * Quellen unterschiedlich geschrieben ankommt.
     *
     * @return array<int, array{upn: string, name: string, department: string|null}>
     */
    protected function enrichRows(AssetBillingRun $run): array
    {
        $rows = $run->snapshotRows();

        // Zeilen, denen ein Name fehlt — erkennbar daran, dass er der UPN entspricht (Fallback).
        $needsLookup = array_filter(
            $rows,
            fn (array $row) => ($row['name'] ?? '') === ($row['upn'] ?? '') || blank($row['department'] ?? null),
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
            ->get(['user_principal_name', 'display_name', 'department'])
            ->keyBy(fn ($holder) => mb_strtolower((string) $holder->user_principal_name));

        return array_map(function (array $row) use ($holders) {
            $holder = $holders->get(mb_strtolower((string) $row['upn']));

            if ($holder === null) {
                return $row;
            }

            if (($row['name'] ?? '') === $row['upn'] && filled($holder->display_name)) {
                $row['name'] = $holder->display_name;
            }

            if (blank($row['department'] ?? null)) {
                $row['department'] = $holder->department;
            }

            return $row;
        }, $rows);
    }

    /** 'YYYY-MM' → 'September 2026'. Bewusst hier und nicht über die Carbon-Lokalisierung. */
    protected function periodLabel(string $period): string
    {
        $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
            'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        [$year, $month] = array_pad(explode('-', $period), 2, null);

        return ($months[(int) $month] ?? $month) . ' ' . $year;
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
