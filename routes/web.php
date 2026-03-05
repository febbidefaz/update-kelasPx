<?php

use App\Http\Controllers\SepController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/daftar_pasien',[SepController::class,'daftar']);

Route::get('/updatekelas/{nosep}/{token}', [SepController::class,'updatekelas']);
Route::get('/sync-kelas-bpjs', function () {

    Artisan::call('bpjs:sync-kelas-inap');

    return "Sinkronisasi kelas BPJS selesai dijalankan";

});
Route::get('/sync-kelas/auto/{token}', [SepController::class, 'syncAutoPage']);
Route::get('/sync-kelas/run/{token}',  [SepController::class, 'syncRun']); // SSE
