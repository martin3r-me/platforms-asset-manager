# Provider-Abstraktion: zwei Rollen (MDM vs. Eigentum), `Connector` wird provider-generisch

Status: akzeptiert (beschlossen 2026-06-22, Umsetzung steht aus)

Kontext: `AssetDevice` und der Sync sind heute Intune-verdrahtet (`intune_id`, `raw_data` = Graph-Payload, Graph-spezifischer Job). Das Modul soll weitere Quellen anbinden (zunächst **Apple Business Manager**, perspektivisch Jamf). Diese Quellen sind **zweierlei Natur**: ein MDM kennt den *Zustand* eines Geräts, eine Beschaffungs-/Eigentumsquelle kennt den *Bestand*.

Entscheidung: Wir abstrahieren Quellen über **zwei Provider-Rollen**:
- **MDM/Management** (Intune, perspektivisch Jamf) → Compliance, OS, Nutzer/UPN, Verschlüsselung, Check-in.
- **Eigentum/Beschaffung** (Apple Business Manager) → Seriennummer, Modell, Bestellnummer, MDM-Zuweisung, Kauf.

Beide beschreiben **dasselbe physische Gerät** und werden **per Seriennummer** (ADR 0006) zu **einem** `AssetDevice` gemerged. Ein `DeviceSyncProvider`-Contract kapselt Auth/Fetch/Test; `IntuneGraphService` implementiert ihn. `Connector` wird **provider-generisch** (Feld `provider`); die Azure-Felder werden provider-spezifische Config. Die Kardinalität wird **0..N je Tenant, max. einer je Provider** (`connector()` → `connectors()`, Unique `(tenant_id, provider)`). Mehrere Quell-Referenzen je Gerät leben in einer neuen Kind-Tabelle **`asset_device_sources`** (`device_id`, `provider`, `external_id`, `last_seen_at`); sie ersetzt `intune_id`-als-Identität. **Reconcile wirkt pro Quelle**: ein Gerät wird nur soft-gelöscht, wenn es aus **allen** seinen Quellen fehlt (und nicht terminal-gepinnt ist, ADR 0007).

## Bewusste Abgrenzungen / Trade-offs

- **M365-Lizenzen bleiben Microsoft-only** — eine Fähigkeit des Microsoft-Providers, nicht des generischen Geräte-Contracts. Lizenz-Features erscheinen nur bei vorhandenem Microsoft-Connector.
- **Jamf wird vorerst nicht gebaut** — der Seam bleibt generisch, sodass eine weitere MDM-Implementierung später ohne Schemaänderung andocken kann.
- **Revidiert ADR 0003 teilweise:** die dortige Aussage „Connector 0..1 je Tenant" wird zu „0..N, max. einer je Provider". Der übrige Tenant-/Consent-/Token-Cache-Mechanismus aus ADR 0003 bleibt gültig.

## Considered Options

- *Einzelnes `external_id`/`source`-Feld am Gerät* — verworfen: ein Gerät kann **gleichzeitig** in Intune **und** ABM bekannt sein → mehrere Referenzen nötig.
- *Eine Provider-Rolle für alles* — verworfen: MDM (Zustand) und Eigentum (Bestand) liefern fachlich Verschiedenes; die Zwei-Rollen-Trennung macht den „besessen, aber nicht verwaltet"-Befund (ADR 0010) erst möglich.
