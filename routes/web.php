<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseImportController;
use App\Http\Controllers\GeoController;
use App\Http\Controllers\HeatmapController;
use App\Http\Controllers\OfficerProjectionController;
use App\Http\Controllers\PenugasanController;
use App\Http\Controllers\PetugasController;
use App\Http\Controllers\PetugasNameController;
use App\Http\Controllers\RegionNameController;
use App\Http\Controllers\StatistikController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    $redirectDisabledAnalyticsPage = function () {
        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => 'Halaman ini sementara dinonaktifkan karena data belum dapat diperbarui secara andal.',
        ]);

        return redirect()->route('dashboard');
    };

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::redirect('/dashboard', '/'); // backward compat
    Route::get('/api/data', [DashboardController::class, 'data'])->name('dashboard.data');
    Route::get('/api/snapshots', [DashboardController::class, 'snapshots'])->name('dashboard.snapshots');

    Route::get('/ringkasan', [DashboardController::class, 'ringkasan'])->name('ringkasan');
    Route::get('/api/ringkasan-data', [DashboardController::class, 'ringkasanData'])->name('ringkasan.data');
    Route::get('/changelog', fn () => Inertia::render('Changelog', [
        'currentVersion' => config('app.version'),
        'releases' => config('releases.history', []),
    ]))->name('changelog');

    Route::get('/peta', [GeoController::class, 'index'])->name('peta');
    Route::get('/api/geo/boundaries', [GeoController::class, 'boundaries'])->name('geo.boundaries');
    Route::get('/api/geo/metrics', [GeoController::class, 'metrics'])->name('geo.metrics');
    Route::get('/api/geo/officers', [GeoController::class, 'officers'])->name('geo.officers');
    Route::get('/api/geo/officers/{userId}/regions', [GeoController::class, 'officerRegions'])->name('geo.officers.regions');
    Route::get('/api/geo/regions/{level}/{regionId}', [GeoController::class, 'regionDetail'])->name('geo.regions.detail');
    Route::get('/api/geo/regions/{idsubsls}', [GeoController::class, 'region'])->name('geo.regions.show');

    Route::get('/proyeksi', [OfficerProjectionController::class, 'index'])->name('proyeksi');
    Route::get('/api/projections/officers', [OfficerProjectionController::class, 'officers'])->name('projections.officers');
    Route::get('/api/projections/officers/{officerKey}', [OfficerProjectionController::class, 'detail'])->name('projections.officers.detail');

    Route::get('/heatmap', $redirectDisabledAnalyticsPage)->name('heatmap');
    Route::get('/api/heatmap', [HeatmapController::class, 'data'])->name('heatmap.data');
    Route::get('/api/heatmap/hourly', [HeatmapController::class, 'hourly'])->name('heatmap.hourly');

    Route::get('/petugas', $redirectDisabledAnalyticsPage)->name('petugas');
    Route::get('/api/petugas/list', [PetugasController::class, 'list'])->name('petugas.list');
    Route::get('/api/petugas/turnaround', [PetugasController::class, 'turnaround'])->name('petugas.turnaround');
    Route::get('/api/petugas/quality', [PetugasController::class, 'quality'])->name('petugas.quality');
    Route::get('/api/petugas/gelombang', [PetugasController::class, 'gelombang'])->name('petugas.gelombang');
    Route::get('/api/petugas/matrix', [PetugasController::class, 'matrix'])->name('petugas.matrix');

    Route::get('/penugasan', $redirectDisabledAnalyticsPage)->name('penugasan');
    Route::get('/api/penugasan', [PenugasanController::class, 'data'])->name('penugasan.data');
    Route::get('/api/penugasan/history', [PenugasanController::class, 'history'])->name('penugasan.history');
    Route::get('/api/penugasan/mangkrak', [PenugasanController::class, 'mangkrak'])->name('penugasan.mangkrak');

    Route::get('/statistik', $redirectDisabledAnalyticsPage)->name('statistik');
    Route::get('/api/statistik/proporsi', [StatistikController::class, 'proporsi'])->name('statistik.proporsi');
    Route::get('/api/statistik/komparasi', [StatistikController::class, 'komparasi'])->name('statistik.komparasi');
    Route::get('/api/statistik/chi2', [StatistikController::class, 'chi2'])->name('statistik.chi2');
    Route::get('/api/statistik/korelasi', [StatistikController::class, 'korelasi'])->name('statistik.korelasi');
    Route::get('/api/statistik/bangunan-kosong', [StatistikController::class, 'bangunanKosong'])->name('statistik.bangunan-kosong');

    Route::get('/api/db-status', [DatabaseImportController::class, 'status'])->name('db.status');
    Route::post('/import-db', [DatabaseImportController::class, 'store'])->name('db.import');

    Route::get('/api/region-names', [RegionNameController::class, 'index'])->name('region-names.index');
    Route::post('/api/region-names/import', [RegionNameController::class, 'importCsv'])->name('region-names.import');
    Route::delete('/api/region-names/all', [RegionNameController::class, 'destroyAll'])->name('region-names.destroy-all');
    Route::delete('/api/region-names/{code}', [RegionNameController::class, 'destroy'])->name('region-names.destroy');

    Route::get('/api/petugas-names', [PetugasNameController::class, 'index'])->name('petugas-names.index');
    Route::post('/api/petugas-names', [PetugasNameController::class, 'upsert'])->name('petugas-names.upsert');
    Route::delete('/api/petugas-names/{username}', [PetugasNameController::class, 'destroy'])->name('petugas-names.destroy');

    // Admin panel
    Route::middleware('can:admin')->prefix('admin')->group(function () {
        Route::get('/download-db', [DatabaseImportController::class, 'download'])->name('admin.db.download');
        Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
    });
});

require __DIR__.'/settings.php';
