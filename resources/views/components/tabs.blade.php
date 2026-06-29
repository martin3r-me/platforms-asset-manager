@props([
    'tabs' => [],   // ['key' => 'Label', …]
])

{{--
    Tab-Button-Leiste mit Navy-Active (Pill). Erwartet einen ELTERN-Alpine-Scope mit `tab`
    (z. B. <div x-data="{ tab: $wire.entangle('tab') }">), der auch die Content-Blöcke (x-show) umschließt.
    Bewusst kein <x-ui-tab> (dessen Active-State ist fehlerhaft). Siehe DESIGN.md.
--}}
<div class="flex flex-wrap gap-1.5 border-b border-[color:var(--am-border)] pb-2">
    @foreach($tabs as $key => $label)
        <button type="button" @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]'"
                class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors">
            {{ $label }}
        </button>
    @endforeach
</div>
