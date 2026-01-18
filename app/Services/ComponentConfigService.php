<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ComponentConfigService
{
    /**
     * Whether hidden columns should be included when generating headers.
     */
    protected bool $includeHiddenColumnsInHeaders = false;

    /**
     * Whether to include top-level headers in API response.
     */
    protected bool $includeTopLevelHeaders = false;

    /**
     * Whether to include top-level filters in API response.
     */
    protected bool $includeTopLevelFilters = false;

    /**
     * Whether to include top-level pagination in API response.
     */
    protected bool $includeTopLevelPagination = false;

    /**
     * Construct the service with a ComponentConfigProvider.
     * Ensures headers do not include hidden columns by default.
     */
    public function __construct()
    {
        $this->setIncludeHiddenColumnsInHeaders(false);
    }

    public function setIncludeHiddenColumnsInHeaders(bool $value): void
    {
        $this->includeHiddenColumnsInHeaders = $value;
    }

    public function getIncludeTopLevelHeaders(): bool
    {
        return $this->includeTopLevelHeaders;
    }

    public function getIncludeTopLevelFilters(): bool
    {
        return $this->includeTopLevelFilters;
    }

    public function getIncludeTopLevelPagination(): bool
    {
        return $this->includeTopLevelPagination;
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
        $columnsSubsetNormalized = null;
        $relationsFromColumns = [];
        if ($columns) {
            $tokens = array_filter(array_map('trim', explode(',', $columns)));
            $columnsSubsetNormalized = [];
            foreach ($tokens as $token) {
                if (Str::contains($token, '.')) {
                    [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                    if (! $rest) {
                        throw new \InvalidArgumentException("Invalid columns segment '$token'");
                    }
                    $relationName = null;
                    if (method_exists($model, $first)) {
                        try {
                            $relTest = $model->{$first}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $first;
                        }
                    }
                    if (! $relationName) {
                        $camel = Str::camel($first);
                        if (method_exists($model, $camel)) {
                            try {
                                $relTest = $model->{$camel}();
                            } catch (\Throwable $e) {
                                $relTest = null;
                            }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $camel;
                            }
                        }
                    }
                    if (! $relationName && Str::endsWith($first, '_id')) {
                        $guess = Str::camel(substr($first, 0, -3));
                        if (method_exists($model, $guess)) {
                            try {
                                $relTest = $model->{$guess}();
                            } catch (\Throwable $e) {
                                $relTest = null;
                            }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $guess;
                            }
                        }
                    }
                    if (! $relationName) {
                        throw new \InvalidArgumentException("Unknown relation reference '$first' in columns");
                    }
                    $related = $model->{$relationName}()->getRelated();
                    if (! method_exists($related, 'apiSchema')) {
                        throw new \InvalidArgumentException("Related model for '$relationName' lacks apiSchema()");
                    }
                    $relSchema = $related->apiSchema();
                    $relColumns = $relSchema['columns'] ?? [];
                    if (! array_key_exists($rest, $relColumns)) {
                        throw new \InvalidArgumentException("Column '$rest' is not defined in $relationName apiSchema");
                    }
                    $columnsSubsetNormalized[] = $first.'.'.$rest;
                    $relationsFromColumns[] = $relationName;
                } else {
                    if (! array_key_exists($token, $columnsSchema)) {
                        throw new \InvalidArgumentException("Column '$token' is not defined in apiSchema");
                    }
                    $columnsSubsetNormalized[] = $token;
                }
            }
        }

        return [$columnsSubsetNormalized, array_unique($relationsFromColumns)];
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
            // Resolve component block and derive columns (no data queries).
            $resolvedComp = $this->resolveViewComponent(
                $modelName,
                $request->query('component'),
                $request->query('columns')
            );
            $compBlock = $resolvedComp['compBlock'];
            $columnsParam = $resolvedComp['columnsParam'];
            [$columnsSubsetNormalized, $relationsFromColumns] = $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);

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
        $lang = (string) ($request->query('lang') ?? 'dv');

        // Verify the selected language is supported by the component configuration.
        if (! $this->isLangAllowedForComponent($compBlock, $lang)) {
            return response()->json([
                'message' => "Language '$lang' not supported by view config",
                'data' => [],
            ]);
        }

        // No data queries here; GenericApiController handles data.
        $q = $request->query('q');

        // Per-page for configuration only (no DB queries).
        $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));

        // Shape records using language-aware tokens and optional meta.
        $effectiveTokens = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);
        $records = [];

        // Component settings and customizations for frontend rendering.
        $component = (string) ($resolvedComp['componentKey'] ?? '');
        $columnCustomizations = $this->getColumnCustomizationsFromComponent($compBlock);
        $allowedFilters = $this->getAllowedFiltersFromComponent($compBlock);
        $componentSettingsQuery = $request->query('componentSettings');
        if (is_string($componentSettingsQuery) && $componentSettingsQuery !== '') {
            // Validate that the requested component config exists
            $cfg = $this->loadComponentConfig($componentSettingsQuery);
            if (empty($cfg)) {
                return response()->json([
                    'error' => "Component config '{$componentSettingsQuery}' not found",
                ], 422);
            }

            $componentSettings = $this->buildComponentSettings(
                $componentSettingsQuery,
                $columnsSchema,
                $effectiveTokens,
                $lang,
                $perPage,
                $modelName,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters
            );
        } else {
            $componentsMap = $compBlock['components'] ?? [];
            $componentKeys = is_array($componentsMap) ? array_keys($componentsMap) : [];

            // Validate that all component configs exist
            $missing = [];
            foreach ($componentKeys as $k) {
                $cfg = $this->loadComponentConfig($k);
                if (empty($cfg)) {
                    $missing[] = $k;
                }
            }
            if (! empty($missing)) {
                return response()->json([
                    'error' => 'Component config(s) not found',
                    'missingComponents' => $missing,
                ], 422);
            }

            $componentSettings = $this->buildComponentSettingsForComponents(
                $componentKeys,
                $columnsSchema,
                $effectiveTokens,
                $lang,
                $perPage,
                $modelName,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters,
                is_array($componentsMap) ? $componentsMap : null
            );
        }

        // Optional: top-level headers based on component configuration.
        $topLevelHeaders = null;
        if ($this->getIncludeTopLevelHeaders()) {
            $columnCustomizations = $this->getColumnCustomizationsFromComponent($compBlock);
            $topLevelHeaders = $this->buildTopLevelHeaders($modelInstance, $columnsSchema, $effectiveTokens, $lang, $columnCustomizations);
        }

        // Optional: top-level filters including current applied filters/search.
        $topLevelFilters = null;

        if ($this->getIncludeTopLevelFilters()) {
            $allowedFilters = $this->getAllowedFiltersFromComponent($compBlock);
            $topLevelFilters = $this->buildTopLevelFilters(
                $fqcn,
                $modelInstance,
                $columnsSchema,
                $columnsSubsetNormalized,
                [],
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

        if ($this->getIncludeTopLevelPagination()) {
            $response['pagination'] = [
                'current_page' => 1,
                'per_page' => $perPage,
            ];
        }
        $response['component'] = $component;
        $response['componentSettings'] = $componentSettings;

        return response()->json($response);
    }

    /**
     * Load a view configuration JSON for a given model.
     */
    public function loadViewConfig(string $modelName): array
    {
        $path = base_path('app/Services/viewConfigs/'.Str::lower($modelName).'.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $cfg = json_decode($json, true) ?: [];

        return $cfg;
    }

    /**
     * Resolve component view block and derive columns param.
     */
    public function resolveViewComponent(string $modelName, ?string $componentKey, ?string $columnsParam): array
    {
        if (! $componentKey || ! is_string($componentKey) || $componentKey === '') {
            throw new \InvalidArgumentException('component parameter is required');
        }

        $viewCfg = $this->loadViewConfig($modelName);
        if (empty($viewCfg)) {
            throw new \InvalidArgumentException('view config file missing for model');
        }
        if (! array_key_exists($componentKey, $viewCfg)) {
            throw new \InvalidArgumentException('component key not found in view config');
        }
        $compBlock = $viewCfg[$componentKey] ?? [];
        if (! $columnsParam) {
            $compColumns = $compBlock['columns'] ?? [];
            if (! is_array($compColumns) || empty($compColumns)) {
                throw new \InvalidArgumentException('columns not defined in view config for component');
            }
            $columnsParam = implode(',', array_map('trim', $compColumns));
        }

        return [
            'componentKey' => (string) $componentKey,
            'compBlock' => $compBlock,
            'columnsParam' => (string) $columnsParam,
        ];
    }

    /**
     * Check if language is allowed by component block.
     */
    public function isLangAllowedForComponent(array $compBlock, string $lang): bool
    {
        $allowedLangs = $compBlock['lang'] ?? null;
        if (! is_array($allowedLangs) || empty($allowedLangs)) {
            return false;
        }
        $allowedNormalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $allowedLangs)));

        return in_array(strtolower($lang), $allowedNormalized, true);
    }

    /**
     * Get allowed filters from component block.
     */
    public function getAllowedFiltersFromComponent(array $compBlock): ?array
    {
        return is_array(($compBlock['filters'] ?? null)) ? array_values($compBlock['filters']) : null;
    }

    /**
     * Get column customizations from component block.
     */
    public function getColumnCustomizationsFromComponent(array $compBlock): ?array
    {
        $columnCustomizations = $compBlock['columnCustomizations'] ?? null;

        return is_array($columnCustomizations) ? $columnCustomizations : null;
    }

    /**
     * Load a component configuration JSON by key (e.g., 'table').
     */
    public function loadComponentConfig(string $componentSettingsKey): array
    {
        $path = base_path('app/Services/ComponentConfigs/'.$componentSettingsKey.'.json');
        if (! File::exists($path)) {
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

    protected function defaultFilterTypeForDef(array $columnDef): string
    {
        $colType = strtolower((string) ($columnDef['type'] ?? 'string'));
        switch ($colType) {
            case 'date':
                return 'Date';
            case 'datetime':
            case 'timestamp':
                return 'Date';
            default:
                return 'Text';
        }
    }

    protected function pickHeaderLangOverride(array $columnDef, string $requestLang): ?string
    {
        $langs = $columnDef['lang'] ?? [];
        if (! is_array($langs)) {
            return null;
        }
        $normalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $langs)));
        if (empty($normalized)) {
            return null;
        }
        $current = strtolower($requestLang);
        $candidates = array_values(array_filter($normalized, fn ($l) => $l !== $current));
        if (in_array($current, $normalized, true)) {
            if (! empty($candidates)) {
                if ($current === 'en' && in_array('dv', $candidates, true)) {
                    return 'dv';
                }
                if ($current === 'dv' && in_array('en', $candidates, true)) {
                    return 'en';
                }

                return $candidates[0];
            }

            return null;
        }
        if (in_array('en', $normalized, true)) {
            return 'en';
        }
        if (in_array('dv', $normalized, true)) {
            return 'dv';
        }

        return $normalized[0];
    }

    /**
     * Build filter definition objects for a table based on schema.
     */
    public function buildFilters(array $columnsSchema, string $modelName, string $lang, ?array $allowedFilters = null): array
    {
        $filters = [];
        foreach ($columnsSchema as $field => $def) {
            if (is_array($allowedFilters) && ! in_array($field, $allowedFilters, true)) {
                continue;
            }
            if (! is_array($def) || ! $this->columnSupportsLang($def, $lang)) {
                continue;
            }
            $f = $def['filterable'] ?? null;
            // If no explicit filter config, provide a sensible default
            if (! is_array($f)) {
                $filters[] = [
                    'type' => $this->defaultFilterTypeForDef(is_array($def) ? $def : []),
                    'key' => $this->keyFor(is_array($def) ? $def : [], $field),
                    'label' => $this->labelFor(is_array($def) ? $def : [], $field, $lang),
                ];

                continue;
            }
            $type = strtolower((string) ($f['type'] ?? 'search'));
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
                $mode = strtolower((string) ($f['mode'] ?? 'self'));
                $rawItemTitle = $f['itemTitle'] ?? $this->keyFor($def, $field);
                if (is_array($rawItemTitle)) {
                    $itemTitle = (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $this->keyFor($def, $field));
                } else {
                    $itemTitle = (string) $rawItemTitle;
                }
                $itemValue = (string) ($f['itemValue'] ?? $this->keyFor($def, $field));
                $filter['itemTitle'] = $itemTitle;
                $filter['itemValue'] = $itemValue;
                if ($mode === 'self') {
                    $items = $f['items'] ?? [];
                    if (is_array($items)) {
                        $pruned = [];
                        foreach (array_values($items) as $it) {
                            if (is_array($it)) {
                                $pruned[] = [
                                    $itemTitle => (string) ($it[$itemTitle] ?? ''),
                                    $itemValue => (string) ($it[$itemValue] ?? ''),
                                ];
                            } else {
                                $pruned[] = [
                                    $itemTitle => (string) $it,
                                    $itemValue => (string) $it,
                                ];
                            }
                        }
                        $filter['items'] = $pruned;
                    } else {
                        $filter['items'] = [];
                    }
                } else {
                    $relationship = (string) ($f['relationship'] ?? '');
                    $relatedModelName = $relationship !== '' ? Str::studly($relationship) : null;
                    if (! $relatedModelName) {
                        $base = $key;
                        if (Str::endsWith($base, '_id')) {
                            $base = Str::beforeLast($base, '_id');
                        }
                        $relatedModelName = Str::studly($base);
                    }
                    $columnsParam = $itemValue.','.$itemTitle;
                    $sortParam = $itemTitle;
                    $filter['url'] = url("/api/{$relatedModelName}").'?columns='.$columnsParam.'&sort='.$sortParam.'&pagination=off&wrap=data';
                }
            }
            $filters[] = $filter;
        }

        return $filters;
    }

    protected function resolveCustomizedTitle(?array $columnCustomizations, string $token, string $lang): ?string
    {
        if (! is_array($columnCustomizations)) {
            return null;
        }
        $custom = $columnCustomizations[$token] ?? null;
        if (! is_array($custom)) {
            return null;
        }
        $title = $custom['title'] ?? null;
        if ($title === null) {
            return null;
        }
        if (is_array($title)) {
            return (string) ($title[$lang] ?? $title['en'] ?? reset($title) ?? '');
        }
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return null;
    }

    /**
     * Recursively build a section payload from a config node.
     */
    public function buildSectionPayload(
        array $node,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        Model $modelInstance,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null
    ): array {
        $out = [];
        foreach ($node as $key => $val) {
            if ($key === 'headers') {
                if ($val === 'on') {
                    $out['headers'] = $this->buildTopLevelHeaders($modelInstance, $columnsSchema, $columnsSubsetNormalized, $lang, $columnCustomizations);
                } elseif ($val === 'off') {
                    // omit key entirely when set to 'off'
                } else {
                    $out['headers'] = $val;
                }

                continue;
            }
            if ($key === 'filters') {
                if ($val === 'on') {
                    $out['filters'] = $this->buildFilters($columnsSchema, $modelName, $lang, $allowedFilters);
                } elseif ($val === 'off') {
                    // omit key entirely when set to 'off'
                } else {
                    $out['filters'] = $val;
                }

                continue;
            }
            if ($key === 'pagination') {
                if ($val === 'on') {
                    $out['pagination'] = [
                        'current_page' => 1,
                        'per_page' => $perPage,
                    ];
                } elseif ($val === 'off') {
                    // omit key entirely when set to 'off'
                } else {
                    $out['pagination'] = $val;
                }

                continue;
            }
            if ($key === 'datalink') {
                if ($val === 'on') {
                    $out['datalink'] = $this->buildDataLink(
                        $modelInstance,
                        $columnsSchema,
                        $columnsSubsetNormalized,
                        $lang,
                        $modelName,
                        $perPage
                    );
                } elseif ($val === 'off') {
                    // omit key entirely when set to 'off'
                } else {
                    $out['datalink'] = $val;
                }

                continue;
            }
            if (is_array($val)) {
                $out[$key] = $this->buildSectionPayload($val, $columnsSchema, $columnsSubsetNormalized, $lang, $perPage, $modelName, $modelInstance, $columnCustomizations, $allowedFilters);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    /**
     * Build component settings from a component config file.
     */
    public function buildComponentSettings(
        string $componentSettingsKey,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        Model $modelInstance,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null
    ): array {
        $configFile = $this->loadComponentConfig($componentSettingsKey);
        if (empty($configFile)) {
            return [];
        }

        $componentSettings = [];

        if (isset($configFile[$componentSettingsKey]) && is_array($configFile[$componentSettingsKey])) {
            $sectionCfg = $configFile[$componentSettingsKey];
            $componentSettings[$componentSettingsKey] = $this->buildSectionPayload(
                $sectionCfg,
                $columnsSchema,
                $columnsSubsetNormalized,
                $lang,
                $perPage,
                $modelName,
                $modelInstance,
                $columnCustomizations,
                $allowedFilters
            );
        }

        foreach ($configFile as $sectionName => $sectionVal) {
            if ($sectionName === $componentSettingsKey) {
                continue;
            }

            if (is_array($sectionVal)) {
                $componentSettings[$sectionName] = $this->buildSectionPayload(
                    $sectionVal,
                    $columnsSchema,
                    $columnsSubsetNormalized,
                    $lang,
                    $perPage,
                    $modelName,
                    $modelInstance,
                    $columnCustomizations,
                    $allowedFilters
                );
            }
        }

        return $componentSettings;
    }

    /**
     * Build component settings for multiple component keys declared in view config.
     * Each key corresponds to a file in ComponentConfigs (e.g., table.json, filterSection.json).
     */
    public function buildComponentSettingsForComponents(
        array $componentKeys,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        int $perPage,
        string $modelName,
        Model $modelInstance,
        ?array $columnCustomizations = null,
        ?array $allowedFilters = null,
        ?array $componentsOverrides = null
    ): array {
        $componentSettings = [];

        foreach ($componentKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $configFile = $this->loadComponentConfig($key);
            if (empty($configFile)) {
                continue;
            }

            if (isset($configFile[$key]) && is_array($configFile[$key])) {
                $sectionCfg = $configFile[$key];
                $payload = $this->buildSectionPayload(
                    $sectionCfg,
                    $columnsSchema,
                    $columnsSubsetNormalized,
                    $lang,
                    $perPage,
                    $modelName,
                    $modelInstance,
                    $columnCustomizations,
                    $allowedFilters
                );

                // Apply overrides from view config components (e.g., button: ["create","edit"]) if provided
                if (is_array($componentsOverrides) && array_key_exists($key, $componentsOverrides)) {
                    $override = $componentsOverrides[$key];
                    if (is_array($override)) {
                        $payload = $this->applyOverridesToSection($payload, $override, $lang);
                    }
                }

                $componentSettings[$key] = $payload;
            }
        }

        return $componentSettings;
    }

    /**
     * Apply overrides from view config for a section payload.
     * Currently supports string-array filters (e.g., 'button' => ['create','edit'])
     * by matching underlying items' 'type' or 'label'.
     */
    protected function applyOverridesToSection(array $sectionPayload, array $overrides, string $lang): array
    {
        foreach ($overrides as $overrideKey => $overrideVal) {
            if (! is_array($overrideVal)) {
                continue;
            }
            $allStrings = true;
            foreach ($overrideVal as $v) {
                if (! is_string($v)) {
                    $allStrings = false;
                    break;
                }
            }
            if (! $allStrings) {
                continue;
            }

            $targetKey = $overrideKey;
            if (! array_key_exists($targetKey, $sectionPayload)) {
                $candidates = [];
                $plural = Str::plural($overrideKey);
                $singular = Str::singular($overrideKey);
                foreach ([$plural, $singular] as $cand) {
                    if (is_string($cand) && $cand !== $overrideKey) {
                        $candidates[] = $cand;
                    }
                }
                foreach ($candidates as $cand) {
                    if (array_key_exists($cand, $sectionPayload)) {
                        $targetKey = $cand;
                        break;
                    }
                }
                if (! array_key_exists($targetKey, $sectionPayload)) {
                    continue;
                }
            }
            $original = $sectionPayload[$targetKey];
            if (! is_array($original)) {
                continue;
            }

            $wanted = array_values(array_unique(array_map('strval', $overrideVal)));
            $wantedLower = array_map('strtolower', $wanted);
            $filtered = [];
            foreach ($original as $it) {
                if (is_array($it)) {
                    // Match priority: name -> type -> label
                    $candidate = (string) ($it['name'] ?? $it['type'] ?? $it['label'] ?? '');
                    $candidateLower = strtolower($candidate);
                    if ($candidate !== '' && in_array($candidateLower, $wantedLower, true)) {
                        $filtered[] = $it;
                    }
                } elseif (is_string($it)) {
                    if (in_array(strtolower($it), $wantedLower, true)) {
                        $filtered[] = $it;
                    }
                }
            }
            $sectionPayload[$targetKey] = $filtered;
        }

        return $sectionPayload;
    }

    protected function resolveRelatedModel(Model $model, string $relation): ?Model
    {
        if (! method_exists($model, $relation)) {
            return null;
        }
        try {
            $rel = $model->{$relation}();
        } catch (\Throwable $e) {
            $rel = null;
        }
        if ($rel instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            return $rel->getRelated();
        }

        return null;
    }

    protected function pickDefaultTitleField(Model $related): string
    {
        $schema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
        $columns = $schema['columns'] ?? [];
        foreach (['name', 'name_eng', 'title'] as $preferred) {
            if (array_key_exists($preferred, $columns) && ($columns[$preferred]['hidden'] ?? false) === false) {
                return $preferred;
            }
        }
        foreach ($columns as $field => $def) {
            if (($def['hidden'] ?? false) === false && ($def['type'] ?? '') === 'string') {
                return $field;
            }
        }

        return 'id';
    }

    /**
     * Build top-level filters, including relation-backed select sources.
     */
    // public function buildTopLevelFilters(
    //     string $fqcn,
    //     Model $modelInstance,
    //     array $columnsSchema,
    //     ?array $columnsSubsetNormalized,
    //     array $appliedFilters,
    //     ?string $q,
    //     array $searchable,
    //     string $lang,
    //     ?array $allowedFilters = null
    // ): array {
    //     $filters = [];

    //     foreach ($columnsSchema as $field => $def) {
    //         $f = $def['filterable'] ?? null;
    //         if (! $f || ! is_array($f)) {
    //             continue;
    //         }
    //         if (is_array($allowedFilters) && ! in_array($field, $allowedFilters, true)) {
    //             continue;
    //         }
    //         if (is_array($columnsSubsetNormalized) && ! in_array($field, $columnsSubsetNormalized, true)) {
    //             continue;
    //         }

    //         $type = strtolower((string) ($f['type'] ?? 'search'));
    //         $overrideLabel = $f['label'] ?? null;
    //         if (is_array($overrideLabel)) {
    //             $label = (string) ($overrideLabel[$lang] ?? $overrideLabel['en'] ?? $this->labelFor($def, $field, $lang));
    //         } elseif (is_string($overrideLabel) && $overrideLabel !== '') {
    //             $label = $overrideLabel;
    //         } else {
    //             $label = $this->labelFor($def, $field, $lang);
    //         }

    //         $key = (string) ($f['value'] ?? $this->keyFor($def, $field));
    //         $out = [
    //             'type' => Str::title($type),
    //             'key' => $key,
    //             'label' => $label,
    //         ];

    //         $mode = strtolower((string) ($f['mode'] ?? 'self'));
    //         if ($mode === 'self') {
    //             $rawItemTitle = $f['itemTitle'] ?? $this->keyFor($def, $field);
    //             if (is_array($rawItemTitle)) {
    //                 $itemTitle = (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $this->keyFor($def, $field));
    //             } else {
    //                 $itemTitle = (string) $rawItemTitle;
    //             }
    //             $itemValue = (string) ($f['itemValue'] ?? $this->keyFor($def, $field));
    //             $out['itemTitle'] = $itemTitle;
    //             $out['itemValue'] = $itemValue;
    //             $items = $f['items'] ?? [];
    //             if (is_array($items)) {
    //                 $pruned = [];
    //                 foreach (array_values($items) as $it) {
    //                     if (is_array($it)) {
    //                         $pruned[] = [
    //                             $itemTitle => (string) ($it[$itemTitle] ?? ''),
    //                             $itemValue => (string) ($it[$itemValue] ?? ''),
    //                         ];
    //                     } else {
    //                         $pruned[] = [
    //                             $itemTitle => (string) $it,
    //                             $itemValue => (string) $it,
    //                         ];
    //                     }
    //                 }
    //                 $out['items'] = $pruned;
    //             } else {
    //                 $out['items'] = [];
    //             }
    //         } elseif ($mode === 'relation') {
    //             $relationship = (string) ($f['relationship'] ?? '');
    //             $related = $relationship ? $this->resolveRelatedModel($modelInstance, $relationship) : null;
    //             if ($related) {
    //                 $relatedBase = class_basename($related);
    //                 $itemValue = (string) ($f['itemValue'] ?? 'id');
    //                 $rawItemTitle = $f['itemTitle'] ?? $this->pickDefaultTitleField($related);
    //                 if (is_array($rawItemTitle)) {
    //                     $itemTitle = (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $this->pickDefaultTitleField($related));
    //                 } else {
    //                     $itemTitle = (string) $rawItemTitle;
    //                 }
    //                 $out['itemTitle'] = $itemTitle;
    //                 $out['itemValue'] = $itemValue;
    //                 $base = url("/api/{$relatedBase}");
    //                 $query = "columns={$itemValue},{$itemTitle}&sort={$itemTitle}&pagination=off&wrap=data";
    //                 $out['url'] = $base.'?'.$query;
    //             }
    //         }

    //         $filters[] = $out;
    //     }

    //     return $filters;
    // }

    public function buildTopLevelFilters(
        string $fqcn,
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        array $appliedFilters,
        ?string $q,
        array $searchable,
        string $lang,
        ?array $allowedFilters = null
    ): array {
        $filters = [];

        // Decide which fields we are iterating over
        $fields = is_array($allowedFilters)
            ? $allowedFilters
            : array_keys($columnsSchema);

        foreach ($fields as $field) {
            $def = $columnsSchema[$field] ?? null;
            $f = is_array($def) ? ($def['filterable'] ?? null) : null;

            // Skip fields that don't support the requested language
            if (! is_array($def) || ! $this->columnSupportsLang($def, $lang)) {
                continue;
            }

            if (is_array($columnsSubsetNormalized) && ! in_array($field, $columnsSubsetNormalized, true)) {
                continue;
            }

            // Base label + key (used by both minimal and full filter)
            $label = $def
                ? $this->labelFor($def, $field, $lang)
                : Str::title(str_replace('_', ' ', $field));

            $key = $def
                ? $this->keyFor($def, $field)
                : $field;

            // If no filter config → minimal output
            if (! is_array($f)) {
                $filters[] = [
                    'type' => $def ? $this->defaultFilterTypeForDef($def) : 'Search',
                    'key' => $key,
                    'label' => $label,
                ];

                continue;
            }

            // -------- Existing logic (mostly unchanged) --------

            $type = strtolower((string) ($f['type'] ?? 'search'));
            $overrideLabel = $f['label'] ?? null;

            if (is_array($overrideLabel)) {
                $label = (string) ($overrideLabel[$lang] ?? $overrideLabel['en'] ?? $label);
            } elseif (is_string($overrideLabel) && $overrideLabel !== '') {
                $label = $overrideLabel;
            }

            $key = (string) ($f['value'] ?? $key);

            $out = [
                'type' => Str::title($type),
                'key' => $key,
                'label' => $label,
            ];

            $mode = strtolower((string) ($f['mode'] ?? 'self'));

            if ($mode === 'self') {
                $rawItemTitle = $f['itemTitle'] ?? $key;
                $itemTitle = is_array($rawItemTitle)
                    ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle))
                    : (string) $rawItemTitle;

                $itemValue = (string) ($f['itemValue'] ?? $key);

                $out['itemTitle'] = $itemTitle;
                $out['itemValue'] = $itemValue;

                $items = $f['items'] ?? [];
                $out['items'] = [];

                if (is_array($items)) {
                    foreach (array_values($items) as $it) {
                        if (is_array($it)) {
                            $out['items'][] = [
                                $itemTitle => (string) ($it[$itemTitle] ?? ''),
                                $itemValue => (string) ($it[$itemValue] ?? ''),
                            ];
                        } else {
                            $out['items'][] = [
                                $itemTitle => (string) $it,
                                $itemValue => (string) $it,
                            ];
                        }
                    }
                }
            }

            if ($mode === 'relation') {
                $relationship = (string) ($f['relationship'] ?? '');
                $related = $relationship
                    ? $this->resolveRelatedModel($modelInstance, $relationship)
                    : null;

                if ($related) {
                    $itemValue = (string) ($f['itemValue'] ?? 'id');
                    $rawItemTitle = $f['itemTitle'] ?? $this->pickDefaultTitleField($related);
                    $itemTitle = is_array($rawItemTitle)
                        ? (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle))
                        : (string) $rawItemTitle;

                    $out['itemTitle'] = $itemTitle;
                    $out['itemValue'] = $itemValue;

                    $base = url('/api/'.class_basename($related));
                    $query = "columns={$itemValue},{$itemTitle}&sort={$itemTitle}&pagination=off&wrap=data";
                    $out['url'] = $base.'?'.$query;
                }
            }

            $filters[] = $out;
        }

        return $filters;
    }

    public function columnSupportsLang(array $def, string $lang): bool
    {
        $langs = $def['lang'] ?? null;
        if (! is_array($langs)) {
            return true;
        }
        $normalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $langs)));

        return in_array(strtolower($lang), $normalized, true);
    }

    /**
     * Filter column tokens by language support from schema and relations.
     */
    public function filterTokensByLangSupport(Model $modelInstance, array $columnsSchema, array $tokens, string $lang): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (Str::contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    continue;
                }
                $relationName = null;
                if (method_exists($modelInstance, $first)) {
                    try {
                        $relTest = $modelInstance->{$first}();
                    } catch (\Throwable $e) {
                        $relTest = null;
                    }
                    if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relationName = $first;
                    }
                }
                if (! $relationName) {
                    $camel = Str::camel($first);
                    if (method_exists($modelInstance, $camel)) {
                        try {
                            $relTest = $modelInstance->{$camel}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $camel;
                        }
                    }
                }
                if (! $relationName && Str::endsWith($first, '_id')) {
                    $guess = Str::camel(substr($first, 0, -3));
                    if (method_exists($modelInstance, $guess)) {
                        try {
                            $relTest = $modelInstance->{$guess}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $guess;
                        }
                    }
                }

                if ($relationName) {
                    $related = $modelInstance->{$relationName}()->getRelated();
                    $relSchema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
                    $relColumns = $relSchema['columns'] ?? [];
                    $relDef = $relColumns[$rest] ?? null;
                    if ($relDef && $this->columnSupportsLang($relDef, $lang)) {
                        $out[] = $token;
                    }

                    continue;
                }

                continue;
            }

            $def = $columnsSchema[$token] ?? null;
            if ($def && $this->columnSupportsLang($def, $lang)) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * Build headers for top-level data, supporting relation tokens and labels.
     */
    public function buildTopLevelHeaders(
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        ?array $columnCustomizations = null
    ): array {
        $fields = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $fields = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $fields = array_keys($columnsSchema);
        }

        $fields = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $fields, $lang);

        $headers = [];
        foreach ($fields as $token) {
            $overrideTitle = $this->resolveCustomizedTitle($columnCustomizations, $token, $lang);

            if (Str::contains($token, '.')) {
                [$first, $rest] = array_pad(explode('.', $token, 2), 2, null);
                if (! $rest) {
                    continue;
                }

                $relationName = null;
                if (method_exists($modelInstance, $first)) {
                    try {
                        $relTest = $modelInstance->{$first}();
                    } catch (\Throwable $e) {
                        $relTest = null;
                    }
                    if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $relationName = $first;
                    }
                }
                if (! $relationName) {
                    $camel = Str::camel($first);
                    if (method_exists($modelInstance, $camel)) {
                        try {
                            $relTest = $modelInstance->{$camel}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $camel;
                        }
                    }
                }
                if (! $relationName && Str::endsWith($first, '_id')) {
                    $guess = Str::camel(substr($first, 0, -3));
                    if (method_exists($modelInstance, $guess)) {
                        try {
                            $relTest = $modelInstance->{$guess}();
                        } catch (\Throwable $e) {
                            $relTest = null;
                        }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $guess;
                        }
                    }
                }

                $relDef = null;
                if ($relationName) {
                    $related = $modelInstance->{$relationName}()->getRelated();
                    $relSchema = method_exists($related, 'apiSchema') ? $related->apiSchema() : [];
                    $relColumns = $relSchema['columns'] ?? [];
                    $relDef = $relColumns[$rest] ?? null;
                }

                if (! $this->includeHiddenColumnsInHeaders && $relDef && (bool) ($relDef['hidden'] ?? false) === true) {
                    continue;
                }

                $title = $overrideTitle;
                if ($title === null) {
                    if ($relDef) {
                        $relLabel = $relDef['relationLabel'] ?? null;
                        if (is_array($relLabel)) {
                            $title = (string) ($relLabel[$lang] ?? $relLabel['en'] ?? $this->labelFor($relDef, $rest, $lang));
                        } elseif (is_string($relLabel) && $relLabel !== '') {
                            $title = $relLabel;
                        } else {
                            $title = $this->labelFor($relDef, $rest, $lang);
                        }
                    }
                }
                if ($title === null) {
                    $title = Str::title(str_replace('_', ' ', $rest));
                }

                $header = [
                    'title' => $title,
                    'value' => $token,
                    'sortable' => (bool) ($relDef['sortable'] ?? false),
                    'hidden' => (bool) ($relDef['hidden'] ?? false),
                ];
                if ($relDef && array_key_exists('type', $relDef)) {
                    $header['type'] = (string) $relDef['type'];
                }
                if ($relDef && array_key_exists('displayType', $relDef)) {
                    $header['displayType'] = (string) $relDef['displayType'];
                }
                if ($relDef && array_key_exists('displayProps', $relDef) && is_array($relDef['displayProps'])) {
                    $header['displayProps'] = $relDef['displayProps'];
                }
                if ($relDef && array_key_exists('inlineEditable', $relDef)) {
                    $header['inlineEditable'] = (bool) $relDef['inlineEditable'];
                }
                if ($relDef) {
                    $override = $this->pickHeaderLangOverride($relDef, $lang);
                    if ($override !== null) {
                        $header['lang'] = $override;
                    }
                }
                $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
                if (is_array($custom)) {
                    if (array_key_exists('sortable', $custom)) {
                        $header['sortable'] = (bool) $custom['sortable'];
                    }
                    if (array_key_exists('hidden', $custom)) {
                        $header['hidden'] = (bool) $custom['hidden'];
                    }
                    if (array_key_exists('type', $custom)) {
                        $header['type'] = (string) $custom['type'];
                    }
                    if (array_key_exists('displayType', $custom)) {
                        $header['displayType'] = (string) $custom['displayType'];
                    }
                    if (array_key_exists('displayProps', $custom) && is_array($custom['displayProps'])) {
                        $header['displayProps'] = $custom['displayProps'];
                    }
                    if (array_key_exists('inlineEditable', $custom)) {
                        $header['inlineEditable'] = (bool) $custom['inlineEditable'];
                    }
                    if (array_key_exists('editable', $custom)) {
                        $header['inlineEditable'] = (bool) $custom['editable'];
                    }
                    foreach ($custom as $k => $v) {
                        if ($k === 'title' || $k === 'value') {
                            continue;
                        }
                        if (! array_key_exists($k, $header)) {
                            $header[$k] = $v;
                        }
                    }
                }
                $headers[] = $header;

                continue;
            }

            $def = $columnsSchema[$token] ?? null;
            if (! $def) {
                continue;
            }
            if (! $this->includeHiddenColumnsInHeaders && (bool) ($def['hidden'] ?? false) === true) {
                continue;
            }
            $header = [
                'title' => $overrideTitle ?? $this->labelFor($def, $token, $lang),
                'value' => $this->keyFor($def, $token),
                'sortable' => (bool) ($def['sortable'] ?? false),
                'hidden' => (bool) ($def['hidden'] ?? false),
            ];
            if (array_key_exists('type', $def)) {
                $header['type'] = (string) $def['type'];
            }
            if (array_key_exists('displayType', $def)) {
                $header['displayType'] = (string) $def['displayType'];
            }
            if (array_key_exists('displayProps', $def) && is_array($def['displayProps'])) {
                $header['displayProps'] = $def['displayProps'];
            }
            if (array_key_exists('inlineEditable', $def)) {
                $header['inlineEditable'] = (bool) $def['inlineEditable'];
            }
            $override = $this->pickHeaderLangOverride($def, $lang);
            if ($override !== null) {
                $header['lang'] = $override;
            }
            $custom = is_array($columnCustomizations) ? ($columnCustomizations[$token] ?? null) : null;
            if (is_array($custom)) {
                if (array_key_exists('sortable', $custom)) {
                    $header['sortable'] = (bool) $custom['sortable'];
                }
                if (array_key_exists('hidden', $custom)) {
                    $header['hidden'] = (bool) $custom['hidden'];
                }
                if (array_key_exists('type', $custom)) {
                    $header['type'] = (string) $custom['type'];
                }
                if (array_key_exists('displayType', $custom)) {
                    $header['displayType'] = (string) $custom['displayType'];
                }
                if (array_key_exists('displayProps', $custom) && is_array($custom['displayProps'])) {
                    $header['displayProps'] = $custom['displayProps'];
                }
                if (array_key_exists('inlineEditable', $custom)) {
                    $header['inlineEditable'] = (bool) $custom['inlineEditable'];
                }
                if (array_key_exists('editable', $custom)) {
                    $header['inlineEditable'] = (bool) $custom['editable'];
                }
                foreach ($custom as $k => $v) {
                    if ($k === 'title' || $k === 'value') {
                        continue;
                    }
                    if (! array_key_exists($k, $header)) {
                        $header[$k] = $v;
                    }
                }
            }
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * Build an absolute datalink URL to the generic data API based on component config.
     * Includes columns, with (relation:field), and per_page only.
     */
    protected function buildDataLink(
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        string $modelName,
        int $perPage
    ): string {
        $baseTokens = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $baseTokens = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $baseTokens = array_keys($columnsSchema);
        }
        $tokens = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $baseTokens, $lang);

        $relationFields = [];
        foreach ($tokens as $token) {
            if (! Str::contains($token, '.')) {
                continue;
            }
            [$rel, $field] = array_pad(explode('.', $token, 2), 2, null);
            if (! $field) {
                continue;
            }
            if (! isset($relationFields[$rel])) {
                $relationFields[$rel] = [];
            }
            if (! in_array($field, $relationFields[$rel], true)) {
                $relationFields[$rel][] = $field;
            }
        }

        $withSegments = [];
        foreach ($relationFields as $rel => $fields) {
            $withSegments[] = $rel.':'.implode(',', $fields);
        }

        $base = url("/api/{$modelName}");

        $query = 'columns='.implode(',', $tokens);
        if (! empty($withSegments)) {
            $query .= '&with='.implode(',', $withSegments);
        }
        $query .= '&per_page='.$perPage;

        return $base.'?'.$query;
    }
}
