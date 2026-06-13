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
use Platform\AssetManager\Livewire\Costs\Allocation as CostsAllocation;
use Platform\AssetManager\Livewire\Costs\Import as CostsImport;
use Platform\AssetManager\Livewire\Costs\ImportLog as CostsImportLog;
use Platform\AssetManager\Livewire\CostLines\Index as CostLinesIndex;
use Platform\AssetManager\Livewire\MasterData\Index as MasterDataIndex;
use Platform\AssetManager\Livewire\DeviceModels\Index as DeviceModelsIndex;
use Platform\AssetManager\Livewire\Printers\Index as PrintersIndex;
use Platform\AssetManager\Livewire\Internet\Index as InternetIndex;

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

Route::get('/printers', PrintersIndex::class)->name('asset-manager.printers.index');
Route::get('/internet', InternetIndex::class)->name('asset-manager.internet.index');

Route::get('/costs', CostsDashboard::class)->name('asset-manager.costs');
Route::get('/costs/allocation', CostsAllocation::class)->name('asset-manager.costs.allocation');
Route::get('/cost-lines', CostLinesIndex::class)->name('asset-manager.cost-lines.index');
Route::get('/costs/import', CostsImport::class)->name('asset-manager.costs.import');
Route::get('/costs/import-log', CostsImportLog::class)->name('asset-manager.costs.import-log');

// Stammdaten: alle vier Bereiche auf EINER Seite
Route::get('/master-data', MasterDataIndex::class)->name('asset-manager.master-data.index');

// Alte Einzel-Routen → Weiterleitung auf die kombinierte Seite (Anker zum passenden Abschnitt).
// Namen bleiben erhalten, damit bestehende route()-Aufrufe/Bookmarks weiter funktionieren.
Route::get('/companies',    fn () => redirect(route('asset-manager.master-data.index') . '#gesellschaften'))->name('asset-manager.companies.index');
Route::get('/cost-centers', fn () => redirect(route('asset-manager.master-data.index') . '#kostenstellen'))->name('asset-manager.cost-centers.index');
Route::get('/cost-types',   fn () => redirect(route('asset-manager.master-data.index') . '#kostenarten'))->name('asset-manager.cost-types.index');
Route::get('/vendors',      fn () => redirect(route('asset-manager.master-data.index') . '#kreditoren'))->name('asset-manager.vendors.index');

Route::get('/device-models', DeviceModelsIndex::class)->name('asset-manager.device-models.index');

Route::get('/setup', ConnectorSetup::class)->name('asset-manager.setup');
