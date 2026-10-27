<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ReviewController;

Route::get('/', [ShowController::class, 'home']);

Route::get('/search', [ShowController::class, 'search']);

Route::get('/genre', [ShowController::class, 'genre']);

Route::get('/platform', [ShowController::class, 'platform']);

Route::get('/keyword', [ShowController::class, 'keyword']);

Route::get('/company', [ShowController::class, 'company']);

Route::get('/person', [ShowController::class, 'person']);

Route::get('/top-rated', [ShowController::class, 'topRated']);

Route::get('/category/{slug}', [ShowController::class, 'category']);

Route::get('/detail/{id}', [ShowController::class, 'detail']);

Route::get('/api/reviews/{tmdbId}', [ReviewController::class, 'show'])->whereNumber('tmdbId');