<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte-Modelle', 'icon' => 'computer-desktop'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg">{{ $flash }}</div>
            @endif

            <p class="text-xs text-[var(--am-text-secondary)]">
                Preise je Geräte-Modell. Der Intune-Sync legt die real existierenden Modelle automatisch an —
                hier nur <strong>Leasing-Rate</strong> (mtl.) <em>oder</em> <strong>Kaufpreis + AfA-Monate</strong> sowie die
                <strong>Kostenart</strong> hinterlegen. Geräte erben den Wert; ein einzelnes Gerät kann ihn in der
                Geräte-Detailansicht überschreiben. Ohne zugeordnete Kostenart fließt der Betrag <em>nicht</em> in den Pivot.
            </p>

            {{-- Anlage (für Modelle ohne Sync) — nur owner/admin --}}
            @if($canManage)
                <x-asset-manager-panel title="Modell anlegen">
                    <div class="flex items-end gap-3 flex-wrap">
                        <div class="w-40">
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Hersteller</label>
                            <x-asset-manager-input size="md" type="text" wire:model="newManufacturer" placeholder="z.B. Dell" />
                        </div>
                        <div class="flex-1 min-w-[160px]">
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Modell</label>
                            <x-asset-manager-input size="md" type="text" wire:model="newModel" placeholder="z.B. Latitude 5420" />
                            @error('newModel')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                        </div>
                        <x-asset-manager-button variant="primary" size="md" wire:click="create">Anlegen</x-asset-manager-button>
                    </div>
                </x-asset-manager-panel>
            @endif

            <x-asset-manager-panel body-class="p-0">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-[var(--am-bg)] text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                            <th class="text-left px-4 py-3">Hersteller</th>
                            <th class="text-left px-4 py-3">Modell</th>
                            <th class="text-right px-4 py-3">Geräte</th>
                            <th class="text-right px-4 py-3">Leasing/Monat</th>
                            <th class="text-right px-4 py-3">Kauf (AfA)</th>
                            <th class="text-left px-4 py-3">Kostenart</th>
                            <th class="text-left px-4 py-3">Kreditor</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[color:var(--am-border)]">
                        @forelse($models as $m)
                            @php $hasPrice = $m->monthly_cost || ($m->purchase_price && $m->depreciation_months); @endphp
                            <tr class="hover:bg-[var(--am-bg)]" wire:key="dm-{{ $m->id }}">
                                @if($editId === $m->id)
                                    <td class="px-4 py-2 text-[var(--am-text-secondary)]">{{ $m->manufacturer ?: '—' }}</td>
                                    <td class="px-4 py-2 font-medium text-[var(--am-text)]">{{ $m->model ?: '—' }}</td>
                                    <td class="px-4 py-2 text-right text-xs text-[var(--am-text-secondary)]">{{ $m->device_count }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="eMonthly" placeholder="€/Mon" class="w-24 text-right" />
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="ePurchase" placeholder="Kaufpreis" class="w-24 text-right" />
                                        <x-asset-manager-input size="sm" type="number" step="1" min="1" wire:model="eDep" placeholder="Mon." class="w-16 text-right ml-1" />
                                    </td>
                                    <td class="px-4 py-2">
                                        <x-asset-manager-select size="sm" wire:model="eCostType">
                                            <option value="">– keine –</option>
                                            @foreach($costTypes as $ct)<option value="{{ $ct->id }}">{{ $ct->name }}</option>@endforeach
                                        </x-asset-manager-select>
                                    </td>
                                    <td class="px-4 py-2">
                                        <x-asset-manager-select size="sm" wire:model="eVendor">
                                            <option value="">– keiner –</option>
                                            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                        </x-asset-manager-select>
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <button wire:click="saveEdit" class="text-xs text-[var(--am-accent)]">Speichern</button>
                                        <button wire:click="$set('editId', null)" class="text-xs text-[var(--am-text-secondary)] ml-1">Abbr.</button>
                                    </td>
                                @else
                                    <td class="px-4 py-2.5 text-[var(--am-text-secondary)]">{{ $m->manufacturer ?: '—' }}</td>
                                    <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">
                                        {{ $m->model ?: '—' }}
                                        @if($hasPrice && !$m->cost_type_id)
                                            <x-asset-manager-badge color="amber" size="xs" class="ml-1" title="Preis ohne Kostenart fließt nicht in den Pivot">keine Kostenart</x-asset-manager-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs text-[var(--am-text-secondary)]">{{ $m->device_count }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-[var(--am-text-secondary)]">{{ $m->monthly_cost ? number_format((float) $m->monthly_cost, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-[var(--am-text-secondary)]">{{ ($m->purchase_price && $m->depreciation_months) ? number_format((float) $m->purchase_price, 2, ',', '.') . ' € / ' . $m->depreciation_months . ' M' : '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $m->costType?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $m->vendor?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        @if($canManage)
                                            <button wire:click="edit({{ $m->id }})" class="text-xs text-[var(--am-text-secondary)] hover:text-[var(--am-accent)]">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                            <button wire:click="delete({{ $m->id }})"
                                                    wire:confirm="Modell {{ $m->manufacturer }} {{ $m->model }} wirklich löschen?{{ $m->device_count ? ' '.$m->device_count.' Geräte verlieren ihren Modell-Preis.' : '' }}"
                                                    class="text-xs text-[var(--am-text-secondary)] hover:text-red-600 ml-2">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-xs text-[var(--am-text-muted)]">Noch keine Geräte-Modelle. Sie erscheinen nach dem nächsten Intune-Sync automatisch.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </x-asset-manager-panel>

        </div>
    </div>
</x-ui-page>
