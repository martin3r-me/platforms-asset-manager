<?php

/**
 * Asset Manager Web Routes
 * 
 * Diese Datei definiert alle Web-Routes für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Routes werden automatisch mit dem Modul-Prefix versehen (aus Config)
 * - Middleware wird automatisch hinzugefügt (web, auth, etc.)
 * - Route-Namen sollten mit dem Modul-Prefix beginnen
 * 
 * BEISPIEL:
 * Route::get('/', Dashboard::class)->name('asset-manager.dashboard');
 * 
 * Wird zu: /asset-manager/ (wenn prefix = 'asset-manager')
 * 
 * @see Platform\Core\Routing\ModuleRouter für Details
 */

use Platform\AssetManager\Livewire\Dashboard;
use Platform\AssetManager\Livewire\Test;
use Platform\AssetManager\Livewire\Sidebar;

/**
 * Dashboard Route
 * 
 * Hauptübersicht des Moduls
 */
Route::get('/', Dashboard::class)->name('asset-manager.dashboard');

/**
 * Test Route
 * 
 * Test-Seite für Entwicklung und Demonstration
 */
Route::get('/test', Test::class)->name('asset-manager.test');

/**
 * Weitere Routes hinzufügen:
 * 
 * Route::get('/entities', Entity\Index::class)->name('asset-manager.entities.index');
 * Route::get('/entities/{entity}', Entity\Show::class)->name('asset-manager.entities.show');
 */
