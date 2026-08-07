{{--
    Hinweis für Auswertungs-Sichten im „Alle Tenants"-Modus (docs/adr/0016).

    Warum überhaupt ein Hinweis: sonst ist einer Zahl nicht anzusehen, ob sie einen Kunden oder alle
    zusammen meint — und genau diese Verwechslung ist bei Kostenzahlen teuer. Der Umschalter steht in
    der Sidebar und damit nicht im Blickfeld, wenn man auf eine Kachel schaut.

    Nutzung: <x-asset-manager-all-tenants-notice :active="$showsAllTenants ?? false" />
--}}
@props(['active' => false])

@if($active)
    <div class="flex items-start gap-2 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-900">
        @svg('heroicon-o-building-office-2', 'w-4 h-4 flex-shrink-0 mt-0.5')
        <p class="text-xs m-0">
            <span class="font-semibold">Alle Tenants:</span>
            Die Zahlen fassen <span class="font-semibold">alle Kundenkontexte</span> dieses Teams zusammen.
            Für eine kundenreine Sicht links in der Seitenleiste einen Tenant wählen.
        </p>
    </div>
@endif
