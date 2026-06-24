@props([
    'color'    => 'gray',   // Farbfamilie (Helfer-Ausgabe): emerald|red|amber|orange|sky|indigo|violet|gray (+ Aliasse)
    'dot'      => false,     // farbiger Status-Punkt links
    'size'     => 'sm',      // xs|sm|md|lg
    'icon'     => null,      // optionales Heroicon (z. B. heroicon-o-exclamation-triangle)
    'iconOnly' => false,
    'pill'     => true,
    'as'       => 'span',
    'href'     => null,
    'class'    => '',
])

{{--
    Modul-lokales Status-/Kategorie-Badge (Farbfamilien-Pass-through).

    Bewusste, dokumentierte Abweichung von den UI-Farbtokens — siehe docs/adr/0011-statusfarben-wcag-aa-abweichung.md:
    Die UI-Tokens (--ui-success/danger/info + --ui-on-*) verfehlen WCAG AA bei Statusfarben
    (Weiß auf emerald/red/blue-500 ≈ 2,5 / 4,0 / 3,3:1). Daher farbgleiche, dunklere Palette-Stufen
    (bg-{hue}-100 / text-{hue}-800, 7–8:1). Alle Klassen sind LITERAL → Tailwind-v4-@source-Scan
    erzeugt sie aus dieser Datei (dev: platform/modules/**, prod: vendor/martin3r/**).
    Die $color-Werte stammen aus den Modell-Helfern (complianceBadgeColor / lifecycleBadgeColor /
    AssetItem|AssetHandover::statusBadgeColor), die bereits Status → Farbfamilie abbilden.
--}}

@php
    $sizeClasses = [
        'xs' => 'text-[10px] px-1.5 py-0.5 gap-1',
        'sm' => 'text-xs px-2 py-0.5 gap-1.5',
        'md' => 'text-sm px-2.5 py-1 gap-1.5',
        'lg' => 'text-sm px-3 py-1 gap-2',
    ];
    $iconSize = [
        'xs' => 'w-3 h-3',
        'sm' => 'w-3.5 h-3.5',
        'md' => 'w-4 h-4',
        'lg' => 'w-4 h-4',
    ][$size] ?? 'w-3.5 h-3.5';

    [$tone, $dotClass] = match ($color) {
        'emerald', 'green'  => ['bg-emerald-100 text-emerald-800', 'bg-emerald-500'],
        'red', 'rose'       => ['bg-red-100 text-red-800',         'bg-red-500'],
        'amber', 'yellow'   => ['bg-amber-100 text-amber-800',     'bg-amber-500'],
        'orange'            => ['bg-orange-100 text-orange-800',   'bg-orange-500'],
        'sky', 'blue'       => ['bg-sky-100 text-sky-800',         'bg-sky-500'],
        'indigo'            => ['bg-indigo-100 text-indigo-800',   'bg-indigo-500'],
        'violet', 'purple'  => ['bg-violet-100 text-violet-800',   'bg-violet-500'],
        default             => ['bg-gray-100 text-gray-700',       'bg-gray-400'],
    };

    $rounded = $pill ? 'rounded-full' : 'rounded-md';
    $base = implode(' ', array_filter([
        'inline-flex items-center font-medium select-none whitespace-nowrap align-middle',
        $sizeClasses[$size] ?? $sizeClasses['sm'],
        $tone,
        $rounded,
        $iconOnly ? 'justify-center' : '',
        $class,
    ]));
@endphp

<{{ $as }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => $base]) }}
>
    @if($dot)
        <span class="inline-block w-1.5 h-1.5 rounded-full flex-shrink-0 {{ $dotClass }}"></span>
    @endif

    @if($icon && str_starts_with($icon, 'heroicon-'))
        <x-dynamic-component :component="$icon" :class="$iconSize" />
    @endif

    @unless($iconOnly)
        <span>{{ $slot }}</span>
    @endunless
</{{ $as }}>
