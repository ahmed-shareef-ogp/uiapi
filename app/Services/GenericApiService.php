<?php
namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GenericApiService
{
    /**
     * Construct the service with a ComponentDataProvider.
     * Ensures headers do not include hidden columns by default.
     *
     * @param  ComponentDataProvider  $provider  Helper for component/view-specific logic
     */
    public function __construct(public ComponentDataProvider $provider)
    {
        $this->provider->setIncludeHiddenColumnsInHeaders(false);
    }

    /**
     * Resolve a model by name and validate it exposes an apiSchema.
     * Returns an array of [FQCN, instance, schema] or null when invalid.
     *
     * @param  string  $modelName  Base model name (e.g., 'Country')
     * @return array|null
     */
    protected function resolveModel(string $modelName): ?array
    {
        $fqcn = 'App\\Models\\' . $modelName;
        if (! class_exists($fqcn)) {
            return null;
        }
        $instance = new $fqcn;
        if (! method_exists($instance, 'apiSchema')) {
            return null;
        }

        return [$fqcn, $instance, $instance->apiSchema()];
    }

    // View and component config logic is delegated to ComponentDataProvider

    /**
     * Normalize a subset of columns from the provided schema and capture any relations implied by tokens.
     *
     * @param  Model        $model          Model instance
     * @param  string|null  $columns        Comma-separated tokens or null
     * @param  array        $columnsSchema  Model apiSchema()['columns'] array
     * @return array                       [normalizedTokens, relationsFromTokens]
     */
    protected function normalizeColumnsSubset(Model $model, ?string $columns, array $columnsSchema): array
    {
        return $this->provider->normalizeColumnsSubset($model, $columns, $columnsSchema);
    }

    // Filtering and sorting parsing moved to ComponentDataProvider

    /**
     * Parse eager-load relations from a `with` query param using model helpers.
     *
     * @param  string  $fqcn   Model FQCN
     * @param  Model   $model  Model instance
     * @param  string|null $with Comma-separated relations or null
     * @return array
     */
    protected function parseWithRelations(string $fqcn, Model $model, ?string $with): array
    {
        return $fqcn::parseWithRelations($model, $with);
    }

    /**
     * Read a boolean query parameter with a default, using FILTER_VALIDATE_BOOL.
     *
     * @param  Request  $req
     * @param  string   $key
     * @param  bool     $default
     * @return bool
     */
    protected function boolQuery(Request $req, string $key, bool $default = true): bool
    {
        $val = $req->query($key);
        if ($val === null) {
            return $default;
        }

        return filter_var($val, FILTER_VALIDATE_BOOL);
    }

    /**
     * List records for a model with component-aware filters, search, sort, relations, and pagination.
     * Returns JSON shaped for UI components, including headers/filters/pagination when enabled.
     *
     * @param  Request  $request
     * @param  string   $modelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, string $modelName)
    {
        // Resolve the model and validate apiSchema.
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }

        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema                   = $schema['columns'] ?? [];
        $searchable                      = $schema['searchable'] ?? [];

        try {
            // Compute component-centric context (selected component, columns, filters, sorts).
            $ctx = $this->provider->computeComponentQueryInputs(
                $modelInstance,
                $modelName,
                $columnsSchema,
                $request->query('component'),
                $request->query('columns'),
                $request->query('filter'),
                $request->query('sort')
            );

            // Unpack computed context fields.
            $compBlock               = $ctx['compBlock'];
            $columnsParam            = $ctx['columnsParam'];
            $columnsSubsetNormalized = $ctx['columnsSubsetNormalized'];
            $relationsFromColumns    = $ctx['relationsFromColumns'];
            $filters                 = $ctx['filters'];
            $sortTokens              = $ctx['sortTokens'];

        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Derive eager relations from `with` plus any relations implied by selected columns.
        $with      = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (! empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (! in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        // Response flags and language handling.
        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        $lang        = (string) ($request->query('lang') ?? 'dv');

        // Verify the selected language is supported by the component configuration.
        if (! $this->provider->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data'    => [],
            ]);
        }

        // Build base query and apply component-aware filters, search, relations, and sorts.
        $query = $fqcn::query();
        $fqcn::applyApiFilters($query, $filters, $columnsSchema);
        $q = $request->query('q');
        $fqcn::applyApiSearch($query, $q, $searchable);
        if (! empty($relations)) {
            $query->with($relations);
        }
        $fqcn::applyApiSorts($query, $sortTokens, $columnsSchema);

        // Paginate according to component defaults or explicit query parameters.
        $perPage   = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));
        $page      = (int) ($request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Shape records using language-aware tokens and optional meta.
        $effectiveTokens = $this->provider->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);
        $records         = [];

        foreach ($paginator->items() as $item) {
            $rec = $item->toApiRecord($effectiveTokens, $includeMeta);
            $records[] = $this->provider->formatRecord($rec, $effectiveTokens);
        }

        // Component settings and customizations for frontend rendering.
        $component            = (string) ($ctx['componentKey'] ?? '');
        $componentSettingsKey = (string) ($request->query('componentSettings') ?? 'table');
        $columnCustomizations = $this->provider->getColumnCustomizationsFromComponent($compBlock);
        $allowedFilters       = $this->provider->getAllowedFiltersFromComponent($compBlock);
        $componentSettings    = $this->provider->buildComponentSettings(
            $componentSettingsKey,
            $columnsSchema,
            $effectiveTokens,
            $lang,
            $paginator,
            $modelName,
            $modelInstance,
            $columnCustomizations,
            $allowedFilters
        );

        // Optional: top-level headers based on component configuration.
        $topLevelHeaders = null;
        if (method_exists($this->provider, 'getIncludeTopLevelHeaders') ? $this->provider->getIncludeTopLevelHeaders() : false) {
            $columnCustomizations = $this->provider->getColumnCustomizationsFromComponent($compBlock);
            $topLevelHeaders      = $this->provider->buildTopLevelHeaders($modelInstance, $columnsSchema, $effectiveTokens, $lang, $columnCustomizations);
        }

        // Optional: top-level filters including current applied filters/search.
        $topLevelFilters = null;
        if (method_exists($this->provider, 'getIncludeTopLevelFilters') ? $this->provider->getIncludeTopLevelFilters() : false) {
            $allowedFilters  = $this->provider->getAllowedFiltersFromComponent($compBlock);
            $topLevelFilters = $this->provider->buildTopLevelFilters(
                $fqcn,
                $modelInstance,
                $columnsSchema,
                $columnsSubsetNormalized,
                $filters,
                $q,
                $searchable,
                $lang,
                $allowedFilters
            );
        }

        // Assemble final response payload.
        $response = [
            'data' => $records,
        ];

        if ($topLevelHeaders !== null) {
            $response['headers'] = $topLevelHeaders;
        }

        if ($topLevelFilters !== null) {
            $response['filters'] = $topLevelFilters;
        }

        if (method_exists($this->provider, 'getIncludeTopLevelPagination') ? $this->provider->getIncludeTopLevelPagination() : false) {
            $response['pagination'] = [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ];
        }
        $response['component']         = $component;
        $response['componentSettings'] = $componentSettings;

        return response()->json($response);
    }

    /**
     * Provide select options for a given model field. Keys in each item match the requested
     * `itemTitle` and `itemValue` field names; the response is wrapped in a `data` array.
     *
     * @param  Request  $request
     * @param  string   $modelName
     * @param  string   $field
     * @return \Illuminate\Http\JsonResponse
     */
    public function options(Request $request, string $modelName, string $field)
    {
        // Resolve the model and validate apiSchema.
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema                   = $schema['columns'] ?? [];
        if (! array_key_exists($field, $columnsSchema)) {
            return response()->json(['error' => "Field '$field' is not defined in apiSchema"], 422);
        }

        // Determine title/value fields, limit, and sort direction.
        $itemTitle = (string) ($request->query('itemTitle') ?? $field);
        $itemValue = (string) ($request->query('itemValue') ?? $field);
        $limit     = (int) ($request->query('limit') ?? 50);
        $sort      = in_array(strtolower((string) $request->query('sort')), ['asc', 'desc'], true) ? strtolower((string) $request->query('sort')) : 'asc';

        // Build a distinct selection using requested fields and map to desired keys.
        $query = $fqcn::query();
        if ($itemTitle === $itemValue) {
            $query->select($itemValue)->distinct()->orderBy($itemValue, $sort)->limit($limit);
            $rows = $query->get()->map(fn($r) => [
                $itemValue => $r->{$itemValue},
            ])->values()->all();
        } else {
            $query->select([$itemValue, $itemTitle])->distinct()->orderBy($itemTitle, $sort)->limit($limit);
            $rows = $query->get()->map(fn($r) => [
                $itemTitle => $r->{$itemTitle},
                $itemValue => $r->{$itemValue},
            ])->values()->all();
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Show a single record by id with optional relations and component-aware token shaping.
     *
     * @param  Request  $request
     * @param  string   $modelName
     * @param  mixed    $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, string $modelName, $id)
    {
        // Resolve the model and validate apiSchema.
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema                   = $schema['columns'] ?? [];

        try {
            // Determine component and selected columns.
            $resolvedComp = $this->provider->resolveViewComponent($modelName, $request->query('component'), $request->query('columns'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock    = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];

        // Ensure language is permitted by component config.
        $lang = (string) ($request->query('lang') ?? 'dv');
        if (! $this->provider->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data'    => [],
            ]);
        }

        try {
            // Normalize selected tokens and collect implied relations.
            [$columnsSubsetNormalized, $relationsFromColumns] =
            $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Build relations set combining `with` query param and column-implied relations.
        $with      = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (! empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (! in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        // Query with relations and attempt to find the record.
        $query = $fqcn::query();
        if (! empty($relations)) {
            $query->with($relations);
        }
        $model = $query->find($id);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }
        // Build record using effective tokens and optional meta.
        $includeMeta         = $this->boolQuery($request, 'include_meta', true);
        $effectiveTokensShow = $this->provider->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record              = $model->toApiRecord($effectiveTokensShow, $includeMeta);
        $record              = $this->provider->formatRecord($record, $effectiveTokensShow);
        $meta                = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta]);
    }

    /**
     * Create a new record using payload keys allowed by schema and return the shaped record.
     *
     * @param  Request  $request
     * @param  string   $modelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, string $modelName)
    {
        // Resolve the model and validate apiSchema.
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $model, $schema] = $resolved;
        $columnsSchema           = $schema['columns'] ?? [];

        // Extract JSON body or form data and whitelist keys using schema columns.
        $payload     = $request->json()->all() ?: $request->all();
        $allowedKeys = array_keys($columnsSchema);
        $data        = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        // Set attributes and persist the model.
        foreach ($data as $key => $value) {
            $model->setAttribute($key, $value);
        }
        $model->save();

        // Optionally eager-load requested relations.
        $with      = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $model, $with);
        if (! empty($relations)) {
            $model->load($relations);
        }

        // Resolve component and columns to shape the response record.
        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        try {
            $resolvedComp = $this->provider->resolveViewComponent($modelName, $request->query('component'), $request->query('columns'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock    = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];
        try {
            [$columnsSubsetNormalized] = $this->normalizeColumnsSubset($model, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Create language-aware record payload and optional meta.
        $effectiveTokensStore = $this->provider->filterTokensByLangSupport($model, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record               = $model->toApiRecord($effectiveTokensStore, $includeMeta);
        $record               = $this->provider->formatRecord($record, $effectiveTokensStore);
        $meta                 = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta], 201);
    }

    /**
     * Update a record by id using payload keys allowed by schema and return the shaped record.
     *
     * @param  Request  $request
     * @param  string   $modelName
     * @param  mixed    $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, string $modelName, $id)
    {
        // Resolve the model and validate apiSchema.
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema                   = $schema['columns'] ?? [];

        // Locate the record and validate existence.
        $model = $fqcn::query()->find($id);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Extract JSON body or form data and whitelist keys using schema columns.
        $payload     = $request->json()->all() ?: $request->all();
        $allowedKeys = array_keys($columnsSchema);
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $model->setAttribute($key, $payload[$key]);
            }
        }
        $model->save();

        // Optionally eager-load requested relations.
        $with      = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $model, $with);
        if (! empty($relations)) {
            $model->load($relations);
        }

        // Resolve component and columns to shape the response record.
        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        try {
            $resolvedComp = $this->provider->resolveViewComponent($modelName, $request->query('component'), $request->query('columns'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock    = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];
        try {
            [$columnsSubsetNormalized] = $this->normalizeColumnsSubset($model, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Create language-aware record payload and optional meta.
        $effectiveTokensUpdate = $this->provider->filterTokensByLangSupport($model, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record                = $model->toApiRecord($effectiveTokensUpdate, $includeMeta);
        $record                = $this->provider->formatRecord($record, $effectiveTokensUpdate);
        $meta                  = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta]);
    }

    /**
     * Delete a record by id.
     *
     * @param  Request  $request
     * @param  string   $modelName
     * @param  mixed    $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, string $modelName, $id)
    {
        // Resolve the model and validate apiSchema.
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn] = $resolved;
        // Attempt to find the record; return 404 if not found.
        $model = $fqcn::query()->find($id);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }
        // Perform deletion and return an empty 204 response.
        $model->delete();

        return response()->noContent();
    }
}
