@props([
    'title' => null,
    'note'  => null,   // optionaler Zusatz rechts neben dem Titel (z. B. „· nur Geräte")
])

{{-- Filter-Block für Listen-Sidebars (Karte + Titel + Slot). Ersetzt das duplizierte <section>-Markup. --}}
<section class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
    @if($title)
        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-3 pt-3 pb-1.5">
            {{ $title }}@if($note)<span class="normal-case text-[var(--am-text-muted)]"> {{ $note }}</span>@endif
        </h3>
    @endif
    <div class="px-3 pb-3">
        {{ $slot }}
    </div>
</section>
