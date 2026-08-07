{{-- Ausgabe anlegen/bearbeiten inkl. Positionen, Rückgabe und Unterschrift. Lag bis S8b in einer
     zugeklappten rechten Sidebar (w-96) — für ein Formular dieser Länge zu eng (ADR 0012). --}}
<x-ui-modal model="showEditor" size="lg">
    <x-slot name="header">{{ $editId ? 'Ausgabe bearbeiten' : 'Neue Ausgabe' }}</x-slot>

    <div class="space-y-4">
        {{-- Kopf: Empfänger + Datum --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Empfänger *</label>
                <x-asset-manager-select size="sm" wire:model="fHolderId">
                    <option value="">– wählen –</option>
                    @foreach($holders as $e)
                        <option value="{{ $e->id }}">{{ $e->display_name ?: $e->user_principal_name }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('fHolderId') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Ausgabedatum</label>
                <x-asset-manager-input size="sm" type="date" wire:model="fIssuedAt" />
                @error('fIssuedAt') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Geräte-Zeilen --}}
        <div class="rounded-lg border border-[color:var(--am-border)] p-3 space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Geräte</span>
                <label class="inline-flex items-center gap-1 text-[10px] text-[var(--am-text-secondary)]">
                    <input type="checkbox" wire:model.live="includeIssued" class="rounded"> belegte zeigen
                </label>
            </div>
            @error('fLines') <p class="text-[10px] text-[var(--am-danger)]">{{ $message }}</p> @enderror

            @foreach($fLines as $i => $line)
                <div wire:key="line-{{ $i }}-{{ $line['id'] ?? 'new' }}" class="rounded-lg border border-[color:var(--am-border)] p-2 space-y-2 {{ !empty($line['returned_at']) ? 'opacity-60 bg-[var(--am-bg)]' : '' }}">
                    @if(!empty($line['id']))
                        {{-- Persistierte Zeile: Gerät read-only --}}
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-[var(--am-text)]">{{ $line['device_label'] ?? '—' }}</span>
                            @if(!empty($line['returned_at']))
                                <x-asset-manager-badge color="gray" size="xs" :pill="false" class="whitespace-nowrap">zurück {{ \Illuminate\Support\Carbon::parse($line['returned_at'])->format('d.m.Y') }}</x-asset-manager-badge>
                            @endif
                        </div>
                    @else
                        {{-- Neue Zeile: Geräte-Picker (belegte nur mit Toggle) --}}
                        <div>
                            <x-asset-manager-select size="sm" wire:model="fLines.{{ $i }}.device_id">
                                <option value="">– Gerät wählen –</option>
                                @foreach($devices as $d)
                                    @php $isOpen = in_array($d->id, $openDeviceIds, true); @endphp
                                    @if(!$isOpen || $includeIssued || (int)($line['device_id'] ?? 0) === $d->id)
                                        <option value="{{ $d->id }}">{{ $d->device_name }}@if($d->serial_number) · {{ $d->serial_number }} @endif @if($isOpen) — belegt @endif</option>
                                    @endif
                                @endforeach
                            </x-asset-manager-select>
                            @error('fLines.'.$i.'.device_id') <p class="text-[10px] text-[var(--am-danger)]">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if(empty($line['returned_at']))
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <x-asset-manager-input size="sm" type="text" wire:model="fLines.{{ $i }}.accessories"
                                                   placeholder="Zubehör (kommagetrennt: Ladegerät, Hülle, SIM …)" />
                            <x-asset-manager-input size="sm" type="text" wire:model="fLines.{{ $i }}.notes"
                                                   placeholder="Notiz" />
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            @if(!empty($line['id']))
                                @if($returningLineId === $line['id'])
                                    <div class="flex-1 flex items-center gap-1">
                                        <x-asset-manager-input size="sm" type="text" wire:model="returnCondition" placeholder="Zustand (optional)" class="flex-1" />
                                        <x-asset-manager-button variant="primary" size="sm" wire:click="confirmReturn">OK</x-asset-manager-button>
                                        <x-asset-manager-button variant="ghost" size="sm" wire:click="cancelReturn">Abbr.</x-asset-manager-button>
                                    </div>
                                @else
                                    <button wire:click="startReturn({{ $line['id'] }})" class="text-[11px] font-medium text-amber-700 hover:text-amber-800">
                                        @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 inline -mt-0.5') Zurückgeben
                                    </button>
                                @endif
                            @else
                                <button wire:click="removeLine({{ $i }})" class="text-[11px] text-red-600 hover:text-red-700">
                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5 inline -mt-0.5') entfernen
                                </button>
                            @endif
                        </div>
                    @elseif($line['return_condition'])
                        <p class="text-[11px] text-[var(--am-text-secondary)]">Zustand: {{ $line['return_condition'] }}</p>
                    @endif
                </div>
            @endforeach

            <x-asset-manager-button variant="secondary" size="sm" class="w-full" wire:click="addLine">
                @svg('heroicon-o-plus', 'w-3.5 h-3.5') Gerät hinzufügen
            </x-asset-manager-button>
        </div>

        {{-- Unterschrift (optional/nachholbar) --}}
        <div class="rounded-lg border border-[color:var(--am-border)] p-3 space-y-2">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Unterschrift (optional)</span>
            <x-asset-manager-input size="sm" type="text" wire:model="fSignerName" placeholder="Name des Unterzeichners" />

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
                        class="w-full h-32 rounded-md border border-dashed border-[color:var(--am-border)] bg-[var(--am-surface)] touch-none cursor-crosshair"></canvas>
                <div class="flex items-center justify-between mt-1">
                    <span class="text-[10px] text-[var(--am-text-secondary)]">Im Feld unterschreiben</span>
                    <button type="button" @click="clear()" class="text-[10px] text-red-600 hover:text-red-700">Löschen</button>
                </div>
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Notiz (Protokoll)</label>
            <x-asset-manager-textarea wire:model="fNotes" rows="2" />
            @error('fNotes') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
        </div>
    </div>

    <x-slot name="footer">
        @if($editId)
            <div class="mr-auto flex items-center gap-2">
                <x-asset-manager-button variant="secondary" size="sm" href="{{ route('asset-manager.handovers.pdf', $editId) }}" target="_blank" title="Protokoll-PDF">
                    @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5')
                    PDF
                </x-asset-manager-button>
                <x-asset-manager-button variant="danger" size="sm" wire:click="delete({{ $editId }})" wire:confirm="Dieses Protokoll löschen?">
                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                    Löschen
                </x-asset-manager-button>
            </div>
        @endif
        <x-asset-manager-button variant="secondary" size="sm" wire:click="cancelEdit">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="save" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            {{ $editId ? 'Speichern' : 'Anlegen' }}
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
