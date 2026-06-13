{{-- Bereichs-Navigation: schaltet $active um. Vars: $areas, $counts, $active --}}
<nav class="space-y-1">
    @foreach($areas as $key => $a)
        <button type="button" wire:click="setActive('{{ $key }}')"
                class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-left transition-colors
                       {{ $active === $key ? 'bg-violet-600 text-white shadow-sm' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-10)]' }}">
            @svg($a['icon'], 'w-4 h-4 flex-shrink-0')
            <span class="flex-1 truncate">{{ $a['label'] }}</span>
            <span class="text-[10px] rounded-full px-2 py-0.5 {{ $active === $key ? 'bg-white/20 text-white' : 'bg-[var(--ui-muted-10)] text-[var(--ui-muted)]' }}">{{ $counts[$key] ?? 0 }}</span>
        </button>
    @endforeach
</nav>
