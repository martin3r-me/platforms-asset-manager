{{--
    Modul-lokale Design-Token-Schicht (`--am-*`) — siehe docs/adr/0013-modul-design-token-schicht.md.

    WARUM hier und nicht in config/ui.php: Das UI-Modul (platform-styles/platforms-ui-tailwind) ist tabu
    (Goldene Regeln 2/3). Dessen `--ui-primary` ist Indigo-600 — das neue Soll-Design will dunkles Navy.
    Daher eine modul-eigene, prefix-gekapselte Token-Ebene (kollidiert nicht mit `--ui-*`).

    EINBINDUNG: einmal pro Seite ganz oben in livewire/sidebar.blade.php — die Sidebar wird von Core auf
    JEDER Modul-Seite eingebettet. Das <style> mit :root gilt dokumentweit, unabhängig von der Position.
    Werte sind aus dem Mockup „Design System – Asset Manager UI" abgeleitet. Single Source of Truth:
    Diese Datei + DESIGN.md (Radius/Shadow/Spacing-Mapping).
--}}
<style>
    :root {
        /* Primär (dunkles Navy/Graphit) — aktive Navigation, aktiver Tab, Primary-Buttons */
        --am-primary: #131826;
        --am-primary-hover: #20283A;
        --am-on-primary: #FFFFFF;

        /* Flächen */
        --am-bg: #F9FAFB;          /* Seiten-/Subtil-Hintergrund */
        --am-surface: #FFFFFF;     /* Cards, Panels, Inputs */

        /* Text */
        --am-text: #111827;
        --am-text-secondary: #4B5563;
        --am-text-muted: #808494;
        --am-text-disabled: #C8CCD0;

        /* Borders */
        --am-border: #E5E7EB;
        --am-border-strong: #D1D5DB;

        /* Akzent (Violett) — Intune, Fokus */
        --am-accent: #7C3AED;
        --am-accent-surface: #EDE9FD;
        --am-focus: 0 0 0 3px rgba(96, 14, 188, 0.14);   /* Fokus-Ring lt. Mockup */

        /* Semantik */
        --am-success: #15BB80;
        --am-warning: #F59E0B;
        --am-error: #EF4444;
        --am-info: #6366F1;
    }
</style>
