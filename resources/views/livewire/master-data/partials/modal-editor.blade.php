{{-- Stammdaten anlegen/bearbeiten. Lag bis S8b in einer zugeklappten rechten Sidebar (ADR 0012).
     Das Formular je Bereich kommt weiterhin aus den bestehenden Partials. --}}
{{-- hideFooter: Speichern/Abbrechen/Löschen liegen bereits in form-actions je Bereichsformular. --}}
<x-ui-modal model="showEditor" size="lg" :hideFooter="true">
    <x-slot name="header">{{ ($creating ? 'Neu: ' : '') . $current['singular'] }}</x-slot>

    <div class="space-y-3">
        @switch($active)
            @case('cost-centers')
                @include('asset-manager::livewire.master-data.partials.form-cost-centers', ['parents' => $parentOptions])
                @break
            @case('cost-types')
                @include('asset-manager::livewire.master-data.partials.form-cost-types', ['vendors' => $vendorsForSelect])
                @break
            @case('vendors')
                @include('asset-manager::livewire.master-data.partials.form-vendors')
                @break
        @endswitch
    </div>
</x-ui-modal>
