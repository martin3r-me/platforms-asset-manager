@props([
    'href'   => '#',
    'active' => false,
    'icon'   => null,    // heroicon-o-*
    'label'  => null,
])

{{--
    Sidebar-Navigationslink mit Navy-Active-State (lt. Mockup). Bewusste modul-lokale Alternative zu
    <x-ui-sidebar-item> (dessen Active fest auf Indigo `--ui-primary` verdrahtet ist, UI-Modul = tabu).
    Icon erbt currentColor → weiß auf Navy im Active-State. Siehe DESIGN.md / ADR 0013.
--}}
@php
    $stateClasses = $active
        ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]'
        : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)] hover:text-[var(--am-text)]';
@endphp
<a href="{{ $href }}" wire:navigate
   {{ $attributes->merge(['class' => 'flex items-center gap-2.5 px-2.5 py-2 rounded-md text-sm font-medium transition-colors ' . $stateClasses]) }}>
    @if($icon)
        <x-dynamic-component :component="$icon" class="w-4 h-4 flex-shrink-0" />
    @endif
    @if($label)
        <span class="truncate">{{ $label }}</span>
    @endif
    {{ $slot }}
</a>
