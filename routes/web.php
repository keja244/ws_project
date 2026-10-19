<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShowController;

Route::get('/', [ShowController::class, 'home']);

Route::get('/search', [ShowController::class, 'search']);

Route::get('/genre', [ShowController::class, 'genre']);

Route::get('/top-rated', [ShowController::class, 'topRated']);

Route::get('/category/{slug}', [ShowController::class, 'category']);

Route::get('/detail/{id}', [ShowController::class, 'detail']);