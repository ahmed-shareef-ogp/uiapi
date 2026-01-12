<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GenericApiService
{
    public function __construct(public ComponentDataProvider $provider)
    {
        $this->provider->setIncludeHiddenColumnsInHeaders(false);
    }

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

    // View and component config logic is delegated to ComponentDataProvider

    protected function normalizeColumnsSubset(Model $model, ?string $columns, array $columnsSchema): array
    {
        return $this->provider->normalizeColumnsSubset($model, $columns, $columnsSchema);
    }

    // Filtering and sorting parsing moved to ComponentDataProvider

    protected function parseWithRelations(string $fqcn, Model $model, ?string $with): array
    {
        return $fqcn::parseWithRelations($model, $with);
    }

    protected function boolQuery(Request $req, string $key, bool $default = true): bool
    {
        $val = $req->query($key);
        if ($val === null) {
            return $default;
        }

        return filter_var($val, FILTER_VALIDATE_BOOL);
    }

    public function index(Request $request, string $modelName)
    {
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }

        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];
        $searchable = $schema['searchable'] ?? [];

        try {
            $ctx = $this->provider->computeComponentQueryInputs(
                    $modelInstance,
                    $modelName,
                    $columnsSchema,
                    $request->query('component'),
                    $request->query('columns'),
                    $request->query('filter'),
                    $request->query('sort')
                );

            $compBlock = $ctx['compBlock'];
            $columnsParam = $ctx['columnsParam'];
            $columnsSubsetNormalized = $ctx['columnsSubsetNormalized'];
            $relationsFromColumns = $ctx['relationsFromColumns'];
            $filters = $ctx['filters'];
            $sortTokens = $ctx['sortTokens'];
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock = $ctx['compBlock'];
        $columnsParam = $ctx['columnsParam'];
        $columnsSubsetNormalized = $ctx['columnsSubsetNormalized'];
        $relationsFromColumns = $ctx['relationsFromColumns'];
        $filters = $ctx['filters'];
        $sortTokens = $ctx['sortTokens'];

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (! empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (! in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        $lang = (string) ($request->query('lang') ?? 'dv');

        if (! $this->provider->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data' => [],
            ]);
        }

        $query = $fqcn::query();
        $fqcn::applyApiFilters($query, $filters, $columnsSchema);
        $q = $request->query('q');
        $fqcn::applyApiSearch($query, $q, $searchable);
        if (! empty($relations)) {
            $query->with($relations);
        }
        $fqcn::applyApiSorts($query, $sortTokens, $columnsSchema);

        $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));
        $page = (int) ($request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $effectiveTokens = $this->provider->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);
        $records = [];
        foreach ($paginator->items() as $item) {
            $rec = $item->toApiRecord($effectiveTokens, $includeMeta);
            $records[] = $this->provider->formatRecord($rec, $effectiveTokens);
        }

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

        $topLevelHeaders = null;
        if (method_exists($this->provider, 'getIncludeTopLevelHeaders') ? $this->provider->getIncludeTopLevelHeaders() : false) {
            $columnCustomizations = $this->provider->getColumnCustomizationsFromComponent($compBlock);
            $topLevelHeaders = $this->provider->buildTopLevelHeaders($modelInstance, $columnsSchema, $effectiveTokens, $lang, $columnCustomizations);
        }

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
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        }
        $response['component'] = $component;
        $response['componentSettings'] = $componentSettings;

        return response()->json($response);
    }

    public function options(Request $request, string $modelName, string $field)
    {
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];
        if (! array_key_exists($field, $columnsSchema)) {
            return response()->json(['error' => "Field '$field' is not defined in apiSchema"], 422);
        }

        $itemTitle = (string) ($request->query('itemTitle') ?? $field);
        $itemValue = (string) ($request->query('itemValue') ?? $field);
        $limit = (int) ($request->query('limit') ?? 50);
        $sort = in_array(strtolower((string) $request->query('sort')), ['asc', 'desc'], true) ? strtolower((string) $request->query('sort')) : 'asc';

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

    public function show(Request $request, string $modelName, $id)
    {
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];

        try {
            $resolvedComp = $this->provider->resolveViewComponent($modelName, $request->query('component'), $request->query('columns'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];

        $lang = (string) ($request->query('lang') ?? 'dv');
        if (! $this->provider->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data' => [],
            ]);
        }

        try {
            [$columnsSubsetNormalized, $relationsFromColumns] =
                $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (! empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (! in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        $query = $fqcn::query();
        if (! empty($relations)) {
            $query->with($relations);
        }
        $model = $query->find($id);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        $effectiveTokensShow = $this->provider->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record = $model->toApiRecord($effectiveTokensShow, $includeMeta);
        $record = $this->provider->formatRecord($record, $effectiveTokensShow);
        $meta = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta]);
    }

    public function store(Request $request, string $modelName)
    {
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $model, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];

        $payload = $request->json()->all() ?: $request->all();
        $allowedKeys = array_keys($columnsSchema);
        $data = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        foreach ($data as $key => $value) {
            $model->setAttribute($key, $value);
        }
        $model->save();

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $model, $with);
        if (! empty($relations)) {
            $model->load($relations);
        }

        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        try {
            $resolvedComp = $this->provider->resolveViewComponent($modelName, $request->query('component'), $request->query('columns'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];
        try {
            [$columnsSubsetNormalized] = $this->normalizeColumnsSubset($model, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $effectiveTokensStore = $this->provider->filterTokensByLangSupport($model, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record = $model->toApiRecord($effectiveTokensStore, $includeMeta);
        $record = $this->provider->formatRecord($record, $effectiveTokensStore);
        $meta = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta], 201);
    }

    public function update(Request $request, string $modelName, $id)
    {
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];

        $model = $fqcn::query()->find($id);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $payload = $request->json()->all() ?: $request->all();
        $allowedKeys = array_keys($columnsSchema);
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $model->setAttribute($key, $payload[$key]);
            }
        }
        $model->save();

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $model, $with);
        if (! empty($relations)) {
            $model->load($relations);
        }

        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        try {
            $resolvedComp = $this->provider->resolveViewComponent($modelName, $request->query('component'), $request->query('columns'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $compBlock = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];
        try {
            [$columnsSubsetNormalized] = $this->normalizeColumnsSubset($model, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $effectiveTokensUpdate = $this->provider->filterTokensByLangSupport($model, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record = $model->toApiRecord($effectiveTokensUpdate, $includeMeta);
        $record = $this->provider->formatRecord($record, $effectiveTokensUpdate);
        $meta = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta]);
    }

    public function destroy(Request $request, string $modelName, $id)
    {
        $resolved = $this->resolveModel($modelName);
        if (! $resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn] = $resolved;
        $model = $fqcn::query()->find($id);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $model->delete();

        return response()->noContent();
    }
}
