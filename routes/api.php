<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\ConfigController;

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

Route::resource("/books", BookController::class);
Route::post("books/bulk", [BookController::class, "bulkCreate"]);

Route::get("/book/{slug}", [BookController::class, 'getOneBookFromSlug']);
Route::get("/author/{slug}", [AuthorController::class, 'getAuthorBySlug']);

Route::get("/config/formats", [ConfigController::class, "getFormats"]);