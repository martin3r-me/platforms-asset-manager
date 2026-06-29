{{-- Platzhalter-Tab (E8): sichtbar, aber noch ohne Backend. Erwartet $title + $icon via @include. --}}
<div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-10 flex flex-col items-center justify-center text-center">
    @svg($icon ?? 'heroicon-o-square-3-stack-3d', 'w-10 h-10 text-[color:var(--ui-muted)] mb-3')
    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $title ?? 'Bereich' }}</p>
    <p class="text-xs text-[color:var(--ui-secondary)] mt-1">Dieser Bereich ist noch nicht verfügbar.</p>
</div>
