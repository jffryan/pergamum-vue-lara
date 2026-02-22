<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

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

// Public auth routes
Route::post('/login', [UserController::class, 'login']);
Route::post('/register', [UserController::class, 'register']);

// Protected auth routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [UserController::class, 'logout']);
});

Route::get('/{vue_capture?}', function() {
    return view('home');
})->where('vue_capture', '[\/\w\.-]*');
