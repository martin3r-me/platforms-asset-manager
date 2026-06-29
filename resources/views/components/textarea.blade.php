@props([
    'rows' => 4,
])

{{-- Einheitliche Textarea. $attributes-Pass-through (wire:model …). Siehe DESIGN.md. --}}
@php
    $base = 'w-full rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] '
        . 'text-sm px-3 py-2 text-[var(--am-text)] placeholder:text-[var(--am-text-muted)] transition-colors '
        . 'focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)] '
        . 'disabled:bg-[var(--am-bg)] disabled:cursor-not-allowed';
@endphp
<textarea rows="{{ $rows }}" {{ $attributes->merge(['class' => $base]) }}>{{ $slot }}</textarea>
