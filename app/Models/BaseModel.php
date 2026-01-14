<?php
namespace App\Models;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;


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

    public static function paginateFromRequest(Builder $query, ?string $pagination): Paginator | Collection
    {
        if ($pagination === 'off') {
            return $query->get();
        }

        return $query->simplePaginate();
    }

    public static function createFromRequest(Request $request, User $user = null)
    {
        $data = static::prepareData($request, $user);

        $record = static::create($data);

        // static::handleFiles($request, $record, $user);
        // static::handlePivots($request, $record);

        return $record;
    }

    protected static function prepareData(Request $request, User $user = null): array
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

    protected static function handleFiles(Request $request, Model $record, User $user = null): void
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
            (new static )->rules(),
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
            fn($col) => Schema::hasColumn($this->getTable(), $col)
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
            $column    = ltrim($field, '-');

            if ($this->isSortable($column)) {
                $query->orderBy($column, $direction);
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
                $key     = $related->getKeyName();

                if (! in_array($key, $cols, true)) {
                    array_unshift($cols, $key);
                }

                $query->with([
                    $name => fn($q) => $q->select($cols),
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
                $relation => fn($q) => $q->withPivot('*'),
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
