<?php

namespace App\Http\Controllers;

use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

class GenericApiController extends Controller
{
    /**
     * Display a listing of the model's resources.
     *
     * @param  string  $model
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $model)
    {

        $requestParams = array_change_key_case($request->all(), CASE_LOWER);

        $modelClass = 'App\\Models\\'.ucfirst(strtolower($model));
        if (! class_exists($modelClass)) {
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

        // If paginated, reshape payload to include desired pagination fields
        if (($requestParams['pagination'] ?? null) !== 'off' && $records instanceof Paginator) {

            // Compute total and last_page independently to avoid LengthAware paginator
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
                    // 'next_page_url' => $records->nextPageUrl(),
                    // 'prev_page_url' => $records->previousPageUrl(),
                ],
            ]);
        }

        // When wrap=data is requested, always wrap non-paginated results
        return response()->json(['data' => $records]);

    }

    /**
     * Display the specified model resource.
     *
     * @param  string  $model
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $model, $id)
    {
        $requestParams = array_change_key_case($request->all(), CASE_LOWER);

        $modelClass = 'App\\Models\\'.ucfirst(strtolower($model));
        if (! class_exists($modelClass)) {
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
        $modelClass = 'App\\Models\\'.ucfirst($model);

        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $modelClass::validate($request);

        $record = $modelClass::createFromRequest($request, $request->user() ?: null);

        // Need to confirm if fileupload works
        app(FileUploadService::class)->handle(
            $request,
            $record,
            $model,
            $request->user() ?? null
        );

        return response()->json($record, 201);
    }

    /**
     * Update the specified model resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, string $model, int $id)
    {
        $modelClass = 'App\\Models\\'.ucfirst($model);

        if (! class_exists($modelClass)) {
            return response()->json(['error' => 'Model not found'], 400);
        }

        $record = $modelClass::findOrFail($id);

        // Validate
        $modelClass::validate($request, $record);

        // Update
        $record = $record->updateFromRequest($request, $request->user());

        return response()->json($record, 200);
    }

    /**
     * Remove the specified model resource.
     *
     * @param  string  $model
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($model, $id)
    {
        $modelClass = 'App\\Models\\'.ucfirst($model);
        if (! class_exists($modelClass)) {
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
