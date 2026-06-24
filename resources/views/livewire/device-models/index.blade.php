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
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            <p class="text-xs text-[color:var(--ui-secondary)]">
                Preise je Geräte-Modell. Der Intune-Sync legt die real existierenden Modelle automatisch an —
                hier nur <strong>Leasing-Rate</strong> (mtl.) <em>oder</em> <strong>Kaufpreis + AfA-Monate</strong> sowie die
                <strong>Kostenart</strong> hinterlegen. Geräte erben den Wert; ein einzelnes Gerät kann ihn in der
                Geräte-Detailansicht überschreiben. Ohne zugeordnete Kostenart fließt der Betrag <em>nicht</em> in den Pivot.
            </p>

            {{-- Anlage (für Modelle ohne Sync) — nur owner/admin --}}
            @if($canManage)
                <div class="flex items-end gap-2 rounded-xl bg-white border border-black/5 shadow-sm p-4 flex-wrap">
                    <div>
                        <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Hersteller</label>
                        <input type="text" wire:model="newManufacturer" placeholder="z.B. Dell" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white w-40">
                    </div>
                    <div class="flex-1 min-w-[160px]">
                        <label class="block text-xs text-[color:var(--ui-secondary)] mb-1">Modell</label>
                        <input type="text" wire:model="newModel" placeholder="z.B. Latitude 5420" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                        @error('newModel')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                    </div>
                    <x-ui-button variant="primary" size="sm" rounded="lg" wire:click="create">Anlegen</x-ui-button>
                </div>
            @endif

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[color:var(--ui-muted)] text-xs uppercase tracking-wider text-[color:var(--ui-body-color)] font-semibold">
                            <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Hersteller</th>
                            <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Modell</th>
                            <th class="text-right px-4 py-3 bg-[color:var(--ui-muted-10)]">Geräte</th>
                            <th class="text-right px-4 py-3 bg-[color:var(--ui-muted-10)]">Leasing/Monat</th>
                            <th class="text-right px-4 py-3 bg-[color:var(--ui-muted-10)]">Kauf (AfA)</th>
                            <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Kostenart</th>
                            <th class="text-left px-4 py-3 bg-[color:var(--ui-muted-10)]">Kreditor</th>
                            <th class="px-4 py-3 bg-[color:var(--ui-muted-10)]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[color:var(--ui-muted)]">
                        @forelse($models as $m)
                            @php $hasPrice = $m->monthly_cost || ($m->purchase_price && $m->depreciation_months); @endphp
                            <tr class="hover:bg-[color:var(--ui-muted-10)]" wire:key="dm-{{ $m->id }}">
                                @if($editId === $m->id)
                                    <td class="px-4 py-2 text-[color:var(--ui-secondary)]">{{ $m->manufacturer ?: '—' }}</td>
                                    <td class="px-4 py-2 font-medium text-gray-800">{{ $m->model ?: '—' }}</td>
                                    <td class="px-4 py-2 text-right text-xs text-[color:var(--ui-secondary)]">{{ $m->device_count }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <input type="number" step="0.01" min="0" wire:model="eMonthly" placeholder="€/Mon" class="w-24 px-2 py-1 text-xs text-right rounded border border-[var(--ui-border)] bg-white">
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <input type="number" step="0.01" min="0" wire:model="ePurchase" placeholder="Kaufpreis" class="w-24 px-2 py-1 text-xs text-right rounded border border-[var(--ui-border)] bg-white">
                                        <input type="number" step="1" min="1" wire:model="eDep" placeholder="Mon." class="w-16 px-2 py-1 text-xs text-right rounded border border-[var(--ui-border)] bg-white ml-1">
                                    </td>
                                    <td class="px-4 py-2">
                                        <select wire:model="eCostType" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                            <option value="">– keine –</option>
                                            @foreach($costTypes as $ct)<option value="{{ $ct->id }}">{{ $ct->name }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2">
                                        <select wire:model="eVendor" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                            <option value="">– keiner –</option>
                                            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <button wire:click="saveEdit" class="text-xs text-[color:var(--ui-primary)]">Speichern</button>
                                        <button wire:click="$set('editId', null)" class="text-xs text-[color:var(--ui-secondary)] ml-1">Abbr.</button>
                                    </td>
                                @else
                                    <td class="px-4 py-2.5 text-[color:var(--ui-secondary)]">{{ $m->manufacturer ?: '—' }}</td>
                                    <td class="px-4 py-2.5 font-medium text-gray-800">
                                        {{ $m->model ?: '—' }}
                                        @if($hasPrice && !$m->cost_type_id)
                                            <x-asset-manager-badge color="amber" size="xs" class="ml-1" title="Preis ohne Kostenart fließt nicht in den Pivot">keine Kostenart</x-asset-manager-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs text-[color:var(--ui-secondary)]">{{ $m->device_count }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">{{ $m->monthly_cost ? number_format((float) $m->monthly_cost, 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">{{ ($m->purchase_price && $m->depreciation_months) ? number_format((float) $m->purchase_price, 2, ',', '.') . ' € / ' . $m->depreciation_months . ' M' : '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $m->costType?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $m->vendor?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        @if($canManage)
                                            <button wire:click="edit({{ $m->id }})" class="text-xs text-[color:var(--ui-secondary)] hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                            <button wire:click="delete({{ $m->id }})"
                                                    wire:confirm="Modell {{ $m->manufacturer }} {{ $m->model }} wirklich löschen?{{ $m->device_count ? ' '.$m->device_count.' Geräte verlieren ihren Modell-Preis.' : '' }}"
                                                    class="text-xs text-[color:var(--ui-secondary)] hover:text-red-600 ml-2">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-xs text-[color:var(--ui-secondary)]">Noch keine Geräte-Modelle. Sie erscheinen nach dem nächsten Intune-Sync automatisch.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-ui-page>
