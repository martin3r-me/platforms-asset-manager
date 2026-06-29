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
                    <x-asset-manager-button variant="primary" size="md" wire:click="newHandover">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neue Ausgabe
                    </x-asset-manager-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search"
                                           placeholder="Empfänger, Gerät, Seriennr.…" />
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Status">
                    <x-asset-manager-select size="sm" wire:model.live="filterStatus">
                        <option value="">Alle</option>
                        <option value="open">Ausgegeben</option>
                        <option value="partially_returned">Teilweise zurück</option>
                        <option value="returned">Vollständig zurück</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Editor --}}
    <x-slot name="activity">
        <x-ui-page-sidebar :title="$editId ? 'Ausgabe bearbeiten' : 'Neue Ausgabe'" icon="heroicon-o-pencil-square"
                           width="w-96" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                @can('asset-manager.manage')
                @if($showEditor)
                    <div class="flex items-center justify-between pb-2 border-b border-[color:var(--am-border)]">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">{{ $editId ? 'Bearbeiten' : 'Neu anlegen' }}</span>
                        <button wire:click="cancelEdit" class="text-[10px] text-[var(--am-text-secondary)] hover:text-red-600">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5') Schließen
                        </button>
                    </div>

                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-3">
                        {{-- Empfänger --}}
                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Empfänger *</label>
                            <x-asset-manager-select size="md" wire:model="fEmployeeId">
                                <option value="">– wählen –</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e->id }}">{{ $e->display_name ?: $e->user_principal_name }}</option>
                                @endforeach
                            </x-asset-manager-select>
                            @error('fEmployeeId')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                        </div>

                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Ausgabedatum</label>
                            <x-asset-manager-input size="md" type="date" wire:model="fIssuedAt" />
                            @error('fIssuedAt')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                        </div>
                    </section>

                    {{-- Geräte-Zeilen --}}
                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Geräte</span>
                            <label class="inline-flex items-center gap-1 text-[10px] text-[var(--am-text-secondary)]">
                                <input type="checkbox" wire:model.live="includeIssued" class="rounded"> belegte zeigen
                            </label>
                        </div>
                        @error('fLines')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror

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
                                        <x-asset-manager-select size="md" wire:model="fLines.{{ $i }}.device_id">
                                            <option value="">– Gerät wählen –</option>
                                            @foreach($devices as $d)
                                                @php $isOpen = in_array($d->id, $openDeviceIds, true); @endphp
                                                @if(!$isOpen || $includeIssued || (int)($line['device_id'] ?? 0) === $d->id)
                                                    <option value="{{ $d->id }}">{{ $d->device_name }}@if($d->serial_number) · {{ $d->serial_number }} @endif @if($isOpen) — belegt @endif</option>
                                                @endif
                                            @endforeach
                                        </x-asset-manager-select>
                                        @error('fLines.'.$i.'.device_id')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                                    </div>
                                @endif

                                @if(empty($line['returned_at']))
                                    <x-asset-manager-input size="sm" type="text" wire:model="fLines.{{ $i }}.accessories"
                                                           placeholder="Zubehör (kommagetrennt: Ladegerät, Hülle, SIM …)" />
                                    <x-asset-manager-input size="sm" type="text" wire:model="fLines.{{ $i }}.notes"
                                                           placeholder="Notiz" />

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
                    </section>

                    {{-- Unterschrift (optional/nachholbar) --}}
                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-2">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">Unterschrift (optional)</span>
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
                    </section>

                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3">
                        <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Notiz (Protokoll)</label>
                        <x-asset-manager-textarea wire:model="fNotes" rows="2" />
                        @error('fNotes')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                    </section>

                    <div class="flex items-center gap-2 pt-1">
                        <x-asset-manager-button variant="primary" size="md" class="flex-1" wire:click="save">
                            {{ $editId ? 'Speichern' : 'Anlegen' }}
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="ghost" size="md" wire:click="cancelEdit">Abbrechen</x-asset-manager-button>
                        @if($editId)
                            <x-asset-manager-button variant="ghost" size="md" href="{{ route('asset-manager.handovers.pdf', $editId) }}" target="_blank" title="Protokoll-PDF">
                                @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5')
                            </x-asset-manager-button>
                            <x-asset-manager-button variant="danger" size="md" wire:click="delete({{ $editId }})" wire:confirm="Dieses Protokoll löschen?" title="Löschen">
                                @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                            </x-asset-manager-button>
                        @endif
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[var(--am-text-muted)] mb-3')
                        <p class="text-[11px] text-[var(--am-text-secondary)]">Eine Zeile anklicken zum Bearbeiten — oder oben „Neue Ausgabe“.</p>
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
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="flex items-center gap-2">
                @svg('heroicon-o-clipboard-document-check', 'w-5 h-5 text-[var(--am-text-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--am-text)] m-0">Geräteausgaben</h2>
            </div>

            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($handovers->isEmpty())
                    <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">Noch keine Ausgaben erfasst.</div>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-[var(--am-bg)]">
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Empfänger</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Geräte</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Datum</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Unterschrift</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($handovers as $h)
                                <tr wire:key="ho-{{ $h->id }}" wire:click="edit({{ $h->id }})"
                                    class="cursor-pointer transition-colors {{ $editId === $h->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : 'hover:bg-[var(--am-bg)]' }}">
                                    <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">{{ $h->employee?->display_name ?: ($h->employee?->user_principal_name ?? '—') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">
                                        @php $names = $h->lines->map(fn($l) => $l->deviceName()); @endphp
                                        {{ $names->take(2)->implode(', ') }}@if($names->count() > 2) +{{ $names->count() - 2 }}@endif
                                        <span class="text-[var(--am-text-muted)]">({{ $h->lines->count() }})</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $h->issued_at?->format('d.m.Y') ?? '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        @if($h->isSigned())
                                            <x-asset-manager-badge color="emerald" size="xs" :pill="false" icon="heroicon-o-check-badge">unterschrieben</x-asset-manager-badge>
                                        @else
                                            <x-asset-manager-badge color="amber" size="xs" :pill="false">nicht unterschrieben</x-asset-manager-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <x-asset-manager-badge :color="$h->statusBadgeColor()" size="xs" :pill="false">{{ $h->statusLabel() }}</x-asset-manager-badge>
                                    </td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        <a href="{{ route('asset-manager.handovers.pdf', $h->id) }}" target="_blank" wire:click.stop
                                           class="text-[var(--am-text-secondary)] hover:text-[var(--am-accent)]" title="Protokoll-PDF">@svg('heroicon-o-document-arrow-down', 'w-4 h-4 inline')</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>

            <div>{{ $handovers->links() }}</div>
        </div>
    </div>
</x-ui-page>
