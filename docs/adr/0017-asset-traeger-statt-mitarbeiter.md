# Asset-Träger statt Mitarbeiter — ein Typ mit Rollen

Status: akzeptiert (beschlossen 2026-08-07, Max/Martin)

Kontext: Die Entität `AssetEmployee` („Mitarbeiter", Tabelle `asset_employees`) trägt heute drei Dinge,
die keine Arbeitsverhältnisse sind:

- **Externe** mit Firmengerät und Lizenz (Dienstleister, Aushilfen),
- **Admin-/Verwaltungsaccounts** — keine Personen,
- **Nicht-Menschen als Lizenzträger** (z. B. „Bumblebee"), sowie die bereits typisierten
  **Funktionskonten** (`account_type='function'`, synthetische UPN `<slug>@funktion.import.local`).

Fachlich bildet die Zeile eine **Beziehung Person ↔ Asset** ab, kein Arbeitsverhältnis. Das ist kein
Kosmetik-Thema: Sobald ein KI-Modell über die Daten läuft — und über die 31 MCP-Tools tut es das —
nimmt es die Bezeichnung wörtlich und bewertet jeden Eintrag als Mitarbeiter. Ergebnis sind falsche
Auswertungen („wir haben 152 Mitarbeiter", tatsächlich 130 Personen + 12 Funktionskonten + 10 Admins).
`account_type` kennt bis heute genau **einen** Wert (`'function'`) und deckt das nicht ab.

Entscheidung: **Die Entität heißt Asset-Träger (`AssetHolder`) und ist ein Typ mit Rollen.**

- Tabelle `asset_employees` → `asset_holders`; Modell `AssetEmployee` → `AssetHolder`;
  `EmployeeService` → `HolderService`.
- `account_type` → **`holder_type`** mit fünf Werten: `person` · `function` · `admin` · `service` ·
  `external`. Bestand migriert (`function` bleibt `function`, alles übrige wird `person`).
- **Ein** Typ mit Rollen, **nicht** getrennte Entitäten für Lizenzträger und Geräteinhaber.
- Routen `asset-manager.employees.*` → `asset-manager.holders.*`, alte Namen leiten weiter (Muster wie
  `devices.show`) — Bookmarks brechen nicht.
- MCP-Tools `asset-manager.employees.*` → `holders.*` mit **Alias- und Deprecation-Phase**; die Namen
  sind über den Live-Connector nach außen sichtbar.
- Im Sprachgebrauch des Moduls kommt „Mitarbeiter" nicht mehr vor — Sidebar-Label „Alle Mitarbeiter"
  → „Asset-Träger", Glossar in `CONTEXT.md` nachgezogen.

## Bewusste Abgrenzungen / Trade-offs

- **Kein Split in Lizenzträger und Geräteinhaber.** Dieselbe Zeile trägt heute schon beides über **eine**
  UPN. Eine Trennung zerreißt die UPN-Eindeutigkeit, auf der Sync (`findOrCreateByUpn`),
  Kostenzuordnung (`asset_cost_lines.assignee`) und Anonymisierung ([ADR 0005](0005-pii-erasure-anonymisierung.md))
  aufbauen — drei Mechaniken, die von genau einem Anker leben.
- **Admin-Accounts werden gelabelt, nicht gefiltert.** Wegwerfen wäre einfacher, macht aber
  Lizenzauswertungen falsch: ein Admin-Account verbraucht eine echte Lizenz. Die Default-Sicht blendet
  Nicht-Personen aus, die Lizenz- und Kostenauswertung zählt sie **mit**.
- **Klassifizierungsregeln pro Tenant konfigurierbar** (Präfix-/Muster-Liste in den Moduleinstellungen),
  **keine Kundennamen oder -muster im Code** — Multi-Tenant-Leitplanke. „Alles was mit `adm-` anfängt ist
  ein Admin" ist eine Kundenkonvention, keine Domänenwahrheit.
- **„Asset-Träger" statt „Träger" oder „Nutzer".** „Nutzer" kollidiert mit dem Plattform-`User`
  (Login-Konto); „Träger" allein ist zu unspezifisch. Das Kompositum benennt die Beziehung, die die
  Entität tatsächlich abbildet.
- **Preis: eine breite, mechanische Umbenennung.** ~50 Dateien, FKs, Routen, Views und externe
  Tool-Namen. Der Aufwand ist einmalig, die Fehlinterpretation dagegen dauerhaft und wächst mit jeder
  LLM-Auswertung. Deshalb jetzt, vor der Read-only-API für Kunden.
- **Nicht umbenannt wird `asset_assignments`** („Zuordnung") — der Begriff ist über
  [ADR 0012](0012-inventar-primaere-asset-flaeche.md) und `CONTEXT.md` bereits geklärt und meint die
  Zeitraum-Verknüpfung, nicht den Träger.

## Considered Options

- *Nur `account_type` erweitern, Name „Mitarbeiter" behalten* — verworfen: der Name ist genau das
  Problem. Ein `holder_type='admin'` in einer Tabelle namens „Mitarbeiter" liest sich für Mensch und
  Modell weiterhin als Mitarbeiter.
- *Getrennte Entitäten `LicenseHolder` / `DeviceHolder`* — verworfen, siehe UPN-Eindeutigkeit oben.
- *`AssetSubject` als Name* — verworfen: `Support\AssetSubject` existiert schon und bezeichnet das
  *Asset* in der vereinten Inventar-Sicht ([ADR 0012](0012-inventar-primaere-asset-flaeche.md)). Zwei
  Bedeutungen für ein Wort ist genau der Fehler, den diese ADR behebt.
- *Nicht-Personen aus dem Bestand entfernen und extern führen* — verworfen: sie tragen echte Lizenzen
  und echte Kosten; sie fehlten dann in jeder Auswertung.

Verweise: [ADR 0005](0005-pii-erasure-anonymisierung.md),
[ADR 0012](0012-inventar-primaere-asset-flaeche.md),
[ADR 0014](0014-mobilfunk-ohne-eigene-vertrags-entitaet.md),
[ADR 0016](0016-tenant-als-zugriffsgrenze.md).
