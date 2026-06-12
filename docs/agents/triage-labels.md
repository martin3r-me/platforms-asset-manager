# Triage Labels

Die Skills sprechen in fünf kanonischen Triage-Rollen. Da der Issue-Tracker das **Dev-Modul** ist
(Boards mit Slots statt freier Labels), werden die Rollen auf **Board-Slots** abgebildet — nicht auf
GitHub-Label-Strings.

| Rolle in mattpocock/skills | Abbildung im Dev-Modul                              | Bedeutung                                  |
| -------------------------- | --------------------------------------------------- | ------------------------------------------ |
| `needs-triage`             | Slot **Backlog** (noch nicht bewertet)              | Maintainer muss das Issue bewerten         |
| `needs-info`               | Slot **Backlog** + Discussion-Verweis / Kommentar   | Wartet auf Rückmeldung des Melders         |
| `ready-for-agent`          | Slot **To Do** (vollständig spezifiziert, AFK-ready)| Fertig spezifiziert, ein Agent kann starten |
| `ready-for-human`          | Slot **To Do** mit Hinweis „human"                  | Braucht menschliche Umsetzung              |
| `wontfix`                  | Slot **Done** mit Begründung / geschlossen          | Wird nicht umgesetzt                       |

Aktiver Fortschritt eines aufgenommenen Issues wandert durch die Slots:
**Backlog → To Do → In Progress → Review → Done**.

Wenn eine Skill von einer Rolle spricht (z. B. „apply the AFK-ready triage label"), nutze die
entsprechende Slot-Abbildung oben über die Dev-Modul-MCP-Tools. Passt die Abbildung nicht zum
konkreten Fall, hier die rechte Spalte anpassen.
