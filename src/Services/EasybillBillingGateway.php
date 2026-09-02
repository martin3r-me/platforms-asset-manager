<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * {@see BillingGateway} gegen den easybill-Connector des Integrations-Moduls.
 *
 * Die Kopplung ist absichtlich lose: die fremden Klassen werden zur Laufzeit über den Container
 * aufgelöst, nicht importiert, und `platform-integrations` steht nicht in unserer `composer.json`.
 * Fehlt das Modul, meldet {@see isAvailable()} das und die Oberfläche blendet den Rechnungslauf aus,
 * statt beim ersten Klick mit einem Klassenfehler abzustürzen.
 *
 * **Was diese Klasse nicht tut:** festschreiben, versenden, stornieren. Der Connector kann das alles
 * (`completeDocument`, `sendDocument`, `cancelDocument`), aber diese Wege sind hier nicht angebunden.
 * easybill legt jeden Beleg ohnehin als Entwurf an — `is_draft` ist dort read-only, das Finalisieren
 * ist ein eigener Aufruf. Ein Fehler auf unserer Seite kann damit keine gültige Rechnung erzeugen,
 * sondern höchstens einen Entwurf, den man löscht.
 */
class EasybillBillingGateway implements BillingGateway
{
    protected const API_SERVICE = '\Platform\Integrations\Services\EasybillApiService';

    protected ?string $reason = null;

    public function isAvailable(): bool
    {
        if (! class_exists(self::API_SERVICE)) {
            $this->reason = 'Das Integrations-Modul ist in dieser Installation nicht vorhanden.';

            return false;
        }

        if (! Auth::check()) {
            $this->reason = 'Kein angemeldeter Benutzer — die easybill-Verbindung hängt am Benutzerkonto.';

            return false;
        }

        return true;
    }

    public function unavailableReason(): ?string
    {
        return $this->reason;
    }

    public function createDraftInvoice(array $document): int
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException($this->reason ?? 'Rechnungssystem nicht verfügbar.');
        }

        try {
            $result = app(self::API_SERVICE)->createDocument(Auth::user(), $document);
        } catch (Throwable $e) {
            // Die Ausnahmetypen des Connectors (EasybillApiException) fangen wir bewusst nicht
            // typisiert — das wäre wieder ein harter Verweis auf das Fremdmodul. Die Meldung wird
            // durchgereicht und landet im Lauf-Protokoll.
            throw new RuntimeException('easybill: ' . $e->getMessage(), 0, $e);
        }

        $documentId = $result['id'] ?? null;

        if (! $documentId) {
            throw new RuntimeException('easybill hat keinen Beleg zurückgegeben.');
        }

        return (int) $documentId;
    }

    public function documentUrl(int $documentId): ?string
    {
        return 'https://app.easybill.de/documents/' . $documentId;
    }
}
