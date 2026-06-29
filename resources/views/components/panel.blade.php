@props([
    'title'     => null,
    'subtitle'  => null,
    'bodyClass' => 'p-4',   // 'p-0' für bündige Listen/Tabellen
])

{{--
    Flache weiße Card (ersetzt das glasige <x-ui-panel> im Modul-Content). API-kompatibel: `title`.
    Optionaler `actions`-Slot rendert rechts im Header (z. B. „Bearbeiten"-Link). Siehe DESIGN.md.
--}}
<section {{ $attributes->merge(['class' => 'rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm']) }}>
    @if($title || $subtitle || isset($actions))
        <header class="flex items-center justify-between gap-3 px-4 py-3 border-b border-[color:var(--am-border)]">
            <div class="min-w-0">
                @if($title)
                    <h3 class="text-sm font-semibold text-[var(--am-text)] m-0 truncate">{{ $title }}</h3>
                @endif
                @if($subtitle)
                    <div class="text-xs text-[var(--am-text-muted)] mt-0.5">{{ $subtitle }}</div>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2 flex-shrink-0">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="{{ $bodyClass }}">
        {{ $slot }}
    </div>
</section>
