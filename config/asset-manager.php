<?php

/**
 * Asset Manager — Modul-Konfiguration (`config('asset-manager.*')`).
 *
 * Wird in AssetManagerServiceProvider::register() per mergeConfigFrom() geladen; boot() meldet
 * `routing`, `guard`, `navigation` und `sidebar` über PlatformCore::registerModule() an den Core.
 * Die Sidebar, die man im Modul sieht, rendert resources/views/livewire/sidebar.blade.php —
 * Änderungen an der Navigation gehören dorthin.
 *
 * Inhalt:
 * - routing / guard / navigation / sidebar — Andockpunkte an den Core (Modul-Template-Vertrag).
 *   Routing-Modus über `ASSET_MANAGER_MODE`; der Template-Schlüssel `MODULE_TEMPLATE_MODE`
 *   wirkt als Rückfall weiter (bestehende .env-Einträge der Host-App bleiben gültig).
 * - azure — zentrale Multi-Tenant-Azure-App für alle Microsoft-Connectoren (`.env`:
 *   ASSET_MANAGER_AZURE_*). Pro Connector liegt nur das Kunden-Verzeichnis; Consent ist manuell
 *   („Anbindung prüfen"), siehe docs/connector-microsoft-onboarding.md.
 *
 * Kein `billables`-Block: das Modul rechnet seine Kosten nicht über die Plattform-Abrechnung ab.
 * Tenant-/Team-spezifische Einstellungen leben in der DB (asset_team_settings), nicht hier.
 *
 * @see Platform\Core\PlatformCore::registerModule() für Details zur Modul-Registrierung
 */

