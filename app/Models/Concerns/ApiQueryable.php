<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

/**
 * ApiQueryable
 *
 * Provides opt-in helpers for models to:
 * - Define their own API schema via apiSchema()
 * - Serialize themselves into API payloads (including nested relations)
 * - Apply filter/search/sort clauses based on the model's schema
 *
 * Architectural notes:
 * - This trait intentionally keeps JSON shaping inside the model layer,
 *   avoiding Laravel API Resources as per requirements.
 * - Hidden columns in apiSchema are NOT removed from data; frontend decides visibility.
 * - All columns are filterable/sortable unless a model chooses to exclude them.
 */
trait ApiQueryable
{
    /**
     * Models MUST implement this method to declare API schema.
     *
     * Example shape:
     * return [
     *   'columns' => [ 'id' => ['hidden' => true, 'type' => 'number'] ],
     *   'searchable' => ['ref_num', 'summary'],
     * ];
     */
    abstract public function apiSchema(): array;

    /**
     * Serialize the model into an API record array.
     *
     * - Includes all attributes by default (even those marked hidden in schema).
     * - If $columnsSubset is provided, only those attributes are included.
     * - Any eager-loaded relations are nested with their own data + meta.columns.
     *
     * @param array|null $columnsSubset Optional subset of attribute keys to return.
     * @param bool $includeMeta Whether to include relation column metadata.
     * @return array
     */
    public function toApiRecord(?array $columnsSubset = null, bool $includeMeta = true): array
    {
        // Default to the model's declared schema columns.
        $schema = $this->apiSchema();
        $declaredColumns = array_keys($schema['columns'] ?? []);

        // Parse subset tokens into local columns and nested relation field requests.
        // Keep the original alias (e.g. entry_type_id) to flatten keys as "alias.field".
        $localSubset = [];
        $nestedAliasToFields = [];
        $aliasToRelationName = [];

        if (is_array($columnsSubset) && !empty($columnsSubset)) {
            foreach ($columnsSubset as $token) {
                if (!is_string($token)) {
                    continue;
                }
                if (Str::contains($token, '.')) {
                    [$alias, $col] = array_pad(explode('.', $token, 2), 2, null);
                    if (!$alias || !$col) {
                        continue;
                    }

                    // Resolve alias to an actual relation method name, to fetch related model
                    $relationName = null;
                    if (method_exists($this, $alias)) {
                        try { $relTest = $this->{$alias}(); } catch (\Throwable $e) { $relTest = null; }
                        if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relationName = $alias;
                        }
                    }
                    // Support snake_case alias mapping to relation method (e.g., entry_type -> entryType)
                    if (!$relationName) {
                        $camel = Str::camel($alias);
                        if (method_exists($this, $camel)) {
                            try { $relTest = $this->{$camel}(); } catch (\Throwable $e) { $relTest = null; }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $camel;
                            }
                        }
                    }
                    if (!$relationName && Str::endsWith($alias, '_id')) {
                        $guess = Str::camel(substr($alias, 0, -3));
                        if (method_exists($this, $guess)) {
                            try { $relTest = $this->{$guess}(); } catch (\Throwable $e) { $relTest = null; }
                            if ($relTest instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                                $relationName = $guess;
                            }
                        }
                    }
                    if ($relationName) {
                        $aliasToRelationName[$alias] = $relationName;
                        $nestedAliasToFields[$alias] = isset($nestedAliasToFields[$alias])
                            ? array_values(array_unique(array_merge($nestedAliasToFields[$alias], [$col])))
                            : [$col];
                    }
                } else {
                    $localSubset[] = $token;
                }
            }
        }

        $effectiveColumns = !empty($localSubset)
            ? array_values(array_intersect($localSubset, $declaredColumns))
            : $declaredColumns;

        $attributes = Arr::only($this->attributesToArray(), $effectiveColumns);

        $payload = $attributes;

        // Nest eager-loaded relations with their own data + meta
        foreach ($this->getRelations() as $relationName => $relationValue) {
            // If specific fields were requested for this relation via alias, flatten them as alias.field
            $aliasesForRelation = array_keys(array_filter($aliasToRelationName, function ($rel) use ($relationName) {
                return $rel === $relationName;
            }));

            if ($relationValue instanceof Model) {
                if (!empty($aliasesForRelation)) {
                    foreach ($aliasesForRelation as $alias) {
                        $fields = $nestedAliasToFields[$alias] ?? [];
                        foreach ($fields as $field) {
                            $payload[$alias . '.' . $field] = data_get($relationValue, $field);
                        }
                    }
                    // Do not include the nested relation object when flattening specific fields
                    continue;
                }
                // No specific fields requested: include relation using its own serializer
                $payload[$relationName] = $relationValue->toApiRecord(null, $includeMeta);
            } elseif ($relationValue instanceof Collection) {
                $payload[$relationName] = [
                    'data' => $relationValue->map(function (Model $m) use ($includeMeta, $relationName) {
                        // For nested items, default to their declared schema columns.
                        return $m->toApiRecord(null, $includeMeta);
                    })->all(),
                ];
                if ($includeMeta) {
                    $payload[$relationName]['meta'] = [
                        'columns' => $this->safeSchemaColumns($relationValue->first()),
                    ];
                }
            }
        }

        return $payload;
    }

    /**
     * Helper to format a single related model.
     */
    protected function formatRelatedModel(Model $model, bool $includeMeta = true): array
    {
        // Deprecated helper; kept for BC if referenced. Prefer toApiRecord.
        return $model->toApiRecord(null, $includeMeta);
    }

    /**
     * Extract 'columns' from a model's apiSchema safely.
     */
    protected function safeSchemaColumns(?Model $model): array
    {
        if (!$model) {
            return [];
        }
        if (!method_exists($model, 'apiSchema')) {
            return [];
        }
        $schema = $model->apiSchema();
        return $schema['columns'] ?? [];
    }

    /**
     * Apply exact and partial (LIKE) filters against allowed columns.
     *
     * @param Builder $query
     * @param array $filters e.g. ['ref_num' => 'ABC', 'summary' => ['like' => 'error']]
     * @param array $columnsSchema Schema['columns']
     */
    public static function applyApiFilters(Builder $query, array $filters, array $columnsSchema): void
    {
        foreach ($filters as $field => $value) {
            if (!array_key_exists($field, $columnsSchema)) {
                // Ignore unknown fields defensively; upstream validation should catch this
                continue;
            }

            if (is_array($value)) {
                // Support filter[field][like] = text
                if (array_key_exists('like', $value) && is_string($value['like'])) {
                    // Respect provided wildcard pattern (already converted * -> % upstream)
                    // Do NOT add extra % here so starts/ends-with are honored.
                    $query->where($field, 'LIKE', $value['like']);
                }
                // Potential future operators can be added here (gt, lt, in, etc.)
            } else {
                // Exact match
                $query->where($field, $value);
            }
        }
    }

    /**
     * Apply global search across declared searchable columns.
     *
     * @param Builder $query
     * @param string|null $q
     * @param array $searchable Schema['searchable']
     */
    public static function applyApiSearch(Builder $query, ?string $q, array $searchable): void
    {
        if (!$q || empty($searchable)) {
            return;
        }

        $query->where(function (Builder $sub) use ($q, $searchable) {
            foreach ($searchable as $idx => $column) {
                if ($idx === 0) {
                    $sub->where($column, 'LIKE', '%' . $q . '%');
                } else {
                    $sub->orWhere($column, 'LIKE', '%' . $q . '%');
                }
            }
        });
    }

    /**
     * Apply sorting based on schema-validated column name.
     *
     * @param Builder $query
     * @param string|null $sort e.g. "date_entry" or "-date_entry"
     * @param array $columnsSchema
     */
    public static function applyApiSort(Builder $query, ?string $sort, array $columnsSchema): void
    {
        if (!$sort) {
            return;
        }

        $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');

        if (!array_key_exists($field, $columnsSchema)) {
            // Ignore invalid sort; validation should reject this upstream
            return;
        }

        $query->orderBy($field, $direction);
    }

    /**
     * Apply multiple sorts in order: ["col1", "-col2", ...]
     *
     * @param Builder $query
     * @param array $sorts
     * @param array $columnsSchema
     */
    public static function applyApiSorts(Builder $query, array $sorts, array $columnsSchema): void
    {
        foreach ($sorts as $sort) {
            if (!is_string($sort) || $sort === '') {
                continue;
            }
            $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
            $field = ltrim($sort, '-');
            if (!array_key_exists($field, $columnsSchema)) {
                // Ignore invalid field; upstream validation should block it
                continue;
            }
            $query->orderBy($field, $direction);
        }
    }

    /**
     * Parse and validate eager-loaded relations (first segment only).
     * Accepts comma-separated relation paths, e.g.: "sender.person,comments.author".
     *
     * Only validates the existence of the first segment method and that it
     * returns an Eloquent Relation. Nested segments are passed through.
     *
     * @param Model $model
     * @param string|null $with
     * @return array
     */
    public static function parseWithRelations(Model $model, ?string $with): array
    {
        if (!$with) {
            return [];
        }

        $paths = array_filter(array_map('trim', explode(',', $with)));
        $valid = [];

        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $first = $segments[0] ?? null;
            if (!$first) {
                continue;
            }

            if (!method_exists($model, $first)) {
                // Unknown relation method; skip
                continue;
            }

            try {
                $rel = $model->{$first}();
            } catch (\Throwable $e) {
                $rel = null;
            }
            if ($rel instanceof Relation) {
                $valid[] = $path; // allow nested path if first segment is valid
            }
        }

        return $valid;
    }
}
