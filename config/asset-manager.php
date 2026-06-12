<?php

/**
 * Asset Manager Configuration
 * 
 * Diese Config-Datei definiert die Konfiguration für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Ersetze "asset-manager" durch deinen Modul-Namen
 * - Ersetze "AssetManager" durch deinen Modul-Namen (PascalCase)
 * - Alle Routes müssen mit dem Modul-Prefix beginnen
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
        'mode' => env('MODULE_TEMPLATE_MODE', 'path'),
        'prefix' => 'asset-manager',
    ],
    
    /**
     * Guard für Authentication
     * Standard: 'web'
     */
    'guard' => 'web',

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
                [
                    'label' => 'Alle Assets',
                    'route' => 'asset-manager.assets.index',
                    'icon'  => 'heroicon-o-cube-transparent',
                ],
                [
                    'label' => 'Intune-Geräte',
                    'route' => 'asset-manager.devices.index',
                    'icon'  => 'heroicon-o-computer-desktop',
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
                    'label' => 'Kostenstellen',
                    'route' => 'asset-manager.cost-centers.index',
                    'icon'  => 'heroicon-o-clipboard-document-list',
                ],
                [
                    'label' => 'Kreditoren',
                    'route' => 'asset-manager.vendors.index',
                    'icon'  => 'heroicon-o-building-storefront',
                ],
                [
                    'label' => 'Kostenarten',
                    'route' => 'asset-manager.cost-types.index',
                    'icon'  => 'heroicon-o-tag',
                ],
                [
                    'label' => 'Connector',
                    'route' => 'asset-manager.setup',
                    'icon'  => 'heroicon-o-wrench-screwdriver',
                ],
            ],
        ],
    ],
];
