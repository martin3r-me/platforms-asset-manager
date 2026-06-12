<?php

use Platform\AssetManager\Livewire\Dashboard;
use Platform\AssetManager\Livewire\ConnectorSetup;
use Platform\AssetManager\Livewire\Devices\Index as DevicesIndex;
use Platform\AssetManager\Livewire\Devices\Show as DevicesShow;
use Platform\AssetManager\Livewire\Licenses\Index as LicensesIndex;
use Platform\AssetManager\Livewire\Licenses\Show as LicensesShow;
use Platform\AssetManager\Livewire\Assets\Index as AssetsIndex;
use Platform\AssetManager\Livewire\Assets\Create as AssetsCreate;
use Platform\AssetManager\Livewire\Assets\Show as AssetsShow;
use Platform\AssetManager\Livewire\Employees\Index as EmployeesIndex;
use Platform\AssetManager\Livewire\Employees\Show as EmployeesShow;
use Platform\AssetManager\Livewire\Costs\Dashboard as CostsDashboard;

Route::get('/', Dashboard::class)->name('asset-manager.dashboard');

Route::get('/devices', DevicesIndex::class)->name('asset-manager.devices.index');
Route::get('/devices/{device}', DevicesShow::class)->name('asset-manager.devices.show');

Route::get('/assets', AssetsIndex::class)->name('asset-manager.assets.index');
Route::get('/assets/create', AssetsCreate::class)->name('asset-manager.assets.create');
Route::get('/assets/{item}', AssetsShow::class)->name('asset-manager.assets.show');

Route::get('/employees', EmployeesIndex::class)->name('asset-manager.employees.index');
Route::get('/employees/{employee}', EmployeesShow::class)->name('asset-manager.employees.show');

Route::get('/licenses', LicensesIndex::class)->name('asset-manager.licenses.index');
Route::get('/licenses/{sku}', LicensesShow::class)->name('asset-manager.licenses.show');

Route::get('/costs', CostsDashboard::class)->name('asset-manager.costs');

Route::get('/setup', ConnectorSetup::class)->name('asset-manager.setup');
