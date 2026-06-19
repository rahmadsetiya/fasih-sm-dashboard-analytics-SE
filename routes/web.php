<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseImportController;
use App\Http\Controllers\RegionNameController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::redirect('/dashboard', '/'); // backward compat
    Route::get('/api/data', [DashboardController::class, 'data'])->name('dashboard.data');
    Route::get('/api/snapshots', [DashboardController::class, 'snapshots'])->name('dashboard.snapshots');

    Route::get('/api/db-status', [DatabaseImportController::class, 'status'])->name('db.status');
    Route::post('/import-db', [DatabaseImportController::class, 'store'])->name('db.import');

    Route::get('/api/region-names', [RegionNameController::class, 'index'])->name('region-names.index');
    Route::post('/api/region-names/import', [RegionNameController::class, 'importCsv'])->name('region-names.import');
    Route::delete('/api/region-names/all', [RegionNameController::class, 'destroyAll'])->name('region-names.destroy-all');
    Route::delete('/api/region-names/{code}', [RegionNameController::class, 'destroy'])->name('region-names.destroy');
});

require __DIR__.'/settings.php';
