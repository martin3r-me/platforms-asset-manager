@props([
    'label'    => null,
    'mono'     => false,   // monospace + kleiner (Seriennr., IDs)
    'bordered' => false,   // eigene untere Trennlinie (für 2-spaltige detail-list)
])

{{-- Eine Label/Value-Zeile. Wert via Slot (Text oder Badge). Siehe DESIGN.md. --}}
<div class="flex items-center justify-between gap-3 py-2{{ $bordered ? ' border-b border-[color:var(--am-border)]' : '' }}">
    <dt class="text-[var(--am-text-secondary)] flex-shrink-0">{{ $label }}</dt>
    <dd class="text-right {{ $mono ? 'font-mono text-xs text-[var(--am-text)]' : 'font-medium text-[var(--am-text)]' }} min-w-0">{{ $slot }}</dd>
</div>
