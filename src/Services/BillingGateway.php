<?php

namespace Platform\AssetManager\Services;

/**
 * Der Weg nach draußen zum Rechnungssystem — eine Naht, kein Feature.
 *
 * Sie existiert aus demselben Grund wie die Provider-Abstraktion für Gerätequellen (docs/adr/0009):
 * das Rechnungssystem ist ein Fremdmodul, das in einer Installation fehlen darf. Ohne diese Naht
 * müsste der Asset Manager `platform-integrations` per Composer fordern und wäre ohne es nicht mehr
 * lauffähig — für ein Modul, dessen Kern IT-Lifecycle ist und nicht Fakturierung, wäre das die
 * falsche Abhängigkeitsrichtung.
 *
 * Bewusst schmal: **anlegen** und sonst nichts. Festschreiben, Versenden und Stornieren bleiben im
 * Rechnungssystem, wo sie hingehören und wo ein Mensch sie auslöst.
 */
interface BillingGateway
{
    /** Ist ein Rechnungssystem angebunden und benutzbar? */
    public function isAvailable(): bool;

    /** Klartext für die Oberfläche, wenn es das nicht ist. */
    public function unavailableReason(): ?string;

    /**
     * Legt einen Rechnungsentwurf an und gibt dessen Beleg-ID zurück.
     *
     * @param array<string, mixed> $document Beleg-Nutzdaten in der Sprache des Zielsystems.
     *
     * @throws \RuntimeException wenn kein Beleg entstanden ist.
     */
    public function createDraftInvoice(array $document): int;

    /** Direktlink auf den Beleg im Zielsystem, sofern konstruierbar. */
    public function documentUrl(int $documentId): ?string;

    /**
     * Eine Datei an einen bestehenden Beleg hängen; gibt deren Id zurück.
     *
     * Bewusst mit Rückgabe `null` statt Ausnahme, wenn es nicht klappt: der Anhang ist eine Beigabe
     * zur Rechnung, nicht ihr Kern. Scheitert er, soll der bereits angelegte Beleg stehenbleiben und
     * der Vorgang nur vermerken, dass die Aufstellung fehlt — ein Abbruch hinterließe eine Rechnung
     * in easybill, von der unser Lauf nichts weiß.
     *
     * @param string $content Roher Dateiinhalt, nicht base64.
     */
    public function attachToDocument(int $documentId, string $filename, string $content): ?int;

    /**
     * Gibt es diesen Beleg im Zielsystem noch?
     *
     * Dreiwertig, und das ist der Punkt: `true` vorhanden, `false` **nachweislich** weg (404),
     * `null` **unbekannt** — kein Rechnungssystem angebunden, Netzwerkfehler, Token abgelaufen.
     * `null` darf niemals wie `false` behandelt werden: aus „ich konnte nicht nachsehen" eine
     * gelöschte Rechnung zu folgern, gäbe die Periode frei und ließe eine zweite Rechnung für
     * denselben Monat entstehen.
     */
    public function documentExists(int $documentId): ?bool;

    /**
     * Einen Rechnungsempfänger auflösen — wahlweise über die interne ID **oder** die Kundennummer.
     *
     * Beides zuzulassen ist kein Komfort, sondern eine Fehlerquelle weniger: in easybill sieht man
     * die **Kundennummer** (`6010200`), die API verlangt aber die **interne ID** (`2636491004`).
     * Wer die sichtbare Zahl einträgt, bekäme sonst erst beim Rechnungslauf ein „Kunde nicht
     * gefunden" — Wochen später und ohne Hinweis darauf, welche der beiden Zahlen gemeint war.
     *
     * @return array{id: int, name: string, number: string|null}|null
     */
    public function findCustomer(string $idOrNumber): ?array;
}
