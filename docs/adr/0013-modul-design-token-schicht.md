# Modul-lokale Design-Token-Schicht (`--am-*`) & Navy-Primary statt `--ui-primary`

Status: akzeptiert (beschlossen 2026-06-29)

Kontext: Für den Asset-Manager liegt ein neues visuelles Soll-Design vor („Design System – Asset Manager
UI"): ein ruhiges, flaches IT-SaaS-UI mit weißen Flächen, feinen hellen Borders, dezenten Shadows und
**dunklem Navy/Graphit als Primary** (`#131826`) — Violett nur als sparsamer Akzent. Das widerspricht dem
bisherigen Modul-Look (`DESIGN.md` „Linear/Raycast": Frosted-Glass + violett-indigo-Gradient) und vor allem
der Primärfarbe der Plattform: Das geteilte UI-Token `--ui-primary` ist **Indigo-600** (`rgb(79,70,229)`)
und liegt im **unantastbaren** UI-Modul (`platform-styles/platforms-ui-tailwind`, Goldene Regeln 2/3). Es
ist weder dunkles Navy noch modulseitig korrigierbar.

Entscheidung: Wir führen eine **modul-lokale Token-Schicht** mit Prefix **`--am-*`** ein (kollidiert nicht
mit `--ui-*`) und stylen den Content-Bereich flach darüber.

- **Token-Quelle:** `resources/views/components/theme.blade.php` — ein `<style>`-Block, der `--am-*` auf
  `:root` definiert (Werte aus dem Mockup abgeleitet: Primary `#131826`, BG `#F9FAFB`, Surface `#FFFFFF`,
  Text `#111827/#4B5563/#808494/#C8CCD0`, Border `#E5E7EB/#D1D5DB`, Accent `#7C3AED/#EDE9FD`, Fokus-Ring
  `rgba(96,14,188,.14)`, Semantik success/warning/error/info).
- **Injektion:** einmal pro Seite ganz oben in `livewire/sidebar.blade.php` — Core bettet die Modul-Sidebar
  auf **jeder** Modul-Seite ein, das ist die eine zuverlässige Stelle. (Gleiches Muster wie die Plattform
  ihre `--ui-*`-Tokens injiziert; kein CSP-Risiko, da inline-`<style>` plattformüblich.)
- **Primary/Active = Navy, modul-lokal:** Für Elemente, die wir vollständig kontrollieren — Primary-Buttons
  (`<x-asset-manager-button>`), aktiver Tab (`<x-asset-manager-tabs>`) und aktive Sidebar-Navigation
  (`<x-asset-manager-nav-item>`) — verwenden wir `--am-primary` statt `--ui-primary`.
- **Komponenten:** wiederkehrende Bausteine werden modul-lokal gekapselt (button, panel, stat-card, input/
  select/textarea, filter-section, nav-item, detail-list/-row, tabs) und im `AssetManagerServiceProvider`
  via `Blade::component(...)` registriert — analog zu `<x-asset-manager-badge>`/`-page-actionbar>`.

## Bewusste Abgrenzungen / Trade-offs

- **`<x-ui-button>` / `<x-ui-panel>` werden im Content nicht genutzt** — sie sind Indigo bzw. glasig
  (`bg-white/50…/70 backdrop-blur`) und modulseitig nicht änderbar. Daher flache modul-lokale Pendants.
  Strukturelle Shell-Komponenten (`x-ui-page`, `-page-navbar`, `-page-actionbar`, `-page-container`,
  `-page-sidebar`, `-sidebar-list`) bleiben als Gerüst erhalten.
- **Topbar bleibt Plattform-Indigo** — sie gehört dem UI-Modul und ist nicht restylebar. Das Mockup ist
  dort bewusst nicht 1:1 erreichbar; akzeptiert.
- **Token-Treue vs. Soll-Design** — wir weichen bei *Primary/Active* bewusst von `--ui-primary` ab (Navy
  statt Indigo), weil das Soll-Design es verlangt. Neutral-/Flächen-/Status-Farben bleiben in der
  modul-eigenen, ebenfalls kontrollierten `--am-`-Ebene konsolidiert (statt verstreuter `--ui-`/Tailwind-
  Literale). Status-Badges bleiben unverändert über `<x-asset-manager-badge>` (ADR 0011, weiterhin AA).
- **Build-Sicherheit** — alle Klassen sind **literal** (`bg-[var(--am-surface)]` etc.); der Tailwind-v4-
  `@source`-Scan erzeugt sie aus den Modul-Blades (dev `platform/modules/**`, prod `vendor/martin3r/**`).
  Keine dynamisch zusammengebauten Klassennamen.
- **Radius/Shadow/Spacing ohne eigene Tokens** — Tailwind-Defaults decken sich mit dem Mockup
  (`rounded-xl/lg/md`, dezente Shadows, 8px-Raster); in `DESIGN.md` dokumentiert.
- **Reversibel über eine Stelle** — definiert die Plattform künftig ein dunkles Primary-Token oder einen
  Dark-Mode, genügt es, `theme.blade.php` (bzw. die Komponenten-Klassen) darauf umzustellen.
- **Light-first** — am Mockup orientiert; vorhandene `dark:`-Reste werden nicht entfernt, aber nicht
  ausgebaut (keine Regression der OS-Dark-Mode-Absicherung der Shell).
