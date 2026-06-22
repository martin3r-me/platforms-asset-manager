# Apple Business Manager als Eigentums-/Beschaffungs-Quelle

Status: akzeptiert (beschlossen 2026-06-22, Umsetzung steht aus — Fast-Follow nach ADR 0006/0009)

Kontext: BROICH besitzt Apple-Hardware, deren **Eigentums-/Beschaffungswahrheit** heute nirgends erfasst ist — Intune kennt nur, was *enrolled* ist. Apple Business Manager (ABM) stellt eine API bereit (OAuth 2 mit privatem Schlüssel), die **Seriennummern, Modelle, Bestellnummern, MDM-Server-Zuweisungen und Audit-Events** liefert.

Entscheidung: Wir binden ABM als **erste Eigentums-/Beschaffungs-Quelle** (Provider-Rolle „ownership", ADR 0009) an. ABM-Datensätze matchen **per Seriennummer** (ADR 0006) auf bestehende Geräte und ergänzen sie um Beschaffungs-/Eigentumsdaten. Auth läuft pro Connector über OAuth 2 + privaten Schlüssel. Der Mehrwert: **ABM-Bestand ∖ MDM-Bestand = „besessen, aber nicht verwaltet"** — eine Drift-Liste („wir besitzen 50 MacBooks, 47 sind in Intune, 3 fehlen"), die als Dashboard-/Anomalie-Befund erscheint.

## Bewusste Abgrenzungen / Trade-offs

- **ABM liefert keinen Live-Zustand** (keine Compliance/OS/Verschlüsselung) — komplementär zum MDM, kein Ersatz. Ein Gerät erhält sein vollständiges Bild aus dem Merge beider Rollen.
- **Reihenfolge:** ABM wird **nach** der Identitäts-Umstellung (ADR 0006) und dem Provider-Seam (ADR 0009) gebaut, weil das serial-basierte Matching die Voraussetzung ist.
