<?php

namespace Ogp\UiApi\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

class GenericApiController
{
    public function index(Request $request, $model)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);

        $modelClassApp = 'App\\Models\\'.ucfirst(strtolower($model));
        $modelClassPkg = 'Ogp\\UiApi\\Models\\'.ucfirst(strtolower($model));
        $modelClass = class_exists($modelClassApp) ? $modelClassApp : (class_exists($modelClassPkg) ? $modelClassPkg : null);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $query = $modelClass::query()
            ->selectColumns($requestParams['columns'] ?? null)
            ->search($requestParams['search'] ?? null)
            ->searchAll($requestParams['searchall'] ?? null)
            ->withoutRow($requestParams['withoutrow'] ?? null)
            ->filter($requestParams['filter'] ?? null)
            ->withRelations($requestParams['with'] ?? null)
            ->withPivotRelations($requestParams['pivot'] ?? null)
            ->sort($requestParams['sort'] ?? null);

        $perPage = isset($requestParams['per_page']) ? (int) $requestParams['per_page'] : null;
        $page = isset($requestParams['page']) ? (int) $requestParams['page'] : null;
        $records = $modelClass::paginateFromRequest(
            $query,
            $requestParams['pagination'] ?? null,
            $perPage,
            $page
        );

        if (($requestParams['pagination'] ?? null) !== 'off' && $records instanceof Paginator) {
            try {
                $total = (int) (clone $query)->toBase()->getCountForPagination();
            } catch (\Throwable $e) {
                $total = (int) (clone $query)->count();
            }

            $effectivePerPage = $perPage ?? $records->perPage();
            $lastPage = (int) max(1, (int) ceil(($total > 0 ? $total : 1) / max(1, (int) $effectivePerPage)));

            return response()->json([
                'data' => $records->items(),
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'first_page' => 1,
                    'last_page' => $lastPage,
                    'per_page' => $effectivePerPage,
                    'total' => $total,
                ],
            ]);
        }

        return response()->json(['data' => $records]);
    }

    public function show(Request $request, $model, $id)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);

        $modelClassApp = 'App\\Models\\'.ucfirst(strtolower($model));
        $modelClassPkg = 'Ogp\\UiApi\\Models\\'.ucfirst(strtolower($model));
        $modelClass = class_exists($modelClassApp) ? $modelClassApp : (class_exists($modelClassPkg) ? $modelClassPkg : null);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        try {
            $query = $modelClass::query()
                ->selectColumns($requestParams['columns'] ?? null)
                ->withRelations($requestParams['with'] ?? null)
                ->withPivotRelations($requestParams['pivot'] ?? null);

            $record = $query->findOrFail($id);

            return response()->json($record);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Record not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, string $model)
    {
        $modelClassApp = 'App\\Models\\'.ucfirst($model);
        $modelClassPkg = 'Ogp\\UiApi\\Models\\'.ucfirst($model);
        $modelClass = class_exists($modelClassApp) ? $modelClassApp : (class_exists($modelClassPkg) ? $modelClassPkg : null);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $modelClass::validate($request);

        $record = $modelClass::createFromRequest($request, $request->user() ?: null);

        if (class_exists('App\\Services\\FileUploadService')) {
            app('App\\Services\\FileUploadService')::handle($request, $record, $model, $request->user() ?? null);
        }

        return response()->json($record, 201);
    }

    public function update(Request $request, string $model, int $id)
    {
        $modelClassApp = 'App\\Models\\'.ucfirst($model);
        $modelClassPkg = 'Ogp\\UiApi\\Models\\'.ucfirst($model);
        $modelClass = class_exists($modelClassApp) ? $modelClassApp : (class_exists($modelClassPkg) ? $modelClassPkg : null);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $record = $modelClass::findOrFail($id);

        $modelClass::validate($request, $record);

        $record = $record->updateFromRequest($request, $request->user());

        return response()->json($record, 200);
    }

    public function destroy($model, $id)
    {
        $modelClassApp = 'App\\Models\\'.ucfirst($model);
        $modelClassPkg = 'Ogp\\UiApi\\Models\\'.ucfirst($model);
        $modelClass = class_exists($modelClassApp) ? $modelClassApp : (class_exists($modelClassPkg) ? $modelClassPkg : null);

        if (! $modelClass) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        try {
            $record = $modelClass::findOrFail($id);
            $record->delete();

            return response()->json(['message' => 'Record deleted successfully'], 204);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Record not found'], 404);
        }
    }
}
