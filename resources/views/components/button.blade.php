@props([
    'variant' => 'primary',   // primary | secondary | ghost | danger
    'size'    => 'sm',        // sm | md | lg
    'href'    => null,
    'type'    => 'button',
])

{{--
    Modul-lokaler Button (flach, Navy-Primary lt. Mockup). Bewusste Alternative zu <x-ui-button>
    (Indigo + Glas-Outline), das im UI-Modul liegt und nicht geändert werden darf.
    Inhalt (Icon-SVG + Label) wird als Slot übergeben — wie bei <x-ui-button>. Alle Klassen literal
    (Tailwind-@source-Scan). Siehe DESIGN.md.
--}}
@php
    $variantClasses = match ($variant) {
        'secondary' => 'bg-[var(--am-surface)] text-[var(--am-text)] border border-[color:var(--am-border-strong)] hover:bg-[var(--am-bg)]',
        'ghost'     => 'bg-transparent text-[var(--am-text-secondary)] border border-transparent hover:bg-[var(--am-bg)]',
        'danger'    => 'bg-transparent text-red-600 border border-transparent hover:bg-red-50',
        default     => 'bg-[var(--am-primary)] text-[var(--am-on-primary)] border border-transparent shadow-sm hover:bg-[var(--am-primary-hover)]',
    };

    $sizeClasses = match ($size) {
        'md' => 'px-3.5 py-2 text-sm gap-2',
        'lg' => 'px-4 py-2.5 text-sm gap-2',
        default => 'px-2.5 py-1.5 text-xs gap-1.5',
    };

    $classes = implode(' ', [
        'inline-flex items-center justify-center rounded-lg font-medium whitespace-nowrap select-none',
        'transition-colors focus:outline-none focus-visible:shadow-[var(--am-focus)]',
        $variantClasses,
        $sizeClasses,
    ]);
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
