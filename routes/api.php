<?php

use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BulkUploadController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FormatController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\ListController;
use App\Http\Controllers\ListItemController;
use App\Http\Controllers\NewBookController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\VersionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::resource('/books', BookController::class);
    Route::resource('/genres', GenreController::class);

    Route::get('/completed/years', [BookController::class, 'getCompletedYears']);
    Route::get('/completed/{year}', [BookController::class, 'getBooksByYear']);
    Route::get('/book/{slug}', [BookController::class, 'getOneBookFromSlug']);
    Route::get('/author/{slug}', [AuthorController::class, 'getAuthorBySlug']);
    Route::post('/create-book/title', [NewBookController::class, 'createOrGetBookByTitle']);
    Route::post('/create-book', [NewBookController::class, 'completeBookCreation']);
    Route::post('/create-authors', [AuthorController::class, 'getOrSetToBeCreatedAuthorsByName']);
    Route::post('/add-read-instance', [BookController::class, 'addReadInstance']);
    Route::post('/versions', [VersionController::class, 'addNewVersion']);
    Route::patch('/versions/{version}/discard', [VersionController::class, 'discard']);
    Route::patch('/versions/{version}/restore', [VersionController::class, 'restore']);

    // Statistics — `scope` defaults to `user`, so /api/statistics still works.
    // Adding an author or genre surface is a ScopeResolver case, not a route.
    Route::get('/statistics/{scope?}/{scopeId?}', [StatisticsController::class, 'show']);

    Route::get('/config/formats', [ConfigController::class, 'getFormats']);
    Route::post('/formats', [FormatController::class, 'store']);

    Route::post('/bulk-upload', [BulkUploadController::class, 'upload']);
    // The inverse of bulk-upload, and deliberately its exact CSV shape — see
    // App\Support\CsvContract.
    Route::get('/export', [ExportController::class, 'download']);

    Route::resource('/lists', ListController::class);
    Route::patch('/lists/{list}/reorder', [ListController::class, 'reorder']);
    Route::post('/lists/{list}/items', [ListItemController::class, 'store']);
    Route::delete('/lists/{list}/items/{item}', [ListItemController::class, 'destroy']);
});
