{{-- Suche + bereichsspezifische Filter. Vars: $areas, $companies, $active, $search, $filterCompany, $onlyActive, $filterSource --}}
<section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
    <div class="px-3 pb-3">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Name suchen..."
               class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
    </div>
</section>

@switch($active)
    @case('cost-centers')
        <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Gesellschaft</h3>
            <div class="px-3 pb-3 space-y-2">
                <select wire:model.live="filterCompany" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                    <option value="">Alle</option>
                    @foreach($companies as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
                </select>
                <label class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)]">
                    <input type="checkbox" wire:model.live="onlyActive" class="rounded"> Nur aktive
                </label>
            </div>
        </section>
        @break

    @case('cost-types')
        <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Quelle</h3>
            <div class="px-3 pb-3">
                <select wire:model.live="filterSource" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                    <option value="">Alle</option>
                    <option value="cost_line">Kostenposition</option>
                    <option value="hardware_afa">Hardware-AfA</option>
                    <option value="ms_license">MS-Lizenz (Graph)</option>
                    <option value="asset_device">Geräte-Kosten</option>
                </select>
            </div>
        </section>
        @break
@endswitch

@if($search || $filterCompany || $onlyActive || $filterSource)
    <button wire:click="$set('search', ''); $set('filterCompany', null); $set('onlyActive', false); $set('filterSource', '')"
            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10">
        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
        Filter zurücksetzen
    </button>
@endif
