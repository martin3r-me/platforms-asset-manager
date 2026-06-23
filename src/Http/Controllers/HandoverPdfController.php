<?php

namespace Platform\AssetManager\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Platform\AssetManager\Models\AssetHandover;

/**
 * Übergabeprotokoll als PDF — on-the-fly aus signature_data + device_snapshot (kein gespeichertes File).
 * Owner/Admin ist nicht nötig (Lesen erlaubt), aber strikt team-scoped: ein Protokoll eines fremden
 * Teams wird mit 403 abgewiesen (Muster IssuePdfController / kanal-unabhängige Team-Grenze).
 */
class HandoverPdfController
{
    public function __invoke(AssetHandover $handover)
    {
        if (! auth()->check()) {
            abort(401, 'Nicht authentifiziert');
        }

        $teamId = auth()->user()->currentTeam?->id;
        if (! $teamId || $handover->team_id !== $teamId) {
            abort(403, 'Zugriff verweigert');
        }

        $handover->load(['employee', 'lines']);

        $html = view('asset-manager::pdf.handover', ['handover' => $handover])->render();

        $recipient = $handover->employee?->user_principal_name
            ?? $handover->employee?->display_name
            ?? 'Empfaenger';
        $recipientSlug = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $recipient);

        $filename = sprintf(
            'Geraeteausgabe_%s_%s.pdf',
            trim($recipientSlug, '-') ?: 'UNK',
            $handover->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d')
        );

        return Pdf::loadHTML($html)
            ->setPaper('a4')
            ->download($filename);
    }
}
