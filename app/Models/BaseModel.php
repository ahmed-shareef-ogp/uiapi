<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    /**
     * Columns allowed for global search
     * Override in child models
     */
    protected array $searchable = [];

    /**
     * Columns allowed for sorting
     * Override in child models
     */
    protected array $sortable = [];

    /**
     * Relations that are allowed to be eager-loaded
     * Empty = allow all defined relations (optional)
     */
    protected array $withable = [];

    /**
     * Pivot relations allowed to be eager-loaded
     * Empty = allow all belongsToMany relations
     */
    protected array $pivotable = [];

    public static function paginateFromRequest(Builder $query, ?string $pagination, ?int $perPage = null, ?int $page = null): Paginator|Collection
    {
        if ($pagination === 'off') {
            return $query->get();
        }

        return $query->simplePaginate($perPage ?? 15, ['*'], 'page', $page);
    }

    public static function createFromRequest(Request $request, ?User $user = null)
    {
        $data = static::prepareData($request, $user);

        $record = static::create($data);

        // static::handleFiles($request, $record, $user);
        // static::handlePivots($request, $record);

        return $record;
    }

    protected static function prepareData(Request $request, ?User $user = null): array
    {
        $data = $request->except(['password', 'pivot']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if (method_exists(static::class, 'fileFields')) {
            $data = $request->except(static::fileFields());
        }

        return $data;
    }

    /**
     * Update model from request
     */
    public function updateFromRequest(Request $request, $user = null): self
    {
        $data = static::sanitizeRequestData($request, $user, false);

        $this->update($data);

        return $this->fresh();
    }

    /**
     * Shared request sanitization
     */
    protected static function sanitizeRequestData(
        Request $request,
        $user,
        bool $isCreate
    ): array {
        $data = $request->except(['password', 'pivot']);

        if ($isCreate && $user) {
            $data['created_by'] = $user->username ?? null;
        }

        if (! $isCreate && $user) {
            $data['updated_by'] = $user->username ?? null;
        }

        if ($request->filled('password')) {
            $data['password'] = bcrypt($request->password);
        }

        return $data;
    }

    /**
     * Default validation messages
     */
    public static function validationMessages(): array
    {
        return [];
    }

    protected static function handlePivots(Request $request, Model $record): void
    {
        if (! $request->has('pivot')) {
            return;
        }

        foreach ($request->input('pivot') as $relation => $items) {
            if (! method_exists($record, $relation)) {
                continue;
            }

            foreach ($items as $item) {
                $record->$relation()->attach(
                    $item['id'],
                    $item['pivot'] ?? []
                );
            }
        }
    }

    protected static function handleFiles(Request $request, Model $record, ?User $user = null): void
    {
        if (! method_exists(static::class, 'fileFields')) {
            return;
        }

        app(\App\Services\FileUploadService::class)->handle(
            $request,
            $record,
            strtolower(class_basename(static::class)),
            $user
        );
    }

    public static function validate(Request $request): void
    {
        $request->headers->set('Accept', 'application/json');

        if (! method_exists(static::class, 'rules')) {
            return;
        }

        Validator::make(
            $request->all(),
            (new static)->rules(),
            static::validationMessages()
        )->validate();

    }

    /* -------------------------
     |  Query Scopes
     |--------------------------*/

    public function scopeSelectColumns(Builder $query, ?string $columns): Builder
    {
        if (! $columns) {
            return $query;
        }

        $requested = array_map('trim', explode(',', $columns));

        $valid = array_filter(
            $requested,
            fn ($col) => Schema::hasColumn($this->getTable(), $col)
        );

        if (! empty($valid)) {
            $query->select($valid);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            foreach (explode('|', $search) as $orGroup) {
                $q->orWhere(function (Builder $orQ) use ($orGroup) {
                    foreach (explode(',', $orGroup) as $criterion) {
                        [$column, $value] = explode(':', $criterion, 2);

                        if ($this->isSearchable($column)) {
                            $orQ->where($column, 'LIKE', "%{$value}%");
                        }
                    }
                });
            }
        });
    }

    // will work only if $searchable is defined in the model
    public function scopeSearchAll(Builder $query, ?string $term): Builder
    {
        if (! $term || empty($this->searchable)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            foreach ($this->searchable as $column) {
                $q->orWhere($column, 'LIKE', "%{$term}%");
            }
        });
    }

    public function scopeWithoutRow(Builder $query, ?string $criteria): Builder
    {
        if (! $criteria) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($criteria) {
            foreach (explode('|', $criteria) as $orGroup) {
                $q->orWhere(function (Builder $orQ) use ($orGroup) {
                    foreach (explode(',', $orGroup) as $criterion) {
                        [$column, $value] = explode(':', $criterion, 2);

                        if ($this->isSearchable($column)) {
                            $orQ->where($column, '!=', $value);
                        }
                    }
                });
            }
        });
    }

    public function scopeSort(Builder $query, ?string $sort): Builder
    {
        if (! $sort) {
            return $query;
        }

        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-');

            if ($this->isSortable($column)) {
                $query->orderBy($column, $direction);
            }
        }

        return $query;
    }

    /**
     * Apply filters from a comma-separated string: field:value pairs.
     * Supports:
     * - AND across pairs: a:b,c:d
     * - OR within a field using pipe: a:x|y
     * - Relation fields: relation.field:value (applied via whereHas)
     * - Special values: null, !null, true, false, empty string
     */
    public function scopeFilter(Builder $query, ?string $filter): Builder
    {
        if (! $filter || trim($filter) === '') {
            return $query;
        }

        $pairs = array_filter(array_map('trim', explode(',', $filter)), fn ($p) => $p !== '');
        foreach ($pairs as $pair) {
            [$field, $raw] = array_pad(explode(':', $pair, 2), 2, null);
            $field = is_string($field) ? trim($field) : '';
            if ($field === '' || $raw === null) {
                continue;
            }

            $tokens = array_map('trim', explode('|', (string) $raw));
            $values = [];
            $hasEmpty = false;
            $hasNull = false;
            $hasNotNull = false;

            foreach ($tokens as $tok) {
                $lower = strtolower($tok);
                if ($tok === '') {
                    $hasEmpty = true;
                } elseif ($lower === 'null') {
                    $hasNull = true;
                } elseif ($lower === '!null') {
                    $hasNotNull = true;
                } elseif ($lower === 'true') {
                    $values[] = true;
                } elseif ($lower === 'false') {
                    $values[] = false;
                } else {
                    $values[] = $tok;
                }
            }

            $apply = function (Builder $b, string $col) use ($values, $hasEmpty, $hasNull, $hasNotNull): void {
                $b->where(function (Builder $q) use ($col, $values, $hasEmpty, $hasNull, $hasNotNull): void {
                    $first = true;
                    if (! empty($values)) {
                        $q->whereIn($col, $values);
                        $first = false;
                    }
                    if ($hasEmpty) {
                        if ($first) {
                            $q->where($col, '');
                        } else {
                            $q->orWhere($col, '');
                        }
                        $first = false;
                    }
                    if ($hasNull) {
                        if ($first) {
                            $q->whereNull($col);
                        } else {
                            $q->orWhereNull($col);
                        }
                        $first = false;
                    }
                    if ($hasNotNull) {
                        if ($first) {
                            $q->whereNotNull($col);
                        } else {
                            $q->orWhereNotNull($col);
                        }
                    }
                });
            };

            if (Str::contains($field, '.')) {
                [$relation, $relCol] = array_pad(explode('.', $field, 2), 2, null);
                if ($relation && $relCol && method_exists($this, $relation)) {
                    $query->whereHas($relation, function (Builder $relQ) use ($apply, $relCol): void {
                        $apply($relQ, $relCol);
                    });
                }
            } else {
                $apply($query, $field);
            }
        }

        return $query;
    }

    public function scopeWithRelations(Builder $query, ?string $relations): Builder
    {
        if (! $relations) {
            return $query;
        }

        foreach (explode(',', $relations) as $relation) {
            [$name, $columns] = array_pad(explode(':', $relation, 2), 2, null);

            if (! method_exists($this, $name)) {
                continue;
            }

            if ($columns) {
                $cols = array_map('trim', explode(',', $columns));

                // Always include the related model key
                $related = $this->$name()->getRelated();
                $key = $related->getKeyName();

                if (! in_array($key, $cols, true)) {
                    array_unshift($cols, $key);
                }

                $query->with([
                    $name => fn ($q) => $q->select($cols),
                ]);
            } else {
                $query->with($name);
            }
        }

        return $query;
    }

    public function scopeWithPivotRelations(Builder $query, ?string $relations): Builder
    {
        if (! $relations) {
            return $query;
        }

        $requested = array_map('trim', explode(',', $relations));

        foreach ($requested as $relation) {
            if (! method_exists($this, $relation)) {
                continue;
            }

            $relationInstance = $this->$relation();

            // Only allow belongsToMany
            if (! $relationInstance instanceof BelongsToMany) {
                continue;
            }

            if (! $this->isPivotableRelation($relation)) {
                continue;
            }

            $query->with([
                $relation => fn ($q) => $q->withPivot('*'),
            ]);
        }

        return $query;
    }

    /* -------------------------
     |  Helpers
     |--------------------------*/

    public function isSearchable(string $column): bool
    {
        return true; // functionality disabled - all columns are searchable

        return in_array($column, $this->searchable, true);
    }

    public function isSortable(string $column): bool
    {
        return true; // functionality disabled - all columns are sortable

        return in_array($column, $this->sortable, true);
    }

    protected function isWithableRelation(string $relation): bool
    {
        return true; // functionality disabled - all relations are withable

        return empty($this->withable) || in_array($relation, $this->withable, true);
    }

    protected function isPivotableRelation(string $relation): bool
    {
        return true; // functionality disabled - all relations are pivotable

        return empty($this->pivotable) || in_array($relation, $this->pivotable, true);
    }
}
