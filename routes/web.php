<?php

use App\Models\Entry;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/entry', function () {
    $entry = Entry::find(1); 
    return response()->json($entry);
});
