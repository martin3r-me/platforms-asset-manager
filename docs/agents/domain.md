# Domain Docs

Wie die Engineering-Skills die Domänen-Doku dieses Repos beim Explorieren konsumieren sollen.

## Vor dem Explorieren lesen

- **`CONTEXT.md`** im Repo-Root (gemeinsame Domänensprache des Moduls).
- **`docs/adr/`** — ADRs, die den Bereich berühren, an dem gleich gearbeitet wird.

Existiert eine dieser Dateien nicht, **stillschweigend fortfahren**. Nicht auf das Fehlen hinweisen
und nicht vorab zum Anlegen auffordern. Die Producer-Skill (`/grill-with-docs`) erzeugt sie lazy,
sobald Begriffe oder Entscheidungen tatsächlich geklärt werden.

> Hinweis: Dauerhafte, geteilte Doku gehört laut plattformweiter `CLAUDE.md` zusätzlich in die
> **Docs** des Dev-Modul-Packages `platforms-asset-manager` (10 Standard-Seiten: overview,
> architecture, setup, api, data_model, testing, deployment, changelog, contributing, troubleshooting).
> `CONTEXT.md`/ADRs hier im Repo sind die schnelle, codenahe Ebene für die Agents.

## Dateistruktur (single-context)

```
/
├── CONTEXT.md
├── docs/adr/
│   ├── 0001-….md
│   └── 0002-….md
└── src/
```

## Vokabular des Glossars verwenden

Wenn ein Output einen Domänenbegriff benennt (Issue-Titel, Refactor-Vorschlag, Hypothese,
Testname), nutze den Begriff so, wie er in `CONTEXT.md` definiert ist. Nicht auf Synonyme
ausweichen, die das Glossar bewusst vermeidet.

Fehlt das benötigte Konzept noch im Glossar, ist das ein Signal — entweder erfindest du Sprache,
die das Projekt nicht nutzt (überdenken), oder es gibt eine echte Lücke (für `/grill-with-docs` notieren).

## ADR-Konflikte kennzeichnen

Widerspricht ein Output einem bestehenden ADR, das explizit benennen statt still zu übergehen:

> _Widerspricht ADR-0003 (…) — aber lohnt sich neu aufzurollen, weil …_
