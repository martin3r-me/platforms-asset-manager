@props([
    'cols' => 1,   // 1 = einspaltig mit Trennlinien | 2 = zweispaltiges Grid (Rows brauchen :bordered)
])

{{-- Container für Label/Value-Zeilen (<x-asset-manager-detail-row>). Siehe DESIGN.md. --}}
@php
    $layout = (int) $cols === 2
        ? 'grid grid-cols-1 sm:grid-cols-2 gap-x-8'
        : 'divide-y divide-[color:var(--am-border)]';
@endphp
<dl {{ $attributes->merge(['class' => 'text-sm ' . $layout]) }}>
    {{ $slot }}
</dl>
