@props([
    'label'      => null,
    'value'      => null,
    'sub'        => null,
    'icon'       => null,     // heroicon-o-*
    'accent'     => null,     // navy | violet | indigo | emerald | amber | red | sky | null (neutral)
    'href'       => null,
    'valueClass' => '',       // optionale Wertfarbe (z. B. für semantische Hervorhebung)
])

{{--
    KPI-/Stat-Kachel (flach, weiß, feine Border, dezenter Shadow, große Kennzahl). Ersetzt das duplizierte
    Glas-/Gradient-Markup aus dashboard/inventory. Icon-Fläche optional (subtiler Tint). Siehe DESIGN.md.
--}}
@php
    $iconTint = match ($accent) {
        'navy'    => 'bg-[var(--am-bg)] text-[var(--am-primary)]',
        'violet'  => 'bg-violet-50 text-violet-600',
        'indigo'  => 'bg-indigo-50 text-indigo-600',
        'emerald' => 'bg-emerald-50 text-emerald-600',
        'amber'   => 'bg-amber-50 text-amber-600',
        'red'     => 'bg-red-50 text-red-600',
        'sky'     => 'bg-sky-50 text-sky-600',
        default   => 'bg-[var(--am-bg)] text-[var(--am-text-muted)]',
    };

    $cardClasses = 'block rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5 transition-colors'
        . ($href ? ' hover:border-[color:var(--am-border-strong)]' : '');
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => $cardClasses]) }}>
    <div class="flex items-start justify-between gap-3">
        <span class="text-xs font-medium text-[var(--am-text-secondary)]">{{ $label }}</span>
        @if($icon)
            <span class="flex items-center justify-center w-8 h-8 rounded-lg flex-shrink-0 {{ $iconTint }}">
                <x-dynamic-component :component="$icon" class="w-4 h-4" />
            </span>
        @endif
    </div>
    <div class="mt-2 text-2xl font-semibold tracking-tight text-[var(--am-text)] {{ $valueClass }}">
        {{ $value }}{{ $slot }}
    </div>
    @if($sub)
        <div class="mt-1 text-xs text-[var(--am-text-muted)]">{{ $sub }}</div>
    @endif
</{{ $tag }}>
