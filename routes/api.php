<?php

use Illuminate\Support\Facades\Route;
use Ogp\UiApi\Http\Controllers\GenericApiController;

Route::prefix(config('uiapi.prefix', 'api'))
    ->middleware(config('uiapi.middleware', ['api']))
    ->group(function () {
        Route::get('{model}', [GenericApiController::class, 'index']);
        Route::get('{model}/{id}', [GenericApiController::class, 'show']);
        Route::post('{model}', [GenericApiController::class, 'store']);
        Route::put('{model}/{id}', [GenericApiController::class, 'update']);
        Route::delete('{model}/{id}', [GenericApiController::class, 'destroy']);
    });