return [
    /**
     * Routing-Konfiguration
     * 
     * 'mode': 'path' = /asset-manager/... (Standard)
     *         'subdomain' = asset-manager.domain.com/... (Alternative)
     * 'prefix': URL-Präfix für alle Routes
     */
    'routing' => [
        // Eigener Schlüssel ASSET_MANAGER_MODE (#878); der Template-Name bleibt als Rückfall, damit
        // bestehende .env-Einträge der Host-App weiter wirken.
        'mode' => env('ASSET_MANAGER_MODE', env('MODULE_TEMPLATE_MODE', 'path')),
        'prefix' => 'asset-manager',
    ],
    
    /**
     * Guard für Authentication
     * Standard: 'web'
     */
    'guard' => 'web',

    /**
     * Zentrale Microsoft-/Azure-App (Multi-Tenant)
     *
     * EINE App-Registrierung, geteilt über ALLE Connectoren. Pro Connector wird nur das Kunden-
     * Verzeichnis (azure_tenant_id) gehalten; client_id/secret kommen von hier.
     * Übergangs-Sicherheit: hat ein Connector eigene client_id/client_secret hinterlegt, haben diese
     * Vorrang (IntuneGraphService-Fallback) — so läuft der bestehende Connector weiter, bis die
     * zentrale App registriert + vom jeweiligen Kunden-Tenant konsentiert ist.
     *
     * 'redirect_uri' wird beim Admin-Consent-Link mitgegeben (muss in der App registriert sein),
     * aber NICHT von uns verarbeitet (manueller Consent: Operator klickt danach „Anbindung prüfen").
     */
    'azure' => [
        'client_id'     => env('ASSET_MANAGER_AZURE_CLIENT_ID'),
        'client_secret' => env('ASSET_MANAGER_AZURE_CLIENT_SECRET'),
        'redirect_uri'  => env('ASSET_MANAGER_AZURE_REDIRECT_URI', 'https://login.microsoftonline.com/common/oauth2/nativeclient'),
    ],

    /**
     * Navigation-Konfiguration
     * 
     * Definiert, wie das Modul in der Hauptnavigation erscheint.
     * 'route': Route-Name für den Link
     * 'icon': Heroicon-Name (ohne heroicon-o- Präfix)
     * 'order': Sortier-Reihenfolge (niedrigere Zahlen = weiter oben)
     */
    'navigation' => [
        'route' => 'asset-manager.dashboard',
        'icon'  => 'heroicon-o-cube',
        'order' => 100, // Hohe Zahl = weiter unten in der Navigation
    ],

    /**
     * Sidebar-Konfiguration
     * 
     * Definiert die Sidebar-Struktur für das Modul.
     * 
     * Struktur:
     * - 'group': Gruppenname (optional)
     * - 'items': Array von Sidebar-Items
     *   - 'label': Anzeige-Text
     *   - 'route': Route-Name
     *   - 'icon': Heroicon-Name
     * 
     * Alternative: 'dynamic' für dynamische Listen (z.B. aus Datenbank)
     *   - 'model': Model-Klasse
     *   - 'team_based': true/false (nach Team filtern)
     *   - 'order_by': Sortier-Feld
     *   - 'route': Basis-Route (wird mit ID erweitert)
     *   - 'icon': Icon für alle Items
     *   - 'label_key': Feldname für Label
     */
    'sidebar' => [
        [
            'group' => 'Übersicht',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'asset-manager.dashboard',
                    'icon'  => 'heroicon-o-home',
                ],
            ],
        ],
        [
            'group' => 'Assets',
            'items' => [
                // E1: „Inventar" ist das kanonische Dach über manuelle Assets + Intune-Geräte
                // (eine vereinte Liste). Einstieg zeigt daher auf die vereinte Inventar-Sicht.
                [
                    'label' => 'Inventar',
                    'route' => 'asset-manager.inventory.index',
                    'icon'  => 'heroicon-o-rectangle-group',
                ],
                [
                    'label' => 'Intune-Geräte',
                    'route' => 'asset-manager.devices.index',
                    'icon'  => 'heroicon-o-computer-desktop',
                ],
                [
                    'label' => 'Geräte-Status',
                    'route' => 'asset-manager.devices.status',
                    'icon'  => 'heroicon-o-signal',
                ],
                [
                    'label' => 'Geräteausgaben',
                    'route' => 'asset-manager.handovers.index',
                    'icon'  => 'heroicon-o-clipboard-document-check',
                ],
                [
                    'label' => 'Lizenzen',
                    'route' => 'asset-manager.licenses.index',
                    'icon'  => 'heroicon-o-key',
                ],
                [
                    'label' => 'Drucker',
                    'route' => 'asset-manager.printers.index',
                    'icon'  => 'heroicon-o-printer',
                ],
                [
                    'label' => 'Internet',
                    'route' => 'asset-manager.internet.index',
                    'icon'  => 'heroicon-o-wifi',
                ],
            ],
        ],
        [
            'group' => 'Mitarbeiter',
            'items' => [
                [
                    'label' => 'Alle Mitarbeiter',
                    'route' => 'asset-manager.employees.index',
                    'icon'  => 'heroicon-o-users',
                ],
            ],
        ],
        [
            'group' => 'Auswertungen',
            'items' => [
                [
                    'label' => 'Kostenaufteilung',
                    'route' => 'asset-manager.costs.allocation',
                    'icon'  => 'heroicon-o-table-cells',
                ],
                [
                    'label' => 'Kostenpositionen',
                    'route' => 'asset-manager.cost-lines.index',
                    'icon'  => 'heroicon-o-list-bullet',
                ],
                [
                    'label' => 'Kosten (pro MA)',
                    'route' => 'asset-manager.costs',
                    'icon'  => 'heroicon-o-banknotes',
                ],
            ],
        ],
        [
            'group' => 'Einstellungen',
            'items' => [
                [
                    'label' => 'Stammdaten',
                    'route' => 'asset-manager.master-data.index',
                    'icon'  => 'heroicon-o-rectangle-stack',
                ],
                [
                    'label' => 'Kosten-Import',
                    'route' => 'asset-manager.costs.import',
                    'icon'  => 'heroicon-o-arrow-up-tray',
                ],
                [
                    'label' => 'Import-Log',
                    'route' => 'asset-manager.costs.import-log',
                    'icon'  => 'heroicon-o-document-magnifying-glass',
                ],
                [
                    'label' => 'Konnektoren',
                    'route' => 'asset-manager.connectors.index',
                    'icon'  => 'heroicon-o-wrench-screwdriver',
                ],
            ],
        ],
    ],
];
