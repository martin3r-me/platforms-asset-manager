<?php

namespace Platform\AssetManager\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Platform\AssetManager\Concerns\GuardsTenantBoundary;
use Platform\AssetManager\Models\AssetBillingRun;

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
            'customerId'   => $run->profile?->easybill_customer_id,
            'quantity'     => $run->quantity,
            'unitPrice'    => $run->unitPrice(),
            'totalNet'     => $run->totalNet(),
            'basisLabel'   => $run->profile?->basisLabel() ?? '',
            'rows'         => $run->snapshotRows(),
            'skippedCount' => $run->skipped_count,
            'documentId'   => $run->easybill_document_id,
            'countedAt'    => $this->countedAt($run),
        ])->render();

        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($run->profile?->name ?? 'Kunde'));

        $filename = sprintf(
            'Leistungsnachweis_%s_%s.pdf',
            trim($slug, '_') ?: 'Kunde',
            $run->period,
        );

        return Pdf::loadHTML($html)->setPaper('a4')->download($filename);
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
