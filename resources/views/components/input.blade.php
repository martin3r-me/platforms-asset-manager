@props([
    'type' => 'text',
    'size' => 'md',   // sm (Filter-Sidebars) | md
])

{{-- Einheitliches Textfeld. $attributes-Pass-through (wire:model, placeholder …). Siehe DESIGN.md. --}}
@php
    $sizeClasses = $size === 'sm' ? 'text-xs px-2 py-1.5' : 'text-sm px-3 py-2';
    $base = 'w-full rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] '
        . 'text-[var(--am-text)] placeholder:text-[var(--am-text-muted)] transition-colors '
        . 'focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)] '
        . 'disabled:bg-[var(--am-bg)] disabled:text-[var(--am-text-disabled)] disabled:cursor-not-allowed';
@endphp
<input type="{{ $type }}" {{ $attributes->merge(['class' => $base . ' ' . $sizeClasses]) }} />
