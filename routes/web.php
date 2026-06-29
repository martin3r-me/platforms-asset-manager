<?php

use Platform\AssetManager\Livewire\Dashboard;
use Platform\AssetManager\Livewire\Connectors\Index as ConnectorsIndex;
use Platform\AssetManager\Livewire\Devices\Index as DevicesIndex;
use Platform\AssetManager\Livewire\Devices\Show as DevicesShow;
use Platform\AssetManager\Livewire\Devices\Status as DevicesStatus;
use Platform\AssetManager\Livewire\Compliance\Index as ComplianceIndex;
use Platform\AssetManager\Livewire\Licenses\Index as LicensesIndex;
use Platform\AssetManager\Livewire\Licenses\Show as LicensesShow;
use Platform\AssetManager\Livewire\Assets\Index as AssetsIndex;
use Platform\AssetManager\Livewire\Assets\Create as AssetsCreate;
use Platform\AssetManager\Livewire\Assets\Show as AssetsShow;
use Platform\AssetManager\Livewire\Inventory\Index as InventoryIndex;
use Platform\AssetManager\Livewire\Inventory\Show as InventoryShow;
use Platform\AssetManager\Livewire\Employees\Index as EmployeesIndex;
use Platform\AssetManager\Livewire\Employees\Show as EmployeesShow;
use Platform\AssetManager\Livewire\Costs\Dashboard as CostsDashboard;
use Platform\AssetManager\Livewire\Costs\Allocation as CostsAllocation;
use Platform\AssetManager\Livewire\Costs\Import as CostsImport;
use Platform\AssetManager\Livewire\Costs\ImportLog as CostsImportLog;
use Platform\AssetManager\Livewire\CostLines\Index as CostLinesIndex;
use Platform\AssetManager\Livewire\Reports\DeviceModels as DeviceModelsReport;
use Platform\AssetManager\Livewire\MasterData\Index as MasterDataIndex;
use Platform\AssetManager\Livewire\DeviceModels\Index as DeviceModelsIndex;
use Platform\AssetManager\Livewire\Printers\Index as PrintersIndex;
use Platform\AssetManager\Livewire\Internet\Index as InternetIndex;
use Platform\AssetManager\Livewire\Settings\Index as SettingsIndex;
use Platform\AssetManager\Http\Middleware\EnsureControllingEnabled;
use Platform\AssetManager\Livewire\Handovers\Index as HandoversIndex;
use Platform\AssetManager\Http\Controllers\HandoverPdfController;

Route::get('/', Dashboard::class)->name('asset-manager.dashboard');

Route::get('/devices', DevicesIndex::class)->name('asset-manager.devices.index');
// Literal-Route VOR der {device}-Wildcard registrieren, sonst matcht {device}='status'.
Route::get('/devices/status', DevicesStatus::class)->name('asset-manager.devices.status');
Route::get('/devices/{device}', DevicesShow::class)->name('asset-manager.devices.show');

Route::get('/compliance', ComplianceIndex::class)->name('asset-manager.compliance.index');

Route::get('/inventory', InventoryIndex::class)->name('asset-manager.inventory.index');
// Vereinte Detailseite (E1/E3): EINE Komponente, Typ-Dispatch via {type}=manual|intune + {id}.
// Getrennte Tabellen/IDs (item vs. device) kollidieren so nicht; geladen wird strikt nach Typ.
Route::get('/inventory/{type}/{id}', InventoryShow::class)
    ->whereIn('type', ['manual', 'intune'])
    ->whereNumber('id')
    ->name('asset-manager.inventory.show');

Route::get('/assets', AssetsIndex::class)->name('asset-manager.assets.index');
// Anlegen läuft jetzt als Modal auf der vereinten Inventar-Liste (Phase 1). Die alte Create-Seite
// leitet weiter und öffnet das Modal (?create=1) — Name bleibt erhalten, route()-Aufrufe/Bookmarks
// brechen nicht (Muster wie die MasterData-Redirects unten).
Route::get('/assets/create', fn () => redirect()->route('asset-manager.inventory.index', ['create' => 1]))->name('asset-manager.assets.create');
// Manuelle Asset-Detailseite ist in der vereinten Inventar-Detailseite aufgegangen (ADR 0012).
// Redirect erhält Bookmarks/route()-Aufrufe; {item} ist die ID (kein Model-Binding im Closure).
Route::get('/assets/{item}', fn ($item) => redirect()->route('asset-manager.inventory.show', ['type' => 'manual', 'id' => $item]))->name('asset-manager.assets.show');

