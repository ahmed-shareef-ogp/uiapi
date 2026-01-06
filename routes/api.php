<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Model;
use App\Services\GenericApiService;

/**
 * Generic internal API endpoint: GET /api/data
 *
 * - Resolves models dynamically from ?model=ModelName
 * - Validates filters/sort against the model's apiSchema()
 * - Delegates JSON shaping to the model via ApiQueryable trait
 * - Supports eager-loaded nested relations via ?with=rel,rel.nested
 * - Pagination via ?page & ?per_page
 * - Global search via ?q across schema['searchable']
 * - Column metadata included by default, toggle via ?include_meta=false
 *
 * Note: No controllers or resources used per requirements.
 */
Route::get('/sections', function () {
    return response()->json([
        'data' => [
            ['id' => 1, 'code' => 'PMS', 'name_en' => 'Prosecution Management Section', 'name' => 'ޕްރޮސިކިއުޝަން މެނޖްމަންޓް ސެކްޝަން'],
            ['id' => 2, 'code' => 'SCTB', 'name_en' => 'Section B', 'name' => 'ސެކްޝަން ބީ'],
            ['id' => 3, 'code' => 'SCTC', 'name_en' => 'Section C', 'name' => 'ސެކްޝަން ސީ']
            // ['id' => 1, 'code' => 'PMS', 'name' => ['en' => 'Prosecution Management Section', 'dv' => 'ޕްރޮސިކިއުޝަން މެނޖްމަންޓް ސެކްޝަން'] ],
            // ['id' => 2, 'code' => 'SCTB', 'name' => ['en' => 'Section B', 'dv' => 'ސެކްޝަން ބީ'] ],
            // ['id' => 3, 'code' => 'SCTC', 'name' => ['en' => 'Section C', 'dv' => 'ސެކްޝަން ސީ'] ],
        ],
    ]);
});

