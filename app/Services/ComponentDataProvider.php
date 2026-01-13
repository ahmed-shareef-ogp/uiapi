<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * ComponentDataProvider
 *
 * Provides non-HTTP, component-centric helpers used by the generic API
 * to shape view configs, headers, filters, and language-aware column
 * handling. Keeps GenericApiService focused on request/response logic.
 */
class ComponentDataProvider
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
     * Whether relation tokens in records should be nested.
     */
    protected bool $nestRelationsInRecords = true;

    public function setIncludeHiddenColumnsInHeaders(bool $value): void
    {
        $this->includeHiddenColumnsInHeaders = $value;
    }

    public function setIncludeTopLevelHeaders(bool $value): void
    {
        $this->includeTopLevelHeaders = $value;
    }

    public function getIncludeTopLevelHeaders(): bool
    {
        return $this->includeTopLevelHeaders;
    }

    public function setIncludeTopLevelFilters(bool $value): void
    {
        $this->includeTopLevelFilters = $value;
    }

    public function getIncludeTopLevelFilters(): bool
    {
        return $this->includeTopLevelFilters;
    }

    public function setIncludeTopLevelPagination(bool $value): void
    {
        $this->includeTopLevelPagination = $value;
    }

    public function getIncludeTopLevelPagination(): bool
    {
        return $this->includeTopLevelPagination;
    }

    public function setNestRelationsInRecords(bool $value): void
    {
        $this->nestRelationsInRecords = $value;
    }

    /**
     * Ensure the model exposes apiSchema() and return it as an array.
     *
     * @throws \InvalidArgumentException when apiSchema() is missing
     */
    public function getApiSchemaOrFail(Model $model): array
    {
        if (! method_exists($model, 'apiSchema')) {
            throw new \InvalidArgumentException('Model missing apiSchema()');
        }

        $schema = $model->apiSchema();

        return is_array($schema) ? $schema : [];
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
        // Require explicit language declaration on the component block.
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
     * Parse filter query string into validated filters.
     */
    public function parseFilters(?string $filter, array $columnsSchema): array
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

    /**
     * Parse sort query string into validated sort tokens.
     */
    public function parseSorts(?string $sort, array $columnsSchema, ?array $columnsSubsetNormalized = null): array
    {
        $sortTokens = [];
        if ($sort) {
            $sortTokens = array_filter(array_map('trim', explode(',', $sort)));
            foreach ($sortTokens as $tok) {
                $field = ltrim($tok, '-');
                if (Str::contains($field, '.')) {
                    if (! is_array($columnsSubsetNormalized) || ! in_array($field, $columnsSubsetNormalized, true)) {
                        throw new \InvalidArgumentException("Sort field '$field' is not defined in apiSchema");
                    }
                } else {
                    if (! array_key_exists($field, $columnsSchema)) {
                        throw new \InvalidArgumentException("Sort field '$field' is not defined in apiSchema");
                    }
                }
            }
        }

        return $sortTokens;
    }

    /**
     * Compute component-driven inputs for index queries in one place.
     */
    public function computeComponentQueryInputs(
        Model $modelInstance,
        string $modelName,
        array $columnsSchema,
        ?string $componentKey,
        ?string $columnsParam,
        ?string $filterParam,
        ?string $sortParam
    ): array {
        $resolvedComp = $this->resolveViewComponent($modelName, $componentKey, $columnsParam);
        $compBlock = $resolvedComp['compBlock'];
        $columnsParam = $resolvedComp['columnsParam'];

        [$columnsSubsetNormalized, $relationsFromColumns] = $this->normalizeColumnsSubset($modelInstance, $columnsParam, $columnsSchema);
        $filters = $this->parseFilters($filterParam, $columnsSchema);
        $sortTokens = $this->parseSorts($sortParam, $columnsSchema, $columnsSubsetNormalized);

        return [
            'componentKey' => $resolvedComp['componentKey'],
            'compBlock' => $compBlock,
            'columnsParam' => $columnsParam,
            'columnsSubsetNormalized' => $columnsSubsetNormalized,
            'relationsFromColumns' => $relationsFromColumns,
            'filters' => $filters,
            'sortTokens' => $sortTokens,
        ];
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
     * Build headers from a columns schema for a given language.
     */
    public function buildHeaders(array $columnsSchema, string $lang): array
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
            $headers[] = $header;
        }

        return $headers;
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
            $f = $def['filterable'] ?? null;
            if (! $f || ! is_array($f)) {
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
                    // Reduce items to only the current language title key and itemValue
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
                                // If item is a scalar, use it for both fields
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
                    $sourceModel = (string) ($f['sourceModel'] ?? $modelName);
                    $paramField = ($sourceModel === $modelName) ? $field : $itemValue;
                    $filter['url'] = url("/api/{$sourceModel}/options/{$paramField}")."?itemTitle={$itemTitle}&itemValue={$itemValue}&lang={$lang}";
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
        $paginator,
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
            if (is_array($val)) {
                $out[$key] = $this->buildSectionPayload($val, $columnsSchema, $columnsSubsetNormalized, $lang, $paginator, $modelName, $modelInstance, $columnCustomizations, $allowedFilters);
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
        $paginator,
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
                $paginator,
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
                    $paginator,
                    $modelName,
                    $modelInstance,
                    $columnCustomizations,
                    $allowedFilters
                );
            }
        }

        return $componentSettings;
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

        foreach ($columnsSchema as $field => $def) {
            $f = $def['filterable'] ?? null;
            if (! $f || ! is_array($f)) {
                continue;
            }
            if (is_array($allowedFilters) && ! in_array($field, $allowedFilters, true)) {
                continue;
            }
            if (is_array($columnsSubsetNormalized) && ! in_array($field, $columnsSubsetNormalized, true)) {
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
            $out = [
                'type' => Str::title($type),
                'key' => $key,
                'label' => $label,
            ];

            $mode = strtolower((string) ($f['mode'] ?? 'self'));
            if ($mode === 'self') {
                $rawItemTitle = $f['itemTitle'] ?? $this->keyFor($def, $field);
                if (is_array($rawItemTitle)) {
                    $itemTitle = (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $this->keyFor($def, $field));
                } else {
                    $itemTitle = (string) $rawItemTitle;
                }
                $itemValue = (string) ($f['itemValue'] ?? $this->keyFor($def, $field));
                $out['itemTitle'] = $itemTitle;
                $out['itemValue'] = $itemValue;
                // Reduce items to only the current language title key and itemValue
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
                    $out['items'] = $pruned;
                } else {
                    $out['items'] = [];
                }
            } elseif ($mode === 'relation') {
                $relationship = (string) ($f['relationship'] ?? '');
                $related = $relationship ? $this->resolveRelatedModel($modelInstance, $relationship) : null;
                if ($related) {
                    $relatedBase = class_basename($related);
                    $itemValue = (string) ($f['itemValue'] ?? 'id');
                    $rawItemTitle = $f['itemTitle'] ?? $this->pickDefaultTitleField($related);
                    // For relation mode, itemTitle is typically a single field. Allow array for completeness.
                    if (is_array($rawItemTitle)) {
                        $itemTitle = (string) ($rawItemTitle[$lang] ?? $rawItemTitle['en'] ?? reset($rawItemTitle) ?? $this->pickDefaultTitleField($related));
                    } else {
                        $itemTitle = (string) $rawItemTitle;
                    }
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
     * Reorder a record to match the ordered tokens requested.
     */
    public function reorderRecord(array $record, array $orderedTokens): array
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

        return $out;
    }

    /**
     * Format record based on ordered tokens, optionally nesting relation tokens.
     */
    public function formatRecord(array $record, array $orderedTokens): array
    {
        if (! $this->nestRelationsInRecords) {
            return $this->reorderRecord($record, $orderedTokens);
        }

        $out = [];
        $represented = [];

        // First pass: respect orderedTokens for top-level ordering
        foreach ($orderedTokens as $token) {
            if (! array_key_exists($token, $record)) {
                continue;
            }
            if (Str::contains($token, '.')) {
                [$group, $field] = array_pad(explode('.', $token, 2), 2, null);
                if (! $field) {
                    continue;
                }
                if (! isset($out[$group]) || ! is_array($out[$group])) {
                    $out[$group] = [];
                }
                $out[$group][$field] = $record[$token];
                $represented[$group.'.'.$field] = true;
            } else {
                $out[$token] = $record[$token];
                $represented[$token] = true;
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
                // Apply columnCustomizations for relation token
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
                    // Pass-through any other custom keys except 'title' and 'value'
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
            // Apply columnCustomizations for base token
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
                // Pass-through any other custom keys except 'title' and 'value'
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
     * Normalize a comma-separated columns string into validated tokens and relations.
     */
    public function normalizeColumnsSubset(Model $model, ?string $columns, array $columnsSchema): array
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
}
