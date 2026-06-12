# platforms-asset-manager

Modul-Repo für den Asset-Manager (`Platform\AssetManager\`). Die **plattformweiten** Regeln
(Modul-Architektur, Boot-Sequenz, Goldene Regeln, Git-/Deploy-Workflow) stehen in der
übergeordneten `C:\Coding\Platforms\CLAUDE.md` und gelten unverändert — diese Datei hier
ergänzt nur die repo-spezifische Skill-Konfiguration.

## Agent skills

### Issue tracker

Aufgaben/Features/Bugs werden **nicht** in GitHub Issues getrackt, sondern im **Dev-Modul**
(office.bhgdigital.de, per MCP) im Package `platforms-asset-manager`. Siehe `docs/agents/issue-tracker.md`.

### Triage labels

Die fünf kanonischen Triage-Rollen sind auf die Board-Slots des Dev-Moduls
(Backlog · To Do · In Progress · Review · Done) gemappt. Siehe `docs/agents/triage-labels.md`.

### Domain docs

Single-context: ein `CONTEXT.md` + `docs/adr/` im Repo-Root. Siehe `docs/agents/domain.md`.
