# Statusfarben weichen für WCAG AA von den UI-Farbtokens ab

Status: akzeptiert (beschlossen 2026-06-24)

Kontext: `platforms-ui-tailwind` ist die verbindliche Quelle für Farben/Tokens, und das Modul soll
ausschließlich vorhandene Tokens nutzen. Bei einer Kontrast-/Lesbarkeits-Überarbeitung (Ziel: WCAG AA,
Fließtext ≥ 4,5:1) stellt sich heraus: **Die UI-Farbtokens erreichen AA bei Statusfarben nicht.**

- Solid (Token-Rezeptur der Buttons): Weiß (`--ui-on-*`) auf `--ui-success` (emerald-500) ≈ **2,5:1**, auf `--ui-danger` (red-500) ≈ **4,0:1**, auf `--ui-info` (blue-500) ≈ **3,3:1**.
- Soft (Rezeptur des `<x-ui-badge>`): 500er-Textfarbe auf `-10`-Tint liegt noch tiefer.

Beide Token-Rezepturen verfehlen also 4,5:1 für genau die Farben, die wir am häufigsten als Status
brauchen (Compliance, Lifecycle, Item-/Übergabe-Status, farbcodierte Geldwerte). Das UI-Modul ist tabu
(Goldene Regel 2), die Tokens sind dort also nicht korrigierbar.

Entscheidung: Für **Status-Badges und farbcodierte Werte** verwenden wir **farbgleiche, dunklere
Palette-Stufen** statt der UI-Farbtokens:

- Badges: `bg-{hue}-100 text-{hue}-800` (+ optionaler `bg-{hue}-500`-Punkt) — 7–8:1.
- Geldwerte/Inline-Zahlen: `text-{hue}-700` (z. B. `sky-700`, `emerald-700`, negativ `red-700`) — 5–6:1.

Die Hues bleiben **dieselben Farbfamilien** wie die Tokens (emerald≈success, red≈danger, amber/orange≈
warning, sky/blue≈info, indigo/violet≈primary), nur in einer dunkleren Stufe. Gekapselt wird das in der
modul-lokalen Komponente **`<x-asset-manager-badge>`** (Farbfamilien-Pass-through): Sie nimmt direkt die
Ausgabe der bestehenden Modell-Helfer (`complianceBadgeColor`, `lifecycleBadgeColor`,
`AssetItem|AssetHandover::statusBadgeColor`) entgegen und mappt jede Farbfamilie literal auf ihr
AA-Paar.

## Bewusste Abgrenzungen / Trade-offs

- **Token-Treue vs. AA** — Wir priorisieren WCAG AA über strikte Token-Reinheit, aber **nur farblich und
  nur dort, wo die Tokens AA verfehlen**. Neutral-/Flächen-/Rahmen-/Hover-/Aktiv-Farben bleiben
  vollständig token-basiert (`--ui-secondary`, `--ui-body-color`, `--ui-muted(-5/-10/-20)`,
  `--ui-border`, `--ui-primary(-5/-10)`, `--ui-on-*` für gefüllte Primär-Buttons).
- **`<x-ui-badge>` wird nicht genutzt** — seine Soft-Rezeptur ist sub-AA und modulseitig nicht fixbar.
  Ebenso `<x-ui-button variant="*-outline">` (Hover-Bug: `hover:text-[--ui-on-secondary]` = Weiß auf
  white/70 → unlesbar); sekundäre Aktionen nutzen daher `secondary-ghost`.
- **Farbfamilien-Pass-through statt semantischem Tone-Layer** — die Helfer *sind* bereits die
  semantische Schicht (Status → Farbe). Ein zweiter Tone-Layer würde `orange` und `amber` auf „warning"
  kollabieren und die im Code bewusst getroffene Unterscheidung (Konflikt/Defekt vs. Karenz/Reparatur)
  verlieren.
- **Build-Sicherheit** — Alle Farbklassen sind **literal** in der Komponente; der Tailwind-v4-`@source`-
  Scan erzeugt sie aus den Modul-Blades (dev `platform/modules/**`, prod `vendor/martin3r/**`). Keine
  Abhängigkeit von der (inerten) `safelist`, keine dynamisch zusammengebauten Klassennamen in Views.
- **Reversibel über eine Stelle** — Definiert die Plattform künftig AA-taugliche dunklere Status-Tokens
  (z. B. `--ui-on-…`-Korrektur oder `--ui-{color}-strong`), genügt es, die `match()`-Tabelle in
  `<x-asset-manager-badge>` darauf umzustellen.
