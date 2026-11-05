<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ReviewController;

Route::get('/', [ShowController::class, 'home']);

Route::get('/search', [ShowController::class, 'search'])->middleware('throttle:60,1');

Route::get('/api/search', [ShowController::class, 'searchApi'])->middleware('throttle:60,1');

Route::get('/genre/{slug?}', [ShowController::class, 'genre'])->where('slug', '[a-z0-9-]+');

Route::get('/api/genre/{slug}', [ShowController::class, 'genreShows'])->where('slug', '[a-z0-9-]+');

Route::get('/platform/{slug?}', [ShowController::class, 'platform'])->where('slug', '[a-z0-9-]+');

Route::get('/api/platform/{slug}', [ShowController::class, 'platformShows'])->where('slug', '[a-z0-9-]+');

Route::get('/keyword/{slug?}', [ShowController::class, 'keyword']);

Route::get('/company/{slug}', [ShowController::class, 'company'])->where('slug', '[a-z0-9-]+');

Route::get('/person/{slug}', [ShowController::class, 'person'])->where('slug', '[a-z0-9-]+');

Route::get('/top-rated', [ShowController::class, 'topRated']);

Route::get('/country/{slug}', [ShowController::class, 'country']);

Route::get('/language/{slug}', [ShowController::class, 'language']);

Route::get('/category/{slug}', [ShowController::class, 'category'])->where('slug', '[a-z-]+');

Route::get('/detail/{id}', [ShowController::class, 'detail'])->where('id', 'tt[0-9]+');

Route::get('/api/reviews/{tmdbId}', [ReviewController::class, 'show'])->whereNumber('tmdbId');

Route::post('/api/chat', [ShowController::class, 'chat'])->middleware('throttle:12,1');

Route::post('/api/chat/feedback', [ShowController::class, 'chatFeedback'])->middleware('throttle:30,1');

Route::get('/api/search', [ShowController::class, 'searchApi']);

Route::get('/api/category/{slug}', [ShowController::class, 'categoryShows']);