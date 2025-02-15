<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BacklogController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\NewBookController;
use App\Http\Controllers\VersionController;

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
Route::resource("/genres", GenreController::class);

Route::get("/completed/{year}", [BookController::class, "getBooksByYear"]);
Route::get("/book/{slug}", [BookController::class, 'getOneBookFromSlug']);
Route::get("/author/{slug}", [AuthorController::class, 'getAuthorBySlug']);
Route::get("/backlog", [BacklogController::class, "index"]);
Route::post("/backlog", [BacklogController::class, "store"]);
Route::delete("/backlog/{id}", [BacklogController::class, "destroy"]);
Route::post("/backlog/update-ordinals", [BacklogController::class, "updateOrdinals"]);
Route::post("/create-book/title", [NewBookController::class, "createOrGetBookByTitle"]);
Route::post("/create-book", [NewBookController::class, "completeBookCreation"]);
Route::post("/create-authors", [AuthorController::class, "getOrSetToBeCreatedAuthorsByName"]);
Route::post("/add-read-instance", [BookController::class, "addReadInstance"]);
Route::post("/versions", [VersionController::class, "addNewVersion"]);

// Statistics
Route::get("/statistics", [StatisticsController::class, "fetchUserStats"]);

Route::get("/config/formats", [ConfigController::class, "getFormats"]);