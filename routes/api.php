<?php

use Illuminate\Http\Request;
use App\Services\GenericApiService;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\GenericApiController;

/**
 * Generic CRUD routes using model name in path.
 *
 * Pattern:
 * - GET /api/{model}            -> index
 * - GET /api/{model}/{id}       -> show
 * - POST /api/{model}           -> store
 * - PUT /api/{model}/{id}       -> update
 * - DELETE /api/{model}/{id}    -> destroy
 *
 * Notes:
 * - No controllers per requirements; closures call GenericApiService.
 * - Fields for create/update pulled from apiSchema.
 * - Pagination, filters, search, sorts mirror /api/data behavior.
 */
// Route::get('/{model}', function (Request $request, string $model) {
//     return app(GenericApiService::class)->index($request, $model);
// });

// Route::get('/{model}/{id}', function (Request $request, string $model, $id) {
//     return app(GenericApiService::class)->show($request, $model, $id);
// });

// Route::post('/{model}', function (Request $request, string $model) {
//     return app(GenericApiService::class)->store($request, $model);
// });

// Route::put('/{model}/{id}', function (Request $request, string $model, $id) {
//     return app(GenericApiService::class)->update($request, $model, $id);
// });

// Route::delete('/{model}/{id}', function (Request $request, string $model, $id) {
//     return app(GenericApiService::class)->destroy($request, $model, $id);
// });

// // Options endpoint for select filters (distinct values or lookup pairs)
// Route::get('/{model}/options/{field}', function (Request $request, string $model, string $field) {
//     return app(GenericApiService::class)->options($request, $model, $field);
// });


Route::get('/{model}', [GenericApiController::class, 'index']);         // will need to reflect the payload format as per
Route::get('/{model}/{id}', [GenericApiController::class, 'show']);
Route::post('/{model}', [GenericApiController::class, 'store']);
Route::put('/{model}/{id}', [GenericApiController::class, 'update']);
Route::delete('/{model}/{id}', [GenericApiController::class, 'destroy']);
