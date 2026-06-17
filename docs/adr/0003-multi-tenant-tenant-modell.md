# Multi-Tenant: Tenant als eigenes Modell mit `tenant_id`-Anker

Kontext: Der Asset Manager soll Inventar je **Kundenkontext** getrennt verwalten. Ein Team (z. B. BHG-IT) betreut mehrere Kunden — teils mit eigenem M365/Intune, teils ganz ohne Microsoft-Anbindung.

Entscheidung: Wir führen ein eigenes **Tenant**-Modell (`asset_tenants`) als verwalteten Kundenkontext ein. Jedes Inventar-Objekt (Geräte, Lizenzen, Mitarbeiter, manuelle Assets) referenziert über `tenant_id` **genau einen** Tenant (Pflicht, keine Mehrfach-Zugehörigkeit). Die Microsoft-Anbindung ist ein **optionaler** Connector (0..1 je Tenant; bestehendes `asset_connector_configs` wird vom Team an den Tenant umgehängt).

## Bewusste Abgrenzungen / Trade-offs

- **Kein Wiring der `platform-organization`-Dimension-Links.** Die Tenant-Zuordnung ist ein schlanker, intrinsischer FK auf unser eigenes Modell → schnelle tenant-reine Views, kein Cross-Modul-Join, keine Fremdmodul-Kopplung. Die dimensionale/organisationale Sicht stellt die Plattform von Haus aus bereit.
- **„Tenant" ist bei uns NICHT zwingend ein M365-Verzeichnis.** Ein Tenant kann ein reiner Manuell-Kunde ohne Intune sein; die Azure-Tenant-GUID lebt am Connector, nicht am Tenant.
- **Tenant und Connector sind getrennte Tabellen**, weil die Anbindung optional (0..1) und volatil ist (Consent/Sync-Lebenszyklus), der Tenant aber durable.
- **Das Kostenmodell bleibt vollständig aus dem Multi-Tenant-Scope ausgeklammert** (Kostenstellen, Gesellschaften, Kostenarten, Kreditoren, Kostenpositionen, Modell-Katalog `asset_device_models`): es bleibt **team-weit und unangetastet** — kein `tenant_id`, **nicht** vom Tenant-Selector gefiltert. Gründe: die Kostenaufteilung ist stark BROICH-spezifisch und das empfindlichste Stück des Moduls (ADR 0001); eine Tenant-Aufteilung der Kosten würde zu viel Risiko binden und wird ggf. künftig in ein **eigenes Modul** ausgelagert. Der Tenant-Selector wirkt daher nur auf **Inventar**-Views; die Kostensicht ist eine separate, team-weite Fläche. (Hebt die zwischenzeitlich erwogene „per-Tenant-Kostenstruktur / M5"-Richtung wieder auf.)
- **Consent-Modell: Multi-Tenant-Azure-App + Admin-Consent-Link (app-only).** Eine zentrale App, Admin-Consent je Kunde-Tenant; unbeaufsichtigter client-credentials-Sync pro Connector.

## Considered Options

- *Tenant-Anker via `platform-organization`-Dimension-Links* — verworfen: schwergewichtig, Fremdmodul-Kopplung, jede View würde zur Cross-Table-Abfrage; die Plattform liefert die Dimension ohnehin nativ.
- *Tenant-Identität nur am Connector (`connector_id`-FK)* — verworfen: ein Tenant muss auch **ohne** Connector existieren (Manuell-Kunde).
- *Tenant + Connector in einer Tabelle (nullable Anbindungs-Felder)* — verworfen zugunsten zweier Tabellen (durable vs. volatil).
