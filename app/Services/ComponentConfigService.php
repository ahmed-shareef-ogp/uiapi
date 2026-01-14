<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ComponentConfigService
{
    /**
     * Construct the service with a ComponentConfigProvider.
     * Ensures headers do not include hidden columns by default.
     *
     * @param  ComponentConfigProvider  $provider  Helper for component/view-specific logic
     */
    public function __construct(public ComponentConfigProvider $provider)
    {
        $this->provider->setIncludeHiddenColumnsInHeaders(false);
    }

    /**
     * Resolve a model by name and validate it exposes an apiSchema.
     * Returns an array of [FQCN, instance, schema] or null when invalid.
     *
     * @param  string  $modelName  Base model name (e.g., 'Country')
     */
    protected function resolveModel(string $modelName): ?array
    {
        $fqcn = 'App\\Models\\'.$modelName;
        if (! class_exists($fqcn)) {
            return null;
        }
        $instance = new $fqcn;
        if (! method_exists($instance, 'apiSchema')) {
            return null;
        }

        return [$fqcn, $instance, $instance->apiSchema()];
    }

    // View and component config logic is delegated to ComponentConfigProvider

    /**
     * Normalize a subset of columns from the provided schema and capture any relations implied by tokens.
     *
     * @param  Model  $model  Model instance
     * @param  string|null  $columns  Comma-separated tokens or null
     * @param  array  $columnsSchema  Model apiSchema()['columns'] array
     * @return array [normalizedTokens, relationsFromTokens]
     */
    protected function normalizeColumnsSubset(Model $model, ?string $columns, array $columnsSchema): array
    {
        return $this->provider->normalizeColumnsSubset($model, $columns, $columnsSchema);
    }

    // Filtering and sorting parsing moved to ComponentConfigProvider

    /**
     * Parse eager-load relations from a `with` query param using model helpers.
     *
     * @param  string  $fqcn  Model FQCN
     * @param  Model  $model  Model instance
     * @param  string|null  $with  Comma-separated relations or null
     */
    protected function parseWithRelations(string $fqcn, Model $model, ?string $with): array
    {
        return $fqcn::parseWithRelations($model, $with);
    }

    /**
     * Read a boolean query parameter with a default, using FILTER_VALIDATE_BOOL.
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
        $columnsSchema = $schema['columns'] ?? [];
        $searchable = $schema['searchable'] ?? [];

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
            $compBlock = $ctx['compBlock'];
            $columnsParam = $ctx['columnsParam'];
            $columnsSubsetNormalized = $ctx['columnsSubsetNormalized'];
            $relationsFromColumns = $ctx['relationsFromColumns'];
            $filters = $ctx['filters'];
            $sortTokens = $ctx['sortTokens'];

        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Derive eager relations from `with` plus any relations implied by selected columns.
        $with = $request->query('with');
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
        $lang = (string) ($request->query('lang') ?? 'dv');

        // Verify the selected language is supported by the component configuration.
        if (! $this->provider->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data' => [],
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
        $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));
        $page = (int) ($request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Shape records using language-aware tokens and optional meta.
        $effectiveTokens = $this->provider->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);
        $records = [];

        // foreach ($paginator->items() as $item) {
        //     $rec = $item->toApiRecord($effectiveTokens, $includeMeta);
        //     $records[] = $this->provider->formatRecord($rec, $effectiveTokens);
        // }

        // Component settings and customizations for frontend rendering.
        $component = (string) ($ctx['componentKey'] ?? '');
        $componentSettingsKey = (string) ($request->query('componentSettings') ?? 'table');
        $columnCustomizations = $this->provider->getColumnCustomizationsFromComponent($compBlock);
        $allowedFilters = $this->provider->getAllowedFiltersFromComponent($compBlock);
        $componentSettings = $this->provider->buildComponentSettings(
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
            $topLevelHeaders = $this->provider->buildTopLevelHeaders($modelInstance, $columnsSchema, $effectiveTokens, $lang, $columnCustomizations);
        }

        // Optional: top-level filters including current applied filters/search.
        $topLevelFilters = null;
        if (method_exists($this->provider, 'getIncludeTopLevelFilters') ? $this->provider->getIncludeTopLevelFilters() : false) {
            $allowedFilters = $this->provider->getAllowedFiltersFromComponent($compBlock);
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
        // $response = [
        //     'data' => $records,
        // ];

        if ($topLevelHeaders !== null) {
            $response['headers'] = $topLevelHeaders;
        }

        if ($topLevelFilters !== null) {
            $response['filters'] = $topLevelFilters;
        }

        if (method_exists($this->provider, 'getIncludeTopLevelPagination') ? $this->provider->getIncludeTopLevelPagination() : false) {
            $response['pagination'] = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        }
        $response['component'] = $component;
        $response['componentSettings'] = $componentSettings;

        return response()->json($response);
    }

    /**
     * Provide select options for a given model field. Keys in each item match the requested
     * `itemTitle` and `itemValue` field names; the response is wrapped in a `data` array.
     *
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
        $columnsSchema = $schema['columns'] ?? [];
        if (! array_key_exists($field, $columnsSchema)) {
            return response()->json(['error' => "Field '$field' is not defined in apiSchema"], 422);
        }

        // Determine title/value fields, limit, and sort direction.
        $itemTitle = (string) ($request->query('itemTitle') ?? $field);
        $itemValue = (string) ($request->query('itemValue') ?? $field);
        $limit = (int) ($request->query('limit') ?? 50);
        $sort = in_array(strtolower((string) $request->query('sort')), ['asc', 'desc'], true) ? strtolower((string) $request->query('sort')) : 'asc';

        // Build a distinct selection using requested fields and map to desired keys.
        $query = $fqcn::query();
        if ($itemTitle === $itemValue) {
            $query->select($itemValue)->distinct()->orderBy($itemValue, $sort)->limit($limit);
            $rows = $query->get()->map(fn ($r) => [
                $itemValue => $r->{$itemValue},
            ])->values()->all();
        } else {
            $query->select([$itemValue, $itemTitle])->distinct()->orderBy($itemTitle, $sort)->limit($limit);
            $rows = $query->get()->map(fn ($r) => [
                $itemTitle => $r->{$itemTitle},
                $itemValue => $r->{$itemValue},
            ])->values()->all();
        }

        return response()->json(['data' => $rows]);
    }
}
