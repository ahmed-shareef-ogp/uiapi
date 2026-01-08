<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenericApiService
{
    protected bool $includeTopLevelHeaders = false;

    protected bool $includeTopLevelFilters = false;

    protected bool $includeTopLevelPagination = false;


    protected bool $includeHiddenColumnsInHeaders = false;

    protected function loadViewConfig(string $modelName): array
    {
        $path = base_path('app/Services/viewConfigs/'.Str::lower($modelName).'.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $cfg = json_decode($json, true) ?: [];

        return $cfg;
    }

    protected function loadComponentConfig(string $componentSettingsKey): array
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
            // Column supports current lang; show an alternate if available
            if (! empty($candidates)) {
                // Prefer 'dv' if current is 'en', or 'en' if current is 'dv', else first
                if ($current === 'en' && in_array('dv', $candidates, true)) {
                    return 'dv';
                }
                if ($current === 'dv' && in_array('en', $candidates, true)) {
                    return 'en';
                }
                return $candidates[0];
            }
            // Only one lang and it's the current one; nothing extra to show
            return null;
        }
        // Current lang not supported; suggest a best available
        if (in_array('en', $normalized, true)) {
            return 'en';
        }
        if (in_array('dv', $normalized, true)) {
            return 'dv';
        }
        return $normalized[0];
    }

    protected function buildHeaders(array $columnsSchema, string $lang): array
    {
        $headers = [];
        foreach ($columnsSchema as $field => $def) {
            $header = [
                'title' => $this->labelFor($def, $field, $lang),
                'value' => $this->keyFor($def, $field),
                'sortable' => (bool) ($def['sortable'] ?? false),
                'hidden' => (bool) ($def['hidden'] ?? false),
            ];
            if (array_key_exists('type', $def)) {
                $header['type'] = (string) $def['type'];
            }
            if (array_key_exists('displayType', $def)) {
                $header['displayType'] = (string) $def['displayType'];
            }
            $override = $this->pickHeaderLangOverride($def, $lang);
            if ($override !== null) {
                $header['lang'] = $override;
            }
            $headers[] = $header;
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

    protected function buildFilters(array $columnsSchema, string $modelName, string $lang, ?array $allowedFilters = null): array
    {
        $filters = [];
        foreach ($columnsSchema as $field => $def) {
            if (is_array($allowedFilters) && ! in_array($field, $allowedFilters, true)) {
                continue;
            }
            $f = $def['filterable'] ?? null;
            if (! $f || ! is_array($f)) {
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
                $mode = strtolower((string) ($f['mode'] ?? 'self'));
                $itemTitle = (string) ($f['itemTitle'] ?? $this->keyFor($def, $field));
                $itemValue = (string) ($f['itemValue'] ?? $this->keyFor($def, $field));
                $filter['itemTitle'] = $itemTitle;
                $filter['itemValue'] = $itemValue;
                if ($mode === 'self') {
                    // No DB query: use schema-defined items
                    $items = $f['items'] ?? [];
                    if (is_array($items)) {
                        $filter['items'] = array_values($items);
                    } else {
                        $filter['items'] = [];
                    }
                } else {
                    // relation (or external) mode: provide URL for options endpoint
                    $sourceModel = (string) ($f['sourceModel'] ?? $modelName);
                    // When sourcing from another model, validate using a field it actually has.
                    $paramField = ($sourceModel === $modelName) ? $field : $itemValue;
                    $filter['url'] = url("/api/{$sourceModel}/options/{$paramField}")."?itemTitle={$itemTitle}&itemValue={$itemValue}&lang={$lang}";
                }
            }
            $filters[] = $filter;
        }

        return $filters;
    }

    protected function resolveOverrideTitle(?array $labelOverrides, string $token, string $lang): ?string
    {
        if (! is_array($labelOverrides)) {
            return null;
        }
        $entry = $labelOverrides[$token] ?? null;
        if ($entry === null) {
            return null;
        }
        if (is_array($entry)) {
            return (string) ($entry[$lang] ?? $entry['en'] ?? reset($entry) ?? '');
        }
        if (is_string($entry) && $entry !== '') {
            return $entry;
        }

        return null;
    }

    protected function buildSectionPayload(
        array $node,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        $paginator,
        string $modelName,
        Model $modelInstance,
        ?array $labelOverrides = null,
        ?array $allowedFilters = null
    ): array {
        $out = [];
        foreach ($node as $key => $val) {
            if ($key === 'headers') {
                if ($val === 'on') {
                    $out['headers'] = $this->buildTopLevelHeaders($modelInstance, $columnsSchema, $columnsSubsetNormalized, $lang, $labelOverrides);
                } else {
                    $out['headers'] = $val;
                }

                continue;
            }
            if ($key === 'filters') {
                if ($val === 'on') {
                    $out['filters'] = $this->buildFilters($columnsSchema, $modelName, $lang, $allowedFilters);
                } else {
                    $out['filters'] = $val;
                }

                continue;
            }
            if ($key === 'pagination') {
                if ($val === 'on') {
                    $out['pagination'] = [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ];
                } else {
                    $out['pagination'] = $val;
                }

                continue;
            }
            // Pass-through other settings as-is, recursing into arrays
            if (is_array($val)) {
                $out[$key] = $this->buildSectionPayload($val, $columnsSchema, $columnsSubsetNormalized, $lang, $paginator, $modelName, $modelInstance, $labelOverrides, $allowedFilters);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
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
        string $lang,
        ?array $allowedFilters = null
    ): array {
        $filters = [];

        foreach ($columnsSchema as $field => $def) {
            $f = $def['filterable'] ?? null;
            if (! $f || ! is_array($f)) {
                continue;
            }

            if (is_array($allowedFilters) && ! in_array($field, $allowedFilters, true)) {
                continue;
            }

            // Respect columns selection: include only selected columns when subset provided
            if (is_array($columnsSubsetNormalized) && ! in_array($field, $columnsSubsetNormalized, true)) {
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
                // No DB query: use schema-defined items
                $items = $f['items'] ?? [];
                if (is_array($items)) {
                    $out['items'] = array_values($items);
                } else {
                    $out['items'] = [];
                }
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
                    $out['url'] = url("/api/{$relatedBase}/options/{$paramField}")."?itemTitle={$itemTitle}&itemValue={$itemValue}&lang={$lang}";
                }
            }

            $filters[] = $out;
        }

        return $filters;
    }

    protected function columnSupportsLang(array $def, string $lang): bool
    {
        $langs = $def['lang'] ?? null;
        if (! is_array($langs)) {
            return true;
        }
        $normalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $langs)));
        return in_array(strtolower($lang), $normalized, true);
    }

    protected function filterTokensByLangSupport(Model $modelInstance, array $columnsSchema, array $tokens, string $lang): array
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

    protected function reorderRecord(array $record, array $orderedTokens): array
    {
        if (empty($orderedTokens)) {
            return $record;
        }
        $out = [];
        foreach ($orderedTokens as $token) {
            if (array_key_exists($token, $record)) {
                $out[$token] = $record[$token];
            }
        }
        foreach ($record as $k => $v) {
            if (! array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    protected function buildTopLevelHeaders(
        Model $modelInstance,
        array $columnsSchema,
        ?array $columnsSubsetNormalized,
        string $lang,
        ?array $labelOverrides = null
    ): array {
        $fields = [];
        if (is_array($columnsSubsetNormalized) && ! empty($columnsSubsetNormalized)) {
            $fields = array_values(array_unique(array_filter(array_map(fn ($t) => is_string($t) ? $t : null, $columnsSubsetNormalized))));
        } else {
            $fields = array_keys($columnsSchema);
        }

        // Language-gate headers to match data columns
        $fields = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $fields, $lang);

        $headers = [];
        foreach ($fields as $token) {
            $overrideTitle = $this->resolveOverrideTitle($labelOverrides, $token, $lang);

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
                if ($relDef) {
                    $override = $this->pickHeaderLangOverride($relDef, $lang);
                    if ($override !== null) {
                        $header['lang'] = $override;
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
            $override = $this->pickHeaderLangOverride($def, $lang);
            if ($override !== null) {
                $header['lang'] = $override;
            }
            $headers[] = $header;
        }

        return $headers;
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
                    // Support snake_case alias mapping to relation method (e.g., entry_type -> entryType)
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
                    // Preserve original alias for output keys; use relationName only for eager-loading
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

    protected function parseFilters(?string $filter, array $columnsSchema): array
    {
        $filters = [];
        if ($filter) {
            $pairs = array_filter(array_map('trim', explode(',', $filter)));
            foreach ($pairs as $pair) {
                [$field, $value] = array_pad(explode(':', $pair, 2), 2, null);
                if (! $field || $value === null) {
                    throw new \InvalidArgumentException("Invalid filter segment '$pair'");
                }
                if (! array_key_exists($field, $columnsSchema)) {
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
                if (! array_key_exists($field, $columnsSchema)) {
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

        // Component is required for index
        $componentParam = $request->query('component');
        if (! $componentParam || ! is_string($componentParam) || $componentParam === '') {
            return response()->json(['error' => 'component parameter is required'], 422);
        }

        // Resolve columns: query param takes precedence; otherwise read from view config JSON under the given component
        $columnsParam = $request->query('columns');
        $viewCfg = [];
        if (! $columnsParam) {
            $viewCfg = $this->loadViewConfig($modelName);
            if (empty($viewCfg)) {
                return response()->json(['error' => 'view config file missing for model'], 422);
            }
            if (! array_key_exists($componentParam, $viewCfg)) {
                return response()->json(['error' => 'component key not found in view config'], 422);
            }
            $compBlock = $viewCfg[$componentParam] ?? [];
            $compColumns = $compBlock['columns'] ?? [];
            if (! is_array($compColumns) || empty($compColumns)) {
                return response()->json(['error' => 'columns not defined in view config for component'], 422);
            }
            $columnsParam = implode(',', array_map('trim', $compColumns));
        } else {
            // When columns provided, still ensure the component exists in view config (as requested)
            $viewCfg = $this->loadViewConfig($modelName);
            if (empty($viewCfg) || ! array_key_exists($componentParam, $viewCfg)) {
                return response()->json(['error' => 'component key not found in view config'], 422);
            }
            $compBlock = $viewCfg[$componentParam] ?? [];
        }

        try {
            [$columnsSubsetNormalized, $relationsFromColumns] =
                $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);
            $filters = $this->parseFilters($request->query('filter'), $columnsSchema);
            $sortTokens = $this->parseSorts($request->query('sort'), $columnsSchema);
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

        $includeMeta = $this->boolQuery($request, 'include_meta', true);
        $lang = (string) ($request->query('lang') ?? 'dv');

        // If view config declares supported languages and requested lang is not among them, return message with no data
        $allowedLangs = $compBlock['lang'] ?? null;
        if (is_array($allowedLangs)) {
            $allowedNormalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $allowedLangs)));
            if (! in_array(strtolower($lang), $allowedNormalized, true)) {
                return response()->json([
                    'message' => "Language '$lang' not supported by view config",
                    'data' => [],
                ]);
            }
        }

        $query = $fqcn::query();

        $fqcn::applyApiFilters($query, $filters, $columnsSchema);

        $q = $request->query('q');
        $fqcn::applyApiSearch($query, $q, $searchable);

        if (! empty($relations)) {
            $query->with($relations);
        }

        $fqcn::applyApiSorts($query, $sortTokens, $columnsSchema);

        // per_page: use query param if present, else fallback to view config component's per_page
        $perPage = (int) ($request->query('per_page') ?? ($compBlock['per_page'] ?? 25));
        $page = (int) ($request->query('page', 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Filter selected columns by language support
        $effectiveTokens = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], $lang);

        $records = [];
        foreach ($paginator->items() as $item) {
            $rec = $item->toApiRecord($effectiveTokens, $includeMeta);
            $records[] = $this->reorderRecord($rec, $effectiveTokens);
        }

        // Build component settings from schema + component config file matching componentSettings key

        $component = (string) $componentParam;
        $componentSettingsKey = (string) ($request->query('componentSettings') ?? 'table');
        $componentSettings = [];

        if ($componentSettingsKey === 'table') {
            // Load table.json dynamically only when requested
            $configFile = $this->loadComponentConfig($componentSettingsKey);
            $labelOverrides = $compBlock['labelOverrides'] ?? null;
            $allowedFilters = is_array($compBlock['filters'] ?? null) ? array_values($compBlock['filters']) : null;
            if (isset($configFile[$componentSettingsKey]) && is_array($configFile[$componentSettingsKey])) {
                $sectionCfg = $configFile[$componentSettingsKey];
                $componentSettings['table'] = $this->buildSectionPayload(
                    $sectionCfg,
                    $columnsSchema,
                    $effectiveTokens,
                    $lang,
                    $paginator,
                    $modelName,
                    $modelInstance,
                    $labelOverrides,
                    $allowedFilters
                );
            }
            // Include any sibling sections (e.g., FilterSection) exactly mirroring config, with special keys transformed
            foreach ($configFile as $sectionName => $sectionVal) {
                if ($sectionName === $componentSettingsKey) {
                    continue;
                }
                if (is_array($sectionVal)) {
                    $componentSettings[$sectionName] = $this->buildSectionPayload(
                        $sectionVal,
                        $columnsSchema,
                        $effectiveTokens,
                        $lang,
                        $paginator,
                        $modelName,
                        $modelInstance,
                        $labelOverrides,
                        $allowedFilters
                    );
                }
            }
        }

        // Conditionally build top-level headers and filters
        $topLevelHeaders = null;
        if ($this->includeTopLevelHeaders) {
            $labelOverrides = $compBlock['labelOverrides'] ?? null;
            $topLevelHeaders = $this->buildTopLevelHeaders($modelInstance, $columnsSchema, $effectiveTokens, $lang, $labelOverrides);
        }

        $topLevelFilters = null;
        if ($this->includeTopLevelFilters) {
            $allowedFilters = is_array($compBlock['filters'] ?? null) ? array_values($compBlock['filters']) : null;
            $topLevelFilters = $this->buildTopLevelFilters(
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

        // Assemble response with conditional top-level keys in requested order
        $response = [
            'data' => $records,
        ];
        if ($topLevelHeaders !== null) {
            $response['headers'] = $topLevelHeaders;
        }
        if ($topLevelFilters !== null) {
            $response['filters'] = $topLevelFilters;
        }
        if ($this->includeTopLevelPagination) {
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
     * Options endpoint for select filters: distinct values or lookup models.
     */
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

        // If title and value are same field, distinct list; else select pairs
        if ($itemTitle === $itemValue) {
            $query->select($itemValue)->distinct()->orderBy($itemValue, $sort)->limit($limit);
            $rows = $query->get()->map(fn ($r) => [
                'title' => $r->{$itemTitle},
                'value' => $r->{$itemValue},
            ])->values()->all();
        } else {
            $query->select([$itemValue, $itemTitle])->distinct()->orderBy($itemTitle, $sort)->limit($limit);
            $rows = $query->get()->map(fn ($r) => [
                'title' => $r->{$itemTitle},
                'value' => $r->{$itemValue},
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

        // Component required; use its columns when query 'columns' is absent
        $componentParam = $request->query('component');
        if (! $componentParam || ! is_string($componentParam) || $componentParam === '') {
            return response()->json(['error' => 'component parameter is required'], 422);
        }
        $columnsParam = $request->query('columns');
        $viewCfg = $this->loadViewConfig($modelName);
        if (empty($viewCfg) || ! array_key_exists($componentParam, $viewCfg)) {
            return response()->json(['error' => 'component key not found in view config'], 422);
        }
        if (! $columnsParam) {
            $compBlock = $viewCfg[$componentParam] ?? [];
            $compColumns = $compBlock['columns'] ?? [];
            if (! is_array($compColumns) || empty($compColumns)) {
                return response()->json(['error' => 'columns not defined in view config for component'], 422);
            }
            $columnsParam = implode(',', array_map('trim', $compColumns));
        }

        // Language support gate based on view config's declared languages
        $lang = (string) ($request->query('lang') ?? 'dv');
        $allowedLangs = ($viewCfg[$componentParam]['lang'] ?? null);
        if (is_array($allowedLangs)) {
            $allowedNormalized = array_values(array_unique(array_map(fn ($l) => strtolower((string) $l), $allowedLangs)));
            if (! in_array(strtolower($lang), $allowedNormalized, true)) {
                return response()->json([
                    'message' => "Language '$lang' not supported by view config",
                    'data' => [],
                ]);
            }
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
        $effectiveTokensShow = $this->filterTokensByLangSupport($modelInstance, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record = $model->toApiRecord($effectiveTokensShow, $includeMeta);
        $record = $this->reorderRecord($record, $effectiveTokensShow);
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
        // Component required; use its columns when query 'columns' is absent
        $componentParam = $request->query('component');
        if (! $componentParam || ! is_string($componentParam) || $componentParam === '') {
            return response()->json(['error' => 'component parameter is required'], 422);
        }
        $columnsParam = $request->query('columns');
        $viewCfg = $this->loadViewConfig($modelName);
        if (empty($viewCfg) || ! array_key_exists($componentParam, $viewCfg)) {
            return response()->json(['error' => 'component key not found in view config'], 422);
        }
        if (! $columnsParam) {
            $compBlock = $viewCfg[$componentParam] ?? [];
            $compColumns = $compBlock['columns'] ?? [];
            if (! is_array($compColumns) || empty($compColumns)) {
                return response()->json(['error' => 'columns not defined in view config for component'], 422);
            }
            $columnsParam = implode(',', array_map('trim', $compColumns));
        }
        try {
            [$columnsSubsetNormalized] = $this->normalizeColumnsSubset($model, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $effectiveTokensStore = $this->filterTokensByLangSupport($model, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record = $model->toApiRecord($effectiveTokensStore, $includeMeta);
        $record = $this->reorderRecord($record, $effectiveTokensStore);
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
        // Component required; use its columns when query 'columns' is absent
        $componentParam = $request->query('component');
        if (! $componentParam || ! is_string($componentParam) || $componentParam === '') {
            return response()->json(['error' => 'component parameter is required'], 422);
        }
        $columnsParam = $request->query('columns');
        $viewCfg = $this->loadViewConfig($modelName);
        if (empty($viewCfg) || ! array_key_exists($componentParam, $viewCfg)) {
            return response()->json(['error' => 'component key not found in view config'], 422);
        }
        if (! $columnsParam) {
            $compBlock = $viewCfg[$componentParam] ?? [];
            $compColumns = $compBlock['columns'] ?? [];
            if (! is_array($compColumns) || empty($compColumns)) {
                return response()->json(['error' => 'columns not defined in view config for component'], 422);
            }
            $columnsParam = implode(',', array_map('trim', $compColumns));
        }
        try {
            [$columnsSubsetNormalized] = $this->normalizeColumnsSubset($model, $columnsParam, $columnsSchema);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $effectiveTokensUpdate = $this->filterTokensByLangSupport($model, $columnsSchema, $columnsSubsetNormalized ?? [], (string) ($request->query('lang') ?? 'dv'));
        $record = $model->toApiRecord($effectiveTokensUpdate, $includeMeta);
        $record = $this->reorderRecord($record, $effectiveTokensUpdate);
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
