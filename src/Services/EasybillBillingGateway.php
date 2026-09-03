<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    /**
     * Datei an einen Beleg hängen — über `uploadAttachment()` des Connectors, die als einzige per
     * multipart sendet. Der JSON-Weg (`createAttachment`) kann für Dateien grundsätzlich nicht
     * funktionieren; easybill antwortet dort mit „File is missing".
     *
     * Fehlschläge werden protokolliert und als `null` gemeldet, nicht geworfen: der Beleg ist zu
     * diesem Zeitpunkt bereits angelegt, und ein Abbruch hinterließe eine Rechnung in easybill, von
     * der unser Lauf nichts weiß.
     */
    public function attachToDocument(int $documentId, string $filename, string $content): ?int
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $api = app(self::API_SERVICE);

        if (! method_exists($api, 'uploadAttachment')) {
            $this->reason = 'Der easybill-Connector kann keine Dateien hochladen '
                . '(uploadAttachment fehlt) — Integrations-Modul zu alt.';

            return null;
        }

        try {
            $result = $api->uploadAttachment(
                Auth::user(),
                $content,
                $filename,
                ['document_id' => $documentId],
                'application/pdf',
            );
        } catch (Throwable $e) {
            $this->reason = 'easybill: ' . $e->getMessage();

            Log::warning('[asset-manager] Anhang konnte nicht hochgeladen werden', [
                'document_id' => $documentId,
                'file'        => $filename,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }

        return isset($result['id']) ? (int) $result['id'] : null;
    }

    /**
     * Existiert der Beleg noch?
     *
     * Nur ein **404** gilt als Beweis für „gelöscht". Jeder andere Fehler — Token abgelaufen,
     * Netzwerk weg, easybill drosselt — liefert `null`; daraus eine Löschung zu folgern würde die
     * Periode freigeben und eine zweite Rechnung ermöglichen. Der HTTP-Status kommt per
     * `method_exists()` von der Connector-Ausnahme, damit hier kein harter Verweis auf deren Klasse
     * entsteht.
     */
    public function documentExists(int $documentId): ?bool
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $document = app(self::API_SERVICE)->getDocument(Auth::user(), $documentId);

            return ! empty($document['id']);
        } catch (Throwable $e) {
            if (method_exists($e, 'getHttpStatusCode') && $e->getHttpStatusCode() === 404) {
                return false;
            }

            $this->reason = 'easybill: ' . $e->getMessage();

            return null;
        }
    }

    /**
     * Kunden über die interne ID oder die Kundennummer finden.
     *
     * Erst der direkte Zugriff über die ID — trifft er, ist man fertig. Sonst der server-seitige
     * Filter auf `number`; das ist die Zahl, die in easybill auf dem Kundenblatt steht und die ein
     * Mensch zur Hand hat. Ein Fehlschlag der ID-Suche ist hier **kein** Fehler, sondern der
     * Normalfall bei einer Kundennummer, deshalb wird er verschluckt.
     */
    public function findCustomer(string $idOrNumber): ?array
    {
        $needle = trim($idOrNumber);

        if ($needle === '' || ! $this->isAvailable()) {
            return null;
        }

        $api  = app(self::API_SERVICE);
        $user = Auth::user();

        if (ctype_digit($needle)) {
            try {
                $customer = $api->getCustomer($user, (int) $needle);

                if (! empty($customer['id'])) {
                    return $this->shape($customer);
                }
            } catch (Throwable) {
                // Keine ID — gleich als Kundennummer versuchen.
            }
        }

        try {
            $result = $api->listCustomers($user, ['number' => $needle, 'limit' => 2]);
        } catch (Throwable $e) {
            $this->reason = 'easybill: ' . $e->getMessage();

            return null;
        }

        $items = $result['items'] ?? $result['data'] ?? [];

        // Genau ein Treffer oder keiner: bei mehreren gleicher Nummer wäre jede Wahl geraten.
        return count($items) === 1 ? $this->shape($items[0]) : null;
    }

    /** @return array{id: int, name: string, number: string|null} */
    protected function shape(array $customer): array
    {
        return [
            'id'     => (int) $customer['id'],
            'name'   => (string) ($customer['display_name'] ?? $customer['company_name'] ?? ''),
            'number' => isset($customer['number']) ? (string) $customer['number'] : null,
        ];
    }
}