Route::get('/employees', EmployeesIndex::class)->name('asset-manager.employees.index');
Route::get('/employees/{employee}', EmployeesShow::class)->name('asset-manager.employees.show');

// Geräteausgaben (Übergabeprotokolle): globale Liste + PDF-Protokoll.
Route::get('/handovers', HandoversIndex::class)->name('asset-manager.handovers.index');
Route::get('/handovers/{handover}/pdf', HandoverPdfController::class)->name('asset-manager.handovers.pdf');

Route::get('/licenses', LicensesIndex::class)->name('asset-manager.licenses.index');
Route::get('/licenses/{sku}', LicensesShow::class)->name('asset-manager.licenses.show');

Route::get('/printers', PrintersIndex::class)->name('asset-manager.printers.index');
Route::get('/internet', InternetIndex::class)->name('asset-manager.internet.index');

// Controlling-Schicht (per Team abschaltbar, ADR 0008): Auswertungen, Stammdaten, Kosten-Import.
// EnsureControllingEnabled leitet bei deaktiviertem Controlling auf das Dashboard um (statt 404),
// damit alte Bookmarks/route()-Aufrufe nicht ins Leere laufen.
Route::middleware(EnsureControllingEnabled::class)->group(function () {
    Route::get('/costs', CostsDashboard::class)->name('asset-manager.costs');
    Route::get('/costs/allocation', CostsAllocation::class)->name('asset-manager.costs.allocation');
    Route::get('/cost-lines', CostLinesIndex::class)->name('asset-manager.cost-lines.index');
    Route::get('/reports/device-models', DeviceModelsReport::class)->name('asset-manager.reports.device-models');
    Route::get('/costs/import', CostsImport::class)->name('asset-manager.costs.import');
    Route::get('/costs/import-log', CostsImportLog::class)->name('asset-manager.costs.import-log');

    // Stammdaten: alle vier Bereiche auf EINER Seite
    Route::get('/master-data', MasterDataIndex::class)->name('asset-manager.master-data.index');

    // Alte Einzel-Routen → Weiterleitung auf die kombinierte Seite (öffnet den passenden Bereich via ?bereich=).
    // Namen bleiben erhalten, damit bestehende route()-Aufrufe/Bookmarks weiter funktionieren.
    Route::get('/companies',    fn () => redirect(route('asset-manager.master-data.index', ['bereich' => 'companies'])))->name('asset-manager.companies.index');
    Route::get('/cost-centers', fn () => redirect(route('asset-manager.master-data.index', ['bereich' => 'cost-centers'])))->name('asset-manager.cost-centers.index');
    Route::get('/cost-types',   fn () => redirect(route('asset-manager.master-data.index', ['bereich' => 'cost-types'])))->name('asset-manager.cost-types.index');
    Route::get('/vendors',      fn () => redirect(route('asset-manager.master-data.index', ['bereich' => 'vendors'])))->name('asset-manager.vendors.index');
});

// Geräte-Modelle bleibt IT-Kern (Hardware-Katalog) — nur die Kosten-Spalten werden in der View
// bei deaktiviertem Controlling ausgeblendet (ADR 0008). Daher NICHT in der Controlling-Gruppe.
Route::get('/device-models', DeviceModelsIndex::class)->name('asset-manager.device-models.index');

// Konnektoren-Verwaltung (Multi-Tenant): Tenant-Liste + Microsoft-Anbindung je Tenant.
Route::get('/connectors', ConnectorsIndex::class)->name('asset-manager.connectors.index');

// Alte Connector-Route → Weiterleitung auf die neue Konnektoren-Seite (Bookmarks/route()-Aufrufe bleiben gültig).
Route::get('/setup', fn () => redirect(route('asset-manager.connectors.index')))->name('asset-manager.setup');

// Modul-Einstellungen (Controlling-Schalter u. a.) — immer erreichbar (IT-Kern, kein Controlling-Gate).
Route::get('/settings', SettingsIndex::class)->name('asset-manager.settings');
