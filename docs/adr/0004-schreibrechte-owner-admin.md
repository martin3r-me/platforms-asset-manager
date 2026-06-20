# ADR 0004 — Schreibrechte = Owner/Admin, kanal-übergreifend (UI + MCP)

- **Status:** akzeptiert
- **Datum:** 2026-06-20
- **Bezug:** Code-Review M3 (H3, H4, M4) · Entscheidung E1 · ergänzt [ADR 0003](0003-multi-tenant-tenant-modell.md) (Team = Sicherheitsgrenze, Tenant = Arbeitsfilter)

## Kontext

Die Berechtigungsgrenze für **schreibende** Aktionen war uneinheitlich und **kanal-abhängig**:

- Geräte-/Connector-/Lösch-Pfade verlangten Owner/Admin (`AssetDevicePolicy`, `Devices/Show::canManage`),
- Cost-Lines, Stammdaten und Mitarbeiter-Bearbeitung in der UI waren dagegen nur an **Team-Mitgliedschaft**
  gebunden (jedes Member durfte schreiben),
- der Excel-**Import** (`runImport()`/`preview()`) war ungated, während der weniger destruktive
  `resetImport()` ein Owner/Admin-Gate trug,
- mutierende **MCP-Tools** (Geräte-Update, Modell-Default, Cost-Lines, Kostenstellen, Mitarbeiter) scopten
  zwar aufs Team, prüften aber **keine Rolle** — ein reines Member konnte über den MCP-Connector
  Operationen ausführen, die ihm die UI verweigerte.

## Entscheidung

1. **Einheitliche Regel:** Jeder **schreibende** Zugriff auf Asset-Manager-Daten — Inventar **und**
   Finanz-/Stammdaten — erfordert die Rolle **Owner oder Admin** des aktiven Teams. Member dürfen
   **lesen, nicht schreiben**. Lese-Pfade bleiben für Member unverändert.
2. **Eine Wahrheitsquelle:** Die zentrale Gate-Ability **`asset-manager.manage`** (definiert im
   `AssetManagerServiceProvider`, gestützt auf `Support\TeamRole::isOwnerOrAdmin()`) ist die einzige
   Mechanik. UI ruft sie über `Gate::authorize('asset-manager.manage')` bzw. die `canManage()`-Helfer
   (Trait `AuthorizesTeamRole`, der auf dieselbe `TeamRole`-Logik delegiert); MCP-Tools über
   `Gate::forUser($context->user)->allows('asset-manager.manage')` → `ACCESS_DENIED`.
3. **Kanal-Parität:** Dieselbe Regel gilt für UI **und** MCP. Die Berechtigung hängt nicht mehr vom
   genutzten Kanal ab.
4. **Policy-Angleichung:** `AssetItemPolicy::create/update` verlangen ebenfalls Owner/Admin (vorher
   `create=true`, `update=team`), konsistent mit `delete()` und der Gate-Ability.

## Konsequenzen

- **+** Konsistente, kanal-unabhängige Berechtigungsgrenze; eine Member-Rolle kann Daten weder über UI
  noch über MCP verändern.
- **+** Eine Stelle (`TeamRole`/`asset-manager.manage`) bestimmt die Regel — keine kopierten Inline-Checks.
- **−** Member, die bisher Cost-Lines/Stammdaten/Mitarbeiter/Assets pflegen durften, verlieren diese
  Schreibrechte (bewusst). Schreib-Bedienelemente sollten in den betroffenen Views für Member ausgeblendet
  werden, damit kein „sichtbarer Button → 403" entsteht (UI-Folgearbeit, nicht Teil des Backend-Gates).
- Der **read-only**-Charakter der Auswertungs-/Listen-Pfade bleibt für alle Team-Mitglieder erhalten.

## Alternativen verworfen

- *Schreibrechte an reine Team-Mitgliedschaft binden* — der bisherige uneinheitliche Zustand; lässt jedes
  Member die Kostenbasis/Stammdaten des Teams verändern.
- *Pro-Kanal unterschiedliche Regeln (UI strenger als MCP o. ä.)* — verworfen: erzeugt genau die
  kanal-abhängige Privilegien-Lücke, die dieser ADR schließt.
- *Feingranulare Rollen je Ressource (eigene Abilities pro Bereich)* — überdimensioniert für den aktuellen
  Bedarf; eine einzige `manage`-Ability deckt die Owner/Admin-vs-Member-Trennung ab. Verfeinerung bleibt
  später möglich, ohne Aufrufstellen zu ändern.
