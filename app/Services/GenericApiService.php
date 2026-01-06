<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class GenericApiService
{
    protected function loadComponentConfig(string $componentSettingsKey): array
    {
        $path = base_path('app/Services/ComponentConfigs/' . $componentSettingsKey . '.json');
        if (!File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $cfg = json_decode($json, true) ?: [];
        return $cfg;
    }

    protected function labelFor(array $columnDef, string $field, string $lang): string
    {
        $label = $columnDef['label'] ?? null;
        if (is_array($label)) {
            if (array_key_exists($lang, $label)) {
                return (string) $label[$lang];
            }
            if (array_key_exists('en', $label)) {
                return (string) $label['en'];
            }
        } elseif (is_string($label) && $label !== '') {
            return $label;
        }
        return Str::title(str_replace('_', ' ', $field));
    }

    protected function keyFor(array $columnDef, string $field): string
    {
        return (string) ($columnDef['key'] ?? $field);
    }

    protected function buildHeaders(array $columnsSchema, string $lang): array
    {
        $headers = [];
        foreach ($columnsSchema as $field => $def) {
            $headers[] = [
                'title' => $this->labelFor($def, $field, $lang),
                'value' => $this->keyFor($def, $field),
                'sortable' => (bool) ($def['sortable'] ?? false),
                'hidden' => (bool) ($def['hidden'] ?? false),
            ];
        }
        return $headers;
    }

    protected function buildSearchbarSettings(array $tableCfg, string $modelName): array
    {
        $settings = $tableCfg['Searchbar']['settings'] ?? [];
        $settingsOut = [];
        if (array_key_exists('submitUrl', $settings) && is_string($settings['submitUrl'])) {
            $settingsOut['submitUrl'] = str_replace('{{model}}', $modelName, $settings['submitUrl']);
        }
        if (array_key_exists('method', $settings) && is_string($settings['method'])) {
            $settingsOut['method'] = $settings['method'];
        }
        return [
            'settings' => $settingsOut,
            'buttons' => $tableCfg['Searchbar']['buttons'] ?? [],
        ];
    }

    protected function buildFilters(array $columnsSchema, string $modelName, string $lang): array
    {
        $filters = [];
        foreach ($columnsSchema as $field => $def) {
            $f = $def['filterable'] ?? null;
            if (!$f || !is_array($f)) {
                continue;
            }
            $type = strtolower((string) ($f['type'] ?? 'search'));
            // Determine display label: prefer filter override, localized by lang
            $overrideLabel = $f['label'] ?? null;
            if (is_array($overrideLabel)) {
                $label = (string) ($overrideLabel[$lang] ?? $overrideLabel['en'] ?? $this->labelFor($def, $field, $lang));
            } elseif (is_string($overrideLabel) && $overrideLabel !== '') {
                $label = $overrideLabel;
            } else {
                $label = $this->labelFor($def, $field, $lang);
            }
            $key = (string) ($f['value'] ?? $this->keyFor($def, $field));
            $filter = [
                'type' => Str::title($type),
                'key' => $key,
                'label' => $label,
            ];
            if ($type === 'select') {
                $itemTitle = (string) ($f['itemTitle'] ?? $this->keyFor($def, $field));
                $itemValue = (string) ($f['itemValue'] ?? $this->keyFor($def, $field));
                $sourceModel = (string) ($f['sourceModel'] ?? $modelName);
                $filter['itemTitle'] = $itemTitle;
                $filter['itemValue'] = $itemValue;
                // When sourcing from another model, validate against its schema using a field it actually has.
                $paramField = ($sourceModel === $modelName) ? $field : $itemValue;
                $filter['url'] = url("/api/{$sourceModel}/options/{$paramField}") . "?itemTitle={$itemTitle}&itemValue={$itemValue}&lang={$lang}";
            }
            $filters[] = $filter;
        }
        return $filters;
    }

    protected function resolveRelatedModel(Model $model, string $relation): ?Model
    {
        if (!method_exists($model, $relation)) {
            return null;
        }
        try { $rel = $model->{$relation}(); } catch (\Throwable $e) { $rel = null; }
        if ($rel instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            return $rel->getRelated();
        }
        return null;
    }

    protected function pickDefaultTitleField(Model $related): string
    {
        $schema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
        $columns = $schema['columns'] ?? [];
        // Prefer common name fields
        foreach (['name', 'name_eng', 'title'] as $preferred) {
            if (array_key_exists($preferred, $columns) && ($columns[$preferred]['hidden'] ?? false) === false) {
                return $preferred;
            }
        }
        // First non-hidden string column
        foreach ($columns as $field => $def) {
            if (($def['hidden'] ?? false) === false && ($def['type'] ?? '') === 'string') {
                return $field;
            }
        }
        // Fallback to id
        return 'id';
    }

    protected function buildTopLevelFilters(
        string $fqcn,
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        array $appliedFilters,
        ?string $q,
        array $searchable,
        string $lang
    ): array {
        $filters = [];

        foreach ($columnsSchema as $field => $def) {
            $f = $def['filterable'] ?? null;
            if (!$f || !is_array($f)) {
                continue;
            }

            // Respect columns selection: include only selected columns when subset provided
            if (is_array($columnsSubsetNormalized) && !in_array($field, $columnsSubsetNormalized, true)) {
                continue;
            }

            $type = strtolower((string) ($f['type'] ?? 'search'));

            // Localized label
            $overrideLabel = $f['label'] ?? null;
            if (is_array($overrideLabel)) {
                $label = (string) ($overrideLabel[$lang] ?? $overrideLabel['en'] ?? $this->labelFor($def, $field, $lang));
            } elseif (is_string($overrideLabel) && $overrideLabel !== '') {
                $label = $overrideLabel;
            } else {
                $label = $this->labelFor($def, $field, $lang);
            }

            $key = (string) ($f['value'] ?? $this->keyFor($def, $field));

            $out = [
                'type' => Str::title($type),
                'key' => $key,
                'label' => $label,
            ];

            $mode = strtolower((string) ($f['mode'] ?? 'self'));

            if ($mode === 'self') {
                // Compute distinct items under current constraints
                $qbuilder = $fqcn::query();
                $fqcn::applyApiFilters($qbuilder, $appliedFilters, $columnsSchema);
                $fqcn::applyApiSearch($qbuilder, $q, $searchable);
                $qbuilder->select($field)->distinct()->orderBy($field, 'asc')->limit(20);
                $items = $qbuilder->get()->map(fn($r) => $r->{$field})->values()->all();
                $out['items'] = $items;
            } elseif ($mode === 'relation') {
                $relationship = (string) ($f['relationship'] ?? '');
                $related = $relationship ? $this->resolveRelatedModel($modelInstance, $relationship) : null;
                if ($related) {
                    $relatedBase = class_basename($related);
                    $itemValue = (string) ($f['itemValue'] ?? 'id');
                    $itemTitle = (string) ($f['itemTitle'] ?? $this->pickDefaultTitleField($related));
                    $paramField = $itemValue;
                    $out['itemTitle'] = $itemTitle;
                    $out['itemValue'] = $itemValue;
                    $out['url'] = url("/api/{$relatedBase}/options/{$paramField}") . "?itemTitle={$itemTitle}&itemValue={$itemValue}&lang={$lang}";
                }
            }

            $filters[] = $out;
        }

        return $filters;
    }

    protected function buildTopLevelHeaders(
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang
    ): array {
        $fields = [];
        if (is_array($columnsSubsetNormalized) && !empty($columnsSubsetNormalized)) {
            foreach ($columnsSubsetNormalized as $token) {
                if (is_string($token) && !Str::contains($token, '.')) {
                    $fields[] = $token;
                }
            }
            $fields = array_values(array_unique($fields));
        } else {
            $fields = array_keys($columnsSchema);
        }

        $headers = [];
        foreach ($fields as $field) {
            $def = $columnsSchema[$field] ?? null;
            if (!$def) {
                continue;
            }
            $headers[] = [
                'title' => $this->labelFor($def, $field, $lang),
                'value' => $this->keyFor($def, $field),
                'sortable' => (bool) ($def['sortable'] ?? false),
                'hidden' => (bool) ($def['hidden'] ?? false),
            ];
        }

        return $headers;
    }
    protected function resolveModel(string $modelName): ?array
    {
        $fqcn = 'App\\Models\\' . $modelName;
        if (!class_exists($fqcn)) {
            return null;
        }
        $instance = new $fqcn();
        if (!method_exists($instance, 'apiSchema')) {
            return null;
        }
        return [$fqcn, $instance, $instance->apiSchema()];
    }

    protected function normalizeColumnsSubset(Model $model, ?string $columns, array $columnsSchema): array
    {
        $columnsSubsetNormalized = null;
        $relationsFromColumns = [];
        if ($columns) {
            $tokens = array_filter(array_map('trim', explode(',', $columns)));
            $columnsSubsetNormalized = [];
            foreach ($tokens as $token) {
                if (Str::contains($token, '.')) {
                    [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                    if (!$rest) {
                        throw new \InvalidArgumentException("Invalid columns segment '$token'");
                    }
                    $relationName = null;
                    if (method_exists($model, $first)) {
                        try { $relTest = $model->{$first}(); } catch (\Throwable $e) { $relTest = null; }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $first;
                        }
                    }
                    // Support snake_case alias mapping to relation method (e.g., entry_type -> entryType)
                    if (!$relationName) {
                        $camel = Str::camel($first);
                        if (method_exists($model, $camel)) {
                            try { $relTest = $model->{$camel}(); } catch (\Throwable $e) { $relTest = null; }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $camel;
                            }
                        }
                    }
                    if (!$relationName && Str::endsWith($first, '_id')) {
                        $guess = Str::camel(substr($first, 0, -3));
                        if (method_exists($model, $guess)) {
                            try { $relTest = $model->{$guess}(); } catch (\Throwable $e) { $relTest = null; }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $guess;
                            }
                        }
                    }
                    if (!$relationName) {
                        throw new \InvalidArgumentException("Unknown relation reference '$first' in columns");
                    }
                    $related = $model->{$relationName}()->getRelated();
                    if (!method_exists($related, 'apiSchema')) {
                        throw new \InvalidArgumentException("Related model for '$relationName' lacks apiSchema()");
                    }
                    $relSchema = $related->apiSchema();
                    $relColumns = $relSchema['columns'] ?? [];
                    if (!array_key_exists($rest, $relColumns)) {
                        throw new \InvalidArgumentException("Column '$rest' is not defined in $relationName apiSchema");
                    }
                    // Preserve original alias for output keys; use relationName only for eager-loading
                    $columnsSubsetNormalized[] = $first . '.' . $rest;
                    $relationsFromColumns[] = $relationName;
                } else {
                    if (!array_key_exists($token, $columnsSchema)) {
                        throw new \InvalidArgumentException("Column '$token' is not defined in apiSchema");
                    }
                    $columnsSubsetNormalized[] = $token;
                }
            }
        }
        return [$columnsSubsetNormalized, array_unique($relationsFromColumns)];
    }

    protected function parseFilters(?string $filter, array $columnsSchema): array
    {
        $filters = [];
        if ($filter) {
            $pairs = array_filter(array_map('trim', explode(',', $filter)));
            foreach ($pairs as $pair) {
                [$field, $value] = array_pad(explode(':', $pair, 2), 2, null);
                if (!$field || $value === null) {
                    throw new \InvalidArgumentException("Invalid filter segment '$pair'");
                }
                if (!array_key_exists($field, $columnsSchema)) {
                    throw new \InvalidArgumentException("Filter field '$field' is not defined in apiSchema");
                }
                if (Str::contains($value, '*')) {
                    $filters[$field] = ['like' => str_replace('*', '%', $value)];
                } else {
                    $filters[$field] = $value;
                }
            }
        }
        return $filters;
    }

    protected function parseSorts(?string $sort, array $columnsSchema): array
    {
        $sortTokens = [];
        if ($sort) {
            $sortTokens = array_filter(array_map('trim', explode(',', $sort)));
            foreach ($sortTokens as $tok) {
                $field = ltrim($tok, '-');
                if (!array_key_exists($field, $columnsSchema)) {
                    throw new \InvalidArgumentException("Sort field '$field' is not defined in apiSchema");
                }
            }
        }
        return $sortTokens;
    }

    protected function parseWithRelations(string $fqcn, Model $model, ?string $with): array
    {
        return $fqcn::parseWithRelations($model, $with);
    }

    protected function boolQuery(Request $req, string $key, bool $default = true): bool
    {
        $val = $req->query($key);
        if ($val === null) return $default;
        return filter_var($val, FILTER_VALIDATE_BOOL);
    }

    public function index(Request $request, string $modelName)
    {
        $resolved = $this->resolveModel($modelName);
        if (!$resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];
        $searchable = $schema['searchable'] ?? [];

        try {
            [$columnsSubsetNormalized, $relationsFromColumns] =
                $this->normalizeColumnsSubset($modelInstance, $request->query('columns'), $columnsSchema);
            $filters = $this->parseFilters($request->query('filter'), $columnsSchema);
            $sortTokens = $this->parseSorts($request->query('sort'), $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (!empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (!in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        $includeMeta = $this->boolQuery($request, 'include_meta', true);

        $query = $fqcn::query();

        $fqcn::applyApiFilters($query, $filters, $columnsSchema);

        $q = $request->query('q');
        $fqcn::applyApiSearch($query, $q, $searchable);

        if (!empty($relations)) {
            $query->with($relations);
        }

        $fqcn::applyApiSorts($query, $sortTokens, $columnsSchema);

        $perPage = (int) ($request->query('per_page', 25));
        $page = (int) ($request->query('page', 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $records = [];
        foreach ($paginator->items() as $item) {
            $records[] = $item->toApiRecord($columnsSubsetNormalized, $includeMeta);
        }

        // Build component settings from schema + component config file matching componentSettings key
        $lang = (string) ($request->query('lang') ?? 'dv');

        $component = (string) ($request->query('component') ?? 'listView');
        $componentSettingsKey = (string) ($request->query('componentSettings') ?? 'table');
        $componentSettings = [];

        if ($componentSettingsKey === 'table') {
            // Load table.json dynamically only when requested
            $configFile = $this->loadComponentConfig($componentSettingsKey);
            $sectionCfg = $configFile[$componentSettingsKey] ?? [];
            $componentSettings['table'] = [];
            // Headers toggle
            if (($sectionCfg['headers'] ?? 'on') === 'on') {
                $componentSettings['table']['headers'] = $this->buildHeaders($columnsSchema, $lang);
            }
            // Searchbar: include settings/buttons as-is
            $componentSettings['table']['Searchbar'] = $this->buildSearchbarSettings($sectionCfg, $modelName);
            // Filters toggle
            if (($sectionCfg['Searchbar']['filters'] ?? 'off') === 'on') {
                $componentSettings['table']['Searchbar']['filters'] = $this->buildFilters($columnsSchema, $modelName, $lang);
            }
            // Pagination toggle
            if (($sectionCfg['pagination'] ?? 'off') === 'on') {
                $componentSettings['table']['pagination'] = [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ];
            }
        }

        // Build top-level filters after data, based on selected columns (or all if none provided)
        $topLevelFilters = $this->buildTopLevelFilters(
            $fqcn,
            $modelInstance,
            $columnsSchema,
            $columnsSubsetNormalized,
            $filters,
            $q,
            $searchable,
            $lang
        );

        // Build top-level headers before filters
        $topLevelHeaders = $this->buildTopLevelHeaders($columnsSchema, $columnsSubsetNormalized, $lang);

        return response()->json([
            'data' => $records,
            'headers' => $topLevelHeaders,
            'filters' => $topLevelFilters,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'component' => $component,
            'componentSettings' => $componentSettings,
        ]);
    }

    /**
     * Options endpoint for select filters: distinct values or lookup models.
     */
    public function options(Request $request, string $modelName, string $field)
    {
        $resolved = $this->resolveModel($modelName);
        if (!$resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];
        if (!array_key_exists($field, $columnsSchema)) {
            return response()->json(['error' => "Field '$field' is not defined in apiSchema"], 422);
        }

        $itemTitle = (string) ($request->query('itemTitle') ?? $field);
        $itemValue = (string) ($request->query('itemValue') ?? $field);
        $limit = (int) ($request->query('limit') ?? 50);
        $sort = in_array(strtolower((string) $request->query('sort')), ['asc','desc'], true) ? strtolower((string) $request->query('sort')) : 'asc';

        $query = $fqcn::query();

        // If title and value are same field, distinct list; else select pairs
        if ($itemTitle === $itemValue) {
            $query->select($itemValue)->distinct()->orderBy($itemValue, $sort)->limit($limit);
            $rows = $query->get()->map(fn($r) => [
                'title' => $r->{$itemTitle},
                'value' => $r->{$itemValue},
            ])->values()->all();
        } else {
            $query->select([$itemValue, $itemTitle])->distinct()->orderBy($itemTitle, $sort)->limit($limit);
            $rows = $query->get()->map(fn($r) => [
                'title' => $r->{$itemTitle},
                'value' => $r->{$itemValue},
            ])->values()->all();
        }

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $modelName, $id)
    {
        $resolved = $this->resolveModel($modelName);
        if (!$resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];

        try {
            [$columnsSubsetNormalized, $relationsFromColumns] =
                $this->normalizeColumnsSubset($modelInstance, $request->query('columns'), $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $with = $request->query('with');
        $relations = $this->parseWithRelations($fqcn, $modelInstance, $with);
        if (!empty($relationsFromColumns)) {
            foreach ($relationsFromColumns as $rel) {
                if (!in_array($rel, $relations, true)) {
                    $relations[] = $rel;
                }
            }
        }

        $query = $fqcn::query();
        if (!empty($relations)) {
            $query->with($relations);
        }
        $model = $query->find($id);
        if (!$model) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        $record = $model->toApiRecord($columnsSubsetNormalized, $includeMeta);
        $meta = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }
        return response()->json(['data' => $record, 'meta' => $meta]);
    }

    public function store(Request $request, string $modelName)
    {
        $resolved = $this->resolveModel($modelName);
        if (!$resolved) {
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
        if (!empty($relations)) {
            $model->load($relations);
        }

        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        try {
            [$columnsSubsetNormalized, ] = $this->normalizeColumnsSubset($model, $request->query('columns'), $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $record = $model->toApiRecord($columnsSubsetNormalized, $includeMeta);
        $meta = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }

        return response()->json(['data' => $record, 'meta' => $meta], 201);
    }

    public function update(Request $request, string $modelName, $id)
    {
        $resolved = $this->resolveModel($modelName);
        if (!$resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, $modelInstance, $schema] = $resolved;
        $columnsSchema = $schema['columns'] ?? [];

        $model = $fqcn::query()->find($id);
        if (!$model) {
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
        if (!empty($relations)) {
            $model->load($relations);
        }

        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        try {
            [$columnsSubsetNormalized, ] = $this->normalizeColumnsSubset($model, $request->query('columns'), $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $record = $model->toApiRecord($columnsSubsetNormalized, $includeMeta);
        $meta = [];
        if ($includeMeta) {
            $meta['columns'] = $columnsSchema;
        }
        return response()->json(['data' => $record, 'meta' => $meta]);
    }

    public function destroy(Request $request, string $modelName, $id)
    {
        $resolved = $this->resolveModel($modelName);
        if (!$resolved) {
            return response()->json(['error' => "Model '$modelName' not found or missing apiSchema()"], 422);
        }
        [$fqcn, , ] = $resolved;
        $model = $fqcn::query()->find($id);
        if (!$model) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $model->delete();
        return response()->noContent();
    }
}
