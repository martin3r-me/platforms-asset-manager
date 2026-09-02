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
}
