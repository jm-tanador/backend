<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\GoogleAuthController;
// use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/search', [VideoController::class, 'search']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);

Route::middleware('auth:sanctum')->group(function () {

    // IMPORTANT: It must be '/me', NOT '/api/me'
    Route::get('/me', function (Request $request) {
        return response()->json($request->user());
    });

    // Personalized feed based on subscriptions
    Route::get('/feed/personalized', [VideoController::class, 'personalizedFeed']);
});
// Route::get('/run-migrations', function () {
//     try {
//         Artisan::call('migrate', ['--force' => true]);
//         return response()->json([
//             'status' => 'success',
//             'output' => Artisan::output()
//         ]);
//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => 'error',
//             'message' => $e->getMessage()
//         ], 500);
//     }
// });