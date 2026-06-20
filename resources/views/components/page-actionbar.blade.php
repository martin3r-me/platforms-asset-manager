@props(['breadcrumbs' => []])
{{--
    Adapter (M3-Fix): nutzt das UI-Modul-Actionbar UNVERAENDERT und reicht unseren gewohnten
    benannten "actions"-Slot in dessen Default-Slot durch. Hintergrund: x-ui-page-actionbar
    rendert rechts nur den Default-Slot, KEINEN actions-Slot. Diese Datei ist die EINZIGE Stelle,
    an der unser Modul an diesen Slot-Vertrag koppelt — aendert das UI-Modul ihn erneut, wird
    nur hier angepasst (nicht in jeder View).
--}}
<x-ui-page-actionbar :breadcrumbs="$breadcrumbs">
    {{ $actions ?? '' }}{{ $slot }}
</x-ui-page-actionbar>
