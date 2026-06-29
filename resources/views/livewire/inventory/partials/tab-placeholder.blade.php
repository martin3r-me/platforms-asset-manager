{{-- Platzhalter-Tab (E8): sichtbar, aber noch ohne Backend. Erwartet $title + $icon via @include. --}}
<div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-10 flex flex-col items-center justify-center text-center">
    @svg($icon ?? 'heroicon-o-square-3-stack-3d', 'w-10 h-10 text-[var(--am-text-muted)] mb-3')
    <p class="text-sm font-medium text-[var(--am-text-secondary)]">{{ $title ?? 'Bereich' }}</p>
    <p class="text-xs text-[var(--am-text-muted)] mt-1">Dieser Bereich ist noch nicht verfügbar.</p>
</div>
