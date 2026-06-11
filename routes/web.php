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
use Platform\AssetManager\Livewire\ConnectorSetup;
use Platform\AssetManager\Livewire\Devices\Index as DevicesIndex;
use Platform\AssetManager\Livewire\Devices\Show as DevicesShow;
use Platform\AssetManager\Livewire\Licenses\Index as LicensesIndex;
use Platform\AssetManager\Livewire\Licenses\Show as LicensesShow;

Route::get('/', Dashboard::class)->name('asset-manager.dashboard');
Route::get('/devices', DevicesIndex::class)->name('asset-manager.devices.index');
Route::get('/devices/{device}', DevicesShow::class)->name('asset-manager.devices.show');
Route::get('/licenses', LicensesIndex::class)->name('asset-manager.licenses.index');
Route::get('/licenses/{sku}', LicensesShow::class)->name('asset-manager.licenses.show');
Route::get('/setup', ConnectorSetup::class)->name('asset-manager.setup');
