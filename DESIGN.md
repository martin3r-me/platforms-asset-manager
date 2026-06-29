# Asset Manager — Design Brief

## Personality: Ruhiges, flaches internes SaaS-Tool

Klar, ruhig, professionell, sehr gut lesbar, effizient für Tabellenarbeit. Vorbild ist ein modernes
IT-Asset-Management-UI: weiße Flächen, feine helle Borders, dezente Shadows, **dunkles Navy/Graphit als
Primary**, Violett nur als sparsamer Akzent (Intune/Fokus). **Keine** Frosted-Glass-Effekte, **keine**
Gradient-Akzente, keine verspielten Animationen.

Die Page-Shell-Komponenten (`x-ui-page`, `x-ui-page-navbar`, `x-ui-page-actionbar`, `x-ui-page-container`,
`x-ui-page-sidebar`, `x-ui-sidebar-list`) bleiben unverändert (UI-Modul = tabu). Alles im Content-Bereich
nutzt die modul-lokalen Komponenten + Tokens unten.

> Frühere Ausrichtung „Linear/Raycast" (Frosted-Glass + violett-indigo-Gradient) wurde 2026-06-29 auf
> diesen flachen Look umgestellt. Begründung & Token-Herkunft: `docs/adr/0013-modul-design-token-schicht.md`.

---

## Design-Tokens (`--am-*`)

Single Source of Truth: `resources/views/components/theme.blade.php` (als `<style>` injiziert, einmal pro
Seite über `livewire/sidebar.blade.php`). Werte aus dem Soll-Mockup abgeleitet. **Warum modul-lokal statt
`config/ui.php`:** Das UI-Modul ist tabu und sein `--ui-primary` ist Indigo-600 — wir brauchen Navy.
Prefix `--am-` → keine Kollision mit `--ui-*`.

| Token | Wert | Verwendung |
|-------|------|------------|
| `--am-primary` / `--am-primary-hover` | `#131826` / `#20283A` | Primary-Buttons, aktiver Tab, aktive Sidebar-Navigation |
| `--am-on-primary` | `#FFFFFF` | Text/Icon auf Primary |
| `--am-bg` | `#F9FAFB` | Seiten-/Subtil-Hintergrund, Tabellenkopf, Hover |
| `--am-surface` | `#FFFFFF` | Cards, Panels, Inputs |
| `--am-text` / `--am-text-secondary` / `--am-text-muted` / `--am-text-disabled` | `#111827` / `#4B5563` / `#808494` / `#C8CCD0` | Texthierarchie |
| `--am-border` / `--am-border-strong` | `#E5E7EB` / `#D1D5DB` | feine Flächen-Border / Input-Border |
| `--am-accent` / `--am-accent-surface` | `#7C3AED` / `#EDE9FD` | Intune-/Fokus-Akzent, Links |
| `--am-focus` | `0 0 0 3px rgba(96,14,188,.14)` | Fokus-Ring (als `focus:shadow-[var(--am-focus)]`) |
| `--am-success` / `--am-warning` / `--am-error` / `--am-info` | `#15BB80` / `#F59E0B` / `#EF4444` / `#6366F1` | semantische Akzente |

**Radius / Shadow / Spacing** = Tailwind-Utilities (kein eigenes Token nötig, deckt sich mit dem Mockup):
Cards `rounded-xl` (12px), Inputs/Buttons `rounded-lg` (8px), klein `rounded-md` (6px); Shadows dezent
(`shadow-sm`, Hover über Border-Wechsel); 8px-Spacing-Raster.

Konsum in Blades stets über **literale** Arbitrary-Values (Tailwind-`@source`-Scan), z. B.
`bg-[var(--am-surface)]`, `text-[color:var(--am-text)]`, `border-[color:var(--am-border)]`.

---

## Komponenten (modul-lokal, registriert im `AssetManagerServiceProvider`)

| Komponente | Datei | Verwendung |
|------------|-------|------------|
| `<x-asset-manager-theme>` | `components/theme.blade.php` | Token-Injektion. Einmal oben in `sidebar.blade.php`. |
| `<x-asset-manager-button>` | `components/button.blade.php` | `variant=primary\|secondary\|ghost\|danger`, `size=sm\|md\|lg`, `href`. Navy-Primary. |
| `<x-asset-manager-panel>` | `components/panel.blade.php` | Flache weiße Card. `title`, `actions`-Slot, `body-class` (`p-0` für bündige Listen/Tabellen). |
| `<x-asset-manager-stat-card>` | `components/stat-card.blade.php` | KPI-Kachel: `label`, `value`, `sub`, `icon`, `accent`, `href`, `value-class`. |
| `<x-asset-manager-input>` / `-select` / `-textarea` | `components/input\|select\|textarea.blade.php` | Felder, `$attributes`-Pass-through (`wire:model` …), `size=sm\|md`. |
| `<x-asset-manager-filter-section>` | `components/filter-section.blade.php` | Filter-Block (`title`, `note`) für Listen-Sidebars. |
| `<x-asset-manager-nav-item>` | `components/nav-item.blade.php` | Sidebar-Link mit Navy-Active (`href`, `active`, `icon`, `label`). |
| `<x-asset-manager-detail-list>` + `-detail-row>` | `components/detail-list\|detail-row.blade.php` | Label/Value-Zeilen. List: `cols=1\|2`. Row: `label`, `mono`, `bordered`. |
| `<x-asset-manager-tabs>` | `components/tabs.blade.php` | Tab-Leiste (Navy-Active-Pill). `:tabs`-Map; erwartet Eltern-Alpine-Scope mit `tab`. |
| `<x-asset-manager-badge>` | `components/badge.blade.php` | Status-/Kategorie-Badge (WCAG-AA, **unverändert**, siehe ADR 0011). |

Genutzt in: `dashboard`, `inventory/index`, `inventory/show`, `sidebar` (Runde 1). Weitere Views
(employees, licenses, costs, compliance, master-data, devices, printers, internet, reports) folgen in
Runde 2 mit denselben Komponenten.

## Tabellen (kein eigener Wrapper — dokumentiertes Klassenset)

- Kopf: `bg-[var(--am-bg)] text-[var(--am-text-muted)] text-xs font-semibold uppercase tracking-wider`, `px-4 py-3`
- Zeilen: `divide-y divide-[color:var(--am-border)]`, Hover `hover:bg-[var(--am-bg)]`, Zelle `px-4 py-3`
- Container: in `<x-asset-manager-panel body-class="p-0">` oder flacher Div mit `overflow-x-auto` (responsiv)

## Regeln

1. **Keine `x-ui-*`-Komponenten im Content-Bereich** (außer Page-Shell-Slots).
2. **Tokens statt Magic Numbers**: Farben über `--am-*`; Radius/Shadow/Spacing über die o. g. Tailwind-Utilities.
3. **Flach** — kein `backdrop-blur`, keine Gradient-Flächen/-Linien, keine Card-Lift-Animation.
4. **Farbe sparsam** — Navy + Neutraltöne tragen; Violett/Semantik nur als Akzent.
5. **Light-first** — am Mockup orientiert; vorhandene `dark:`-Reste werden nicht entfernt, aber nicht ausgebaut.
6. **Literale Klassen** — keine dynamisch zusammengesetzten Klassennamen (Tailwind-`@source`).
