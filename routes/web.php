<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
use Illuminate\Support\Facades\Artisan;

Route::get('/run-migrations', function () {
    try {
        Artisan::call('migrate', ['--force' => true]);
        return 'Migrations completed successfully!<br><pre>' . Artisan::output() . '</pre>';
    } catch (\Exception $e) {
        return 'Migration failed: ' . $e->getMessage();
    }
});

Route::get('/', function () {
    return view('welcome');
});