Route::get('/data', function (Request $request) {
    $input = $request->all();

    $validator = Validator::make($input, [
        'model' => ['required', 'string'],
        'columns' => ['sometimes', 'string'], // e.g. "id, ref_num, entry_type_id.name"
        'filter' => ['sometimes', 'string'], // e.g. "col:val,col2:*val*"
        'q' => ['sometimes', 'string'],
        'sort' => ['sometimes', 'string'],
        'page' => ['sometimes', 'integer', 'min:1'],
        'per_page' => ['sometimes', 'integer', 'min:1'],
        'with' => ['sometimes', 'string'], // comma-separated relation paths
        'include_meta' => ['sometimes', 'boolean'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'error' => 'Validation failed',
            'messages' => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();

    // Resolve model class; assume models live in App\Models
    $modelName = $data['model'];
    $fqcn = 'App\\Models\\' . $modelName;
    if (!class_exists($fqcn)) {
        return response()->json(['error' => "Model '$modelName' not found"], 422);
    }

    /** @var Model $modelInstance */
    $modelInstance = new $fqcn();

    if (!method_exists($modelInstance, 'apiSchema')) {
        return response()->json(['error' => "Model '$modelName' does not implement apiSchema()"], 422);
    }

    $schema = $modelInstance->apiSchema();
    $columnsSchema = $schema['columns'] ?? [];
    $searchable = $schema['searchable'] ?? [];

    // Normalize columns subset from comma-separated string; support nested relation columns via dot notation
    $columnsSubset = null;
    $columnsSubsetNormalized = null;
    $relationsFromColumns = [];
    if (!empty($data['columns'])) {
        $tokens = array_filter(array_map('trim', explode(',', $data['columns'])));
        $columnsSubset = $tokens;
        $columnsSubsetNormalized = [];

        foreach ($tokens as $token) {
            if (str_contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (!$rest) {
                    return response()->json(['error' => "Invalid columns segment '$token'"], 422);
                }

                // Resolve relation name from segment (supports relation method or foreign key like entry_type_id)
                $relationName = null;
                if (method_exists($modelInstance, $first)) {
                    try { $relTest = $modelInstance->{$first}(); } catch (\Throwable $e) { $relTest = null; }
                    if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relationName = $first;
                    }
                }
                if (!$relationName && str_ends_with($first, '_id')) {
                    $guess = \Illuminate\Support\Str::camel(substr($first, 0, -3));
                    if (method_exists($modelInstance, $guess)) {
                        try { $relTest = $modelInstance->{$guess}(); } catch (\Throwable $e) { $relTest = null; }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $guess;
                        }
                    }
                }
                if (!$relationName) {
                    return response()->json(['error' => "Unknown relation reference '$first' in columns"], 422);
                }

                // Validate column against related model schema
                $related = $modelInstance->{$relationName}()->getRelated();
                if (!method_exists($related, 'apiSchema')) {
                    return response()->json(['error' => "Related model for '$relationName' lacks apiSchema()"], 422);
                }
                $relSchema = $related->apiSchema();
                $relColumns = $relSchema['columns'] ?? [];
                if (!array_key_exists($rest, $relColumns)) {
                    return response()->json(['error' => "Column '$rest' is not defined in $relationName apiSchema"], 422);
                }

                $columnsSubsetNormalized[] = $relationName . '.' . $rest;
                $relationsFromColumns[] = $relationName;
            } else {
                // Validate top-level column against schema
                if (!array_key_exists($token, $columnsSchema)) {
                    return response()->json(['error' => "Column '$token' is not defined in apiSchema"], 422);
                }
                $columnsSubsetNormalized[] = $token;
            }
        }
    }

    // Parse filters from string format: col:val,col2:*val*,col3:val*
    $filters = [];
    if (!empty($data['filter'])) {
        $pairs = array_filter(array_map('trim', explode(',', $data['filter'])));
        foreach ($pairs as $pair) {
            [$field, $value] = array_pad(explode(':', $pair, 2), 2, null);
            if (!$field || $value === null) {
                return response()->json(['error' => "Invalid filter segment '$pair'"], 422);
            }
            if (!array_key_exists($field, $columnsSchema)) {
                return response()->json(['error' => "Filter field '$field' is not defined in apiSchema"], 422);
            }

            // Wildcard * indicates LIKE; translate * to % to allow contains or starts/ends-with
            if (str_contains($value, '*')) {
                $likePattern = str_replace('*', '%', $value);
                $filters[$field] = ['like' => $likePattern];
            } else {
                $filters[$field] = $value;
            }
        }
    }

    // Validate sort(s): comma-separated list e.g. "col1,-col2"
    $sortTokens = [];
    if (!empty($data['sort'])) {
        $sortTokens = array_filter(array_map('trim', explode(',', $data['sort'])));
        foreach ($sortTokens as $tok) {
            $field = ltrim($tok, '-');
            if (!array_key_exists($field, $columnsSchema)) {
                return response()->json(['error' => "Sort field '$field' is not defined in apiSchema"], 422);
            }
        }
    }

    // Parse relations (first segment validation only)
    $with = $data['with'] ?? null;
    $relations = $fqcn::parseWithRelations($modelInstance, $with);
    // Add relations implied by columns selection
    if (!empty($relationsFromColumns)) {
        foreach (array_unique($relationsFromColumns) as $rel) {
            if (!in_array($rel, $relations, true)) {
                $relations[] = $rel;
            }
        }
    }

    $includeMeta = array_key_exists('include_meta', $data) ? (bool) $data['include_meta'] : true;

    // Build query
    $query = $fqcn::query();

    // Apply filters
    $fqcn::applyApiFilters($query, $filters, $columnsSchema);

    // Apply global search
    $q = $data['q'] ?? null;
    $fqcn::applyApiSearch($query, $q, $searchable);

    // Eager load relations
    if (!empty($relations)) {
        $query->with($relations);
    }

    // Sorting (supports multiple)
    $fqcn::applyApiSorts($query, $sortTokens, $columnsSchema);

    // Pagination
    $perPage = (int) ($data['per_page'] ?? 25);
    $page = (int) ($data['page'] ?? 1);

    $paginator = $query->paginate($perPage, ['*'], 'page', $page);

    // Serialize records via model method
    $records = [];
    foreach ($paginator->items() as $item) {
        // toApiRecord is provided by ApiQueryable trait; pass normalized subset (supports nested)
        $records[] = $item->toApiRecord($columnsSubsetNormalized, $includeMeta);
    }

    // Build meta
    $meta = [
        'pagination' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ],
    ];
    if ($includeMeta) {
        $meta['columns'] = $columnsSchema;
    }

    return response()->json([
        'data' => $records,
        'meta' => $meta,
    ]);
});

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
Route::get('/{model}', function (Request $request, string $model) {
    return app(GenericApiService::class)->index($request, $model);
});

Route::get('/{model}/{id}', function (Request $request, string $model, $id) {
    return app(GenericApiService::class)->show($request, $model, $id);
});

Route::post('/{model}', function (Request $request, string $model) {
    return app(GenericApiService::class)->store($request, $model);
});

Route::put('/{model}/{id}', function (Request $request, string $model, $id) {
    return app(GenericApiService::class)->update($request, $model, $id);
});

Route::delete('/{model}/{id}', function (Request $request, string $model, $id) {
    return app(GenericApiService::class)->destroy($request, $model, $id);
});

// Options endpoint for select filters (distinct values or lookup pairs)
Route::get('/{model}/options/{field}', function (Request $request, string $model, string $field) {
    return app(GenericApiService::class)->options($request, $model, $field);
});
