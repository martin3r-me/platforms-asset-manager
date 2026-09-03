<?php

namespace Platform\AssetManager\Http\Controllers;

use Platform\AssetManager\Concerns\GuardsTenantBoundary;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Services\BillingStatementPdf;

/**
 * Download der User-Pauschale zu einem Rechnungslauf.
 *
 * Nur die Hülle: erzeugt wird das Dokument im {@see BillingStatementPdf} — denselben Service nutzt
 * der Rechnungslauf, der es an den easybill-Beleg hängt. Zwei Erzeugungswege hießen zwei Dokumente,
 * die irgendwann auseinanderlaufen.
 *
 * Owner/Admin ist nicht nötig (Lesen genügt), aber strikt gescoped: ein Lauf eines fremden **Teams**
 * wird mit 403 abgewiesen, einer eines fremden **Tenants** mit 404 (ADR 0016). Ein Export ist genau
 * der Pfad, auf dem ein Fremd-Datensatz das Haus verlassen würde.
 */
class BillingAttachmentPdfController
{
    use GuardsTenantBoundary;

    public function __invoke(AssetBillingRun $run, BillingStatementPdf $pdf)
    {
        if (! auth()->check()) {
            abort(401, 'Nicht authentifiziert');
        }

        $this->assertTenantBoundary($run);

        return response()->streamDownload(
            fn () => print($pdf->render($run)),
            $pdf->filename($run),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
