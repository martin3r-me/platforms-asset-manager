<x-ui-page title="Vertragszeilen">
    @php
        $money = fn ($value) => number_format((float) $value, 2, ',', '.') . ' €';
        $seats = fn ($value) => number_format((float) $value, 0, ',', '.');
    @endphp

    <x-asset-manager-page-actionbar>
        <div class="flex items-center gap-3">
            @if($periods->isNotEmpty())
                <label class="text-xs text-[var(--am-text-secondary)]">Abrechnungsmonat</label>
                <x-asset-manager-select wire:model.live="period" class="w-40">
                    @foreach($periods as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-asset-manager-select>
            @endif
        </div>
    </x-asset-manager-page-actionbar>

    <div class="p-4 sm:p-6 space-y-5">
        @if($flash)
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-2.5 text-sm text-emerald-800">
                @svg('heroicon-o-check-circle', 'w-4 h-4 inline') {{ $flash }}
            </div>
        @endif

        @if($lines->isEmpty())
            <x-asset-manager-panel title="Noch keine Vertragszeilen">
                <p class="text-sm text-[var(--am-text-secondary)]">
                    Vertragszeilen entstehen beim Import der Lizenz-Abrechnung. Der Upload liegt unter
                    <a href="{{ route('asset-manager.costs.import') }}" wire:navigate class="underline">Kosten → Import</a>.
                </p>
            </x-asset-manager-panel>
        @else
            {{-- Wohin das Geld des Monats geht --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <x-asset-manager-stat-card label="Rechnung (Monat)" :value="$money($totals['invoice'])" />
                <x-asset-manager-stat-card label="Auf Trägern" :value="$seats($totals['seats']) . ' Seats'" />
                <x-asset-manager-stat-card label="IT-Gemeinkosten" :value="$money($totals['overflow'])"
                    sub="Monats-Seats über Bedarf" />
                <x-asset-manager-stat-card label="Lizenzpool IT" :value="$money($totals['pool'])"
                    sub="bezahlt, unbesetzt" />
            </div>

            @if($totals['open'] > 0)
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-2.5 text-sm text-amber-800">
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline')
                    <strong>{{ $totals['open'] }} Position(en) ohne Lizenz</strong> — diese Beträge fließen in
                    keine Kostenstelle. Einmal zuordnen genügt; die Zuordnung gilt danach für alle Monate.
                </div>
            @endif

            <x-asset-manager-panel title="Vertragszeilen {{ $period }}">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-[var(--am-text-muted)] border-b border-[var(--am-border)]">
                                <th class="py-2 pr-3">Position</th>
                                <th class="py-2 pr-3">Lizenz</th>
                                <th class="py-2 pr-3">Bindung</th>
                                <th class="py-2 pr-3">Kündbar</th>
                                <th class="py-2 pr-3 text-right">Menge</th>
                                <th class="py-2 pr-3 text-right">Monatspreis</th>
                                <th class="py-2 pr-3 text-right">Summe</th>
                                <th class="py-2 pr-3">Verteilung</th>
                                @if($canManage)<th class="py-2"></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lines as $line)
                                <tr class="border-b border-[var(--am-border-subtle)] last:border-0 align-top">
                                    <td class="py-2 pr-3">
                                        <div>{{ $line->edition }}</div>
                                        @if($line->discount_percent > 0)
                                            <div class="text-[11px] text-[var(--am-text-muted)] tabular-nums">
                                                Liste {{ $money($line->list_price) }} − {{ number_format($line->discount_percent, 1, ',', '.') }} %
                                            </div>
                                        @endif
                                    </td>

                                    <td class="py-2 pr-3">
                                        @if($line->isMatched())
                                            <div class="text-xs">{{ $skuNames[$line->sku_id] ?? $line->sku_id }}</div>
                                            @if($line->match_state === 'manual')
                                                <x-asset-manager-badge color="sky">von Hand</x-asset-manager-badge>
                                            @endif
                                        @elseif($line->match_state === 'ignored')
                                            <x-asset-manager-badge color="slate">ausgeschlossen</x-asset-manager-badge>
                                        @else
                                            <x-asset-manager-badge color="amber">offen</x-asset-manager-badge>
                                        @endif
                                    </td>

                                    <td class="py-2 pr-3">
                                        <x-asset-manager-badge :color="$line->isYearly() ? 'sky' : 'slate'">
                                            {{ $line->isYearly() ? 'Jahr' : 'Monat' }}
                                        </x-asset-manager-badge>
                                        @unless($line->binding_confirmed)
                                            <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5">unbestätigt</div>
                                        @endunless
                                    </td>

                                    <td class="py-2 pr-3 text-xs tabular-nums">
                                        {{ $line->cancellable_at?->format('d.m.Y') ?? '—' }}
                                    </td>

                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $seats($line->quantity) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $money($line->unit_price) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums font-medium">{{ $money($line->monthlyAmount()) }}</td>

                                    <td class="py-2 pr-3 text-xs">
                                        @if($line->isMatched())
                                            <span class="tabular-nums">{{ $seats($line->assigned_quantity) }}</span> Träger
                                            @if($line->overflow_quantity > 0)
                                                · <span class="tabular-nums">{{ $seats($line->overflow_quantity) }}</span> Gemeinkosten
                                            @endif
                                            @if($line->pool_quantity > 0)
                                                · <span class="tabular-nums">{{ $seats($line->pool_quantity) }}</span> Pool
                                            @endif
                                            @unless($line->quantityBalances())
                                                <x-asset-manager-badge color="red">Bilanz stimmt nicht</x-asset-manager-badge>
                                            @endunless
                                        @else
                                            <span class="text-[var(--am-text-muted)]">nicht verteilt</span>
                                        @endif
                                    </td>

                                    @if($canManage)
                                        <td class="py-2 text-right whitespace-nowrap">
                                            <x-asset-manager-button variant="ghost" size="sm" wire:click="startAssign({{ $line->id }})">
                                                Lizenz
                                            </x-asset-manager-button>
                                            <x-asset-manager-button variant="ghost" size="sm" wire:click="startEdit({{ $line->id }})">
                                                Bindung
                                            </x-asset-manager-button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="text-[11px] text-[var(--am-text-muted)] mt-3">
                    Der Monatspreis ist Listenpreis minus Rabatt; der Rabatt steckt darin und wird nicht
                    getrennt gebucht. Mengen sind der Stand zum Monatsersten — ein Seat kostet den vollen
                    Monat, auch wenn er später dazukam. Die Bindung ist Flexibilitätsinformation und
                    steuert die Verteilung, sie verändert keinen Preis.
                </p>
            </x-asset-manager-panel>
        @endif

        {{-- Zuordnung: Modal innerhalb von x-ui-page (Konvention) --}}
        @if($assigningLineId)
            <x-asset-manager-panel title="Lizenz zuordnen">
                <div class="space-y-3">
                    <p class="text-xs text-[var(--am-text-secondary)]">
                        Die Zuordnung wird gemerkt und gilt für alle Monate. Ohne Auswahl gehört die
                        Position bewusst zu keiner Lizenz und fließt in keine Kostenstelle.
                    </p>

                    <x-asset-manager-select wire:model="assignSkuId">
                        <option value="">— keiner Lizenz zuordnen —</option>
                        @foreach($skus as $sku)
                            <option value="{{ $sku->sku_id }}">
                                {{ $sku->display_name ?: $sku->sku_part_number }}
                                ({{ $sku->consumed_units }}/{{ $sku->purchased_units }} Seats)
                            </option>
                        @endforeach
                    </x-asset-manager-select>

                    <div class="flex items-center gap-2">
                        <x-asset-manager-button variant="primary" size="sm" wire:click="saveAssign">
                            Zuordnen und verteilen
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="cancelAssign">
                            Abbrechen
                        </x-asset-manager-button>
                    </div>
                </div>
            </x-asset-manager-panel>
        @endif

        {{-- Bindung und Kündigungsdatum --}}
        @if($editingLineId)
            <x-asset-manager-panel title="Bindung und Kündigungsdatum">
                <div class="space-y-3">
                    <p class="text-xs text-[var(--am-text-secondary)]">
                        Die Bindung wurde aus der Rechnung vorbelegt. Wird sie hier bestätigt, überschreibt
                        sie kein Import mehr. Das Kündigungsdatum steht in keiner Rechnung.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Bindung</label>
                            <x-asset-manager-select wire:model="editBinding">
                                <option value="P1M">Monatsbindung — flexibel, teurer</option>
                                <option value="P1Y">Jahresbindung — günstiger, gebunden</option>
                            </x-asset-manager-select>
                            @error('editBinding')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror
                        </div>

                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Kündbar zum</label>
                            <x-asset-manager-input type="date" wire:model="editCancellableAt" />
                            @error('editCancellableAt')<span class="text-[11px] text-red-700">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-asset-manager-button variant="primary" size="sm" wire:click="saveEdit">
                            Speichern
                        </x-asset-manager-button>
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="cancelEdit">
                            Abbrechen
                        </x-asset-manager-button>
                    </div>
                </div>
            </x-asset-manager-panel>
        @endif
    </div>
</x-ui-page>
