<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

Route::get('/detail/{id}', function ($id) {
    return view('detail'); 
});

Route::get('/top-rated', function () {
    return view('top-rated');
});

Route::get('/search', function () {
    return view('search'); 
});

Route::get('/genre', function () {
    return view('genre'); 
});