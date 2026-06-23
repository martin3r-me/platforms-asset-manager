<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräteausgaben', 'icon' => 'clipboard-document-check'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
                {{-- Anlegen nur Owner/Admin (ADR 0004) — Backend: save() Gate asset-manager.manage. --}}
                @can('asset-manager.manage')
                    <button wire:click="newHandover"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 transition-all">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neue Ausgabe
                    </button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Empfänger, Gerät, Seriennr.…"
                               class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Status</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterStatus" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="open">Ausgegeben</option>
                            <option value="partially_returned">Teilweise zurück</option>
                            <option value="returned">Vollständig zurück</option>
                        </select>
                    </div>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Editor --}}
    <x-slot name="activity">
        <x-ui-page-sidebar :title="$editId ? 'Ausgabe bearbeiten' : 'Neue Ausgabe'" icon="heroicon-o-pencil-square"
                           width="w-96" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                @can('asset-manager.manage')
                @if($showEditor)
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">{{ $editId ? 'Bearbeiten' : 'Neu anlegen' }}</span>
                        <button wire:click="cancelEdit" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5') Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-3">
                        {{-- Empfänger --}}
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Empfänger *</label>
                            <select wire:model="fEmployeeId" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                <option value="">– wählen –</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e->id }}">{{ $e->display_name ?: $e->user_principal_name }}</option>
                                @endforeach
                            </select>
                            @error('fEmployeeId')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Ausgabedatum</label>
                            <input type="date" wire:model="fIssuedAt" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            @error('fIssuedAt')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                        </div>
                    </section>

                    {{-- Geräte-Zeilen --}}
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Geräte</span>
                            <label class="inline-flex items-center gap-1 text-[10px] text-gray-500">
                                <input type="checkbox" wire:model.live="includeIssued" class="rounded"> belegte zeigen
                            </label>
                        </div>
                        @error('fLines')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror

                        @foreach($fLines as $i => $line)
                            <div wire:key="line-{{ $i }}-{{ $line['id'] ?? 'new' }}" class="rounded-lg border border-[var(--ui-border)]/40 p-2 space-y-2 {{ !empty($line['returned_at']) ? 'opacity-60 bg-[var(--ui-muted-5)]' : '' }}">
                                @if(!empty($line['id']))
                                    {{-- Persistierte Zeile: Gerät read-only --}}
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-medium text-gray-800">{{ $line['device_label'] ?? '—' }}</span>
                                        @if(!empty($line['returned_at']))
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-500/10 text-gray-600 whitespace-nowrap">zurück {{ \Illuminate\Support\Carbon::parse($line['returned_at'])->format('d.m.Y') }}</span>
                                        @endif
                                    </div>
                                @else
                                    {{-- Neue Zeile: Geräte-Picker (belegte nur mit Toggle) --}}
                                    <div>
                                        <select wire:model="fLines.{{ $i }}.device_id" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                            <option value="">– Gerät wählen –</option>
                                            @foreach($devices as $d)
                                                @php $isOpen = in_array($d->id, $openDeviceIds, true); @endphp
                                                @if(!$isOpen || $includeIssued || (int)($line['device_id'] ?? 0) === $d->id)
                                                    <option value="{{ $d->id }}">{{ $d->device_name }}@if($d->serial_number) · {{ $d->serial_number }} @endif @if($isOpen) — belegt @endif</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        @error('fLines.'.$i.'.device_id')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                                    </div>
                                @endif

                                @if(empty($line['returned_at']))
                                    <input type="text" wire:model="fLines.{{ $i }}.accessories" placeholder="Zubehör (kommagetrennt: Ladegerät, Hülle, SIM …)"
                                           class="w-full px-2 py-1.5 text-xs rounded-md border border-[var(--ui-border)]/60 bg-white">
                                    <input type="text" wire:model="fLines.{{ $i }}.notes" placeholder="Notiz"
                                           class="w-full px-2 py-1.5 text-xs rounded-md border border-[var(--ui-border)]/60 bg-white">

                                    <div class="flex items-center justify-end gap-2">
                                        @if(!empty($line['id']))
                                            @if($returningLineId === $line['id'])
                                                <div class="flex-1 flex items-center gap-1">
                                                    <input type="text" wire:model="returnCondition" placeholder="Zustand (optional)"
                                                           class="flex-1 px-2 py-1 text-xs rounded-md border border-[var(--ui-border)]/60 bg-white">
                                                    <button wire:click="confirmReturn" class="px-2 py-1 text-[11px] font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700">OK</button>
                                                    <button wire:click="cancelReturn" class="px-2 py-1 text-[11px] text-gray-500">Abbr.</button>
                                                </div>
                                            @else
                                                <button wire:click="startReturn({{ $line['id'] }})" class="text-[11px] font-medium text-amber-700 hover:text-amber-800">
                                                    @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 inline -mt-0.5') Zurückgeben
                                                </button>
                                            @endif
                                        @else
                                            <button wire:click="removeLine({{ $i }})" class="text-[11px] text-red-500 hover:text-red-600">
                                                @svg('heroicon-o-trash', 'w-3.5 h-3.5 inline -mt-0.5') entfernen
                                            </button>
                                        @endif
                                    </div>
                                @elseif($line['return_condition'])
                                    <p class="text-[11px] text-gray-500">Zustand: {{ $line['return_condition'] }}</p>
                                @endif
                            </div>
                        @endforeach

                        <button wire:click="addLine" class="w-full px-3 py-1.5 text-[11px] font-medium text-violet-700 bg-violet-500/5 border border-violet-500/20 rounded-lg hover:bg-violet-500/10">
                            @svg('heroicon-o-plus', 'w-3.5 h-3.5 inline -mt-0.5') Gerät hinzufügen
                        </button>
                    </section>

                    {{-- Unterschrift (optional/nachholbar) --}}
                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-2">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Unterschrift (optional)</span>
                        <input type="text" wire:model="fSignerName" placeholder="Name des Unterzeichners"
                               class="w-full px-2 py-1.5 text-xs rounded-md border border-[var(--ui-border)]/60 bg-white">

                        <div wire:ignore
                             x-data="{
                                ctx:null, drawing:false,
                                init(){
                                    const c=$refs.pad; this.ctx=c.getContext('2d');
                                    this.ctx.lineWidth=2; this.ctx.lineCap='round'; this.ctx.strokeStyle='#111827';
                                    const data=$wire.get('fSignatureData');
                                    if(data){ const img=new Image(); img.onload=()=>this.ctx.drawImage(img,0,0,c.width,c.height); img.src=data; }
                                },
                                pos(e){ const r=$refs.pad.getBoundingClientRect(); const t=e.touches?e.touches[0]:e;
                                    return { x:(t.clientX-r.left)*($refs.pad.width/r.width), y:(t.clientY-r.top)*($refs.pad.height/r.height) }; },
                                start(e){ e.preventDefault(); this.drawing=true; const p=this.pos(e); this.ctx.beginPath(); this.ctx.moveTo(p.x,p.y); },
                                move(e){ if(!this.drawing)return; e.preventDefault(); const p=this.pos(e); this.ctx.lineTo(p.x,p.y); this.ctx.stroke(); },
                                end(){ if(!this.drawing)return; this.drawing=false; $wire.set('fSignatureData', $refs.pad.toDataURL('image/png'), false); },
                                clear(){ this.ctx.clearRect(0,0,$refs.pad.width,$refs.pad.height); $wire.set('fSignatureData', null, false); }
                             }">
                            <canvas x-ref="pad" width="560" height="180"
                                    @pointerdown="start" @pointermove="move" @pointerup="end" @pointerleave="end"
                                    class="w-full h-32 rounded-md border border-dashed border-[var(--ui-border)] bg-white touch-none cursor-crosshair"></canvas>
                            <div class="flex items-center justify-between mt-1">
                                <span class="text-[10px] text-[var(--ui-muted)]">Im Feld unterschreiben</span>
                                <button type="button" @click="clear()" class="text-[10px] text-red-500 hover:text-red-600">Löschen</button>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3">
                        <label class="block text-xs text-gray-500 mb-1">Notiz (Protokoll)</label>
                        <textarea wire:model="fNotes" rows="2" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white"></textarea>
                        @error('fNotes')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                    </section>

                    <div class="flex items-center gap-2 pt-1">
                        <button wire:click="save" class="flex-1 px-3 py-2 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">
                            {{ $editId ? 'Speichern' : 'Anlegen' }}
                        </button>
                        <button wire:click="cancelEdit" class="px-3 py-2 text-xs font-medium text-gray-500 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">Abbrechen</button>
                        @if($editId)
                            <a href="{{ route('asset-manager.handovers.pdf', $editId) }}" target="_blank"
                               class="px-3 py-2 text-xs font-medium text-gray-600 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]" title="Protokoll-PDF">
                                @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5')
                            </a>
                            <button wire:click="delete({{ $editId }})" wire:confirm="Dieses Protokoll löschen?"
                                    class="px-3 py-2 text-xs font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10" title="Löschen">
                                @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                            </button>
                        @endif
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-gray-300 mb-3')
                        <p class="text-[11px] text-[var(--ui-muted)]">Eine Zeile anklicken zum Bearbeiten — oder oben „Neue Ausgabe“.</p>
                    </div>
                @endif
                @endcan
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div x-data x-on:open-activity.window="$store.ui && $store.ui.mSet('activity', 'open', true)"></div>

    {{-- HAUPT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="flex items-center gap-2">
                @svg('heroicon-o-clipboard-document-check', 'w-5 h-5 text-[var(--ui-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--ui-secondary)] m-0">Geräteausgaben</h2>
            </div>

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                @if($handovers->isEmpty())
                    <div class="p-8 text-center text-sm text-gray-400">Noch keine Ausgaben erfasst.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
                                <th class="text-left px-4 py-3">Empfänger</th>
                                <th class="text-left px-4 py-3">Geräte</th>
                                <th class="text-left px-4 py-3">Datum</th>
                                <th class="text-left px-4 py-3">Unterschrift</th>
                                <th class="text-left px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03]">
                            @foreach($handovers as $h)
                                <tr wire:key="ho-{{ $h->id }}" wire:click="edit({{ $h->id }})"
                                    class="cursor-pointer hover:bg-black/[0.02] {{ $editId === $h->id ? 'bg-violet-500/10' : '' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $h->employee?->display_name ?: ($h->employee?->user_principal_name ?? '—') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-600">
                                        @php $names = $h->lines->map(fn($l) => $l->deviceName()); @endphp
                                        {{ $names->take(2)->implode(', ') }}@if($names->count() > 2) +{{ $names->count() - 2 }}@endif
                                        <span class="text-gray-400">({{ $h->lines->count() }})</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $h->issued_at?->format('d.m.Y') ?? '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        @if($h->isSigned())
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-700">@svg('heroicon-o-check-badge','w-3 h-3') unterschrieben</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-700">nicht unterschrieben</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @php $stCls = [
                                            'open'               => 'bg-emerald-500/10 text-emerald-700',
                                            'partially_returned' => 'bg-amber-500/10 text-amber-700',
                                            'returned'           => 'bg-gray-500/10 text-gray-600',
                                        ][$h->status] ?? 'bg-gray-500/10 text-gray-600'; @endphp
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] {{ $stCls }}">{{ $h->statusLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        <a href="{{ route('asset-manager.handovers.pdf', $h->id) }}" target="_blank" wire:click.stop
                                           class="text-gray-400 hover:text-violet-600" title="Protokoll-PDF">@svg('heroicon-o-document-arrow-down', 'w-4 h-4 inline')</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div>{{ $handovers->links() }}</div>
        </div>
    </div>
</x-ui-page>
