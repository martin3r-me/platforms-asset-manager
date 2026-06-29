@props([
    'label'    => null,
    'mono'     => false,   // monospace + kleiner (Seriennr., IDs)
    'bordered' => false,   // eigene untere Trennlinie (für 2-spaltige detail-list)
])

{{-- Eine Label/Value-Zeile. Wert via Slot (Text oder Badge). Siehe DESIGN.md.
     items-start + break-words: lange Werte (UPN, E-Mail, Seriennr.) brechen im engen Sidebar um,
     statt über den Rand / aus dem Fenster zu laufen. --}}
<div class="flex items-start justify-between gap-3 py-2{{ $bordered ? ' border-b border-[color:var(--am-border)]' : '' }}">
    <dt class="text-[var(--am-text-secondary)] flex-shrink-0">{{ $label }}</dt>
    <dd class="text-right break-words min-w-0 {{ $mono ? 'font-mono text-xs text-[var(--am-text)]' : 'font-medium text-[var(--am-text)]' }}">{{ $slot }}</dd>
</div>
