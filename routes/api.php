<?php

use Platform\AssetManager\Http\Controllers\Api\AssetDeviceController;

Route::get('/devices', [AssetDeviceController::class, 'index'])->name('asset-manager.api.devices.index');
Route::get('/devices/{device}', [AssetDeviceController::class, 'show'])->name('asset-manager.api.devices.show');
