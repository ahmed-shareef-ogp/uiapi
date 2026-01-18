<?php

namespace Ogp\UiApi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    public function scopeSelectColumns(Builder $query, ?string $columns): Builder
    {
        if ($columns === null || strtolower($columns) === 'off') {
            return $query;
        }

        $list = array_map('trim', explode(',', $columns));

        if (count($list) > 0) {
            $query->select($list);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || strtolower($search) === 'off') {
            return $query;
        }

        $searchColumns = $this->searchColumns();

        if (empty($searchColumns)) {
            return $query;
        }

        $query->where(function (Builder $q) use ($searchColumns, $search): void {
            foreach ($searchColumns as $column) {
                $q->orWhere($column, 'LIKE', '%'.$search.'%');
            }
        });

        return $query;
    }

    public function scopeSearchAll(Builder $query, ?string $searchAll): Builder
    {
        if ($searchAll === null || strtolower($searchAll) === 'off') {
            return $query;
        }

        $table = $this->getTable();
        $columns = \Schema::getColumnListing($table);

        $query->where(function (Builder $q) use ($columns, $searchAll): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'LIKE', '%'.$searchAll.'%');
            }
        });

        return $query;
    }

    public function scopeWithoutRow(Builder $query, $withoutRow): Builder
    {
        if ($withoutRow === null || strtolower((string) $withoutRow) === 'off') {
            return $query;
        }

        return $query->whereKeyNot($withoutRow);
    }

    public function scopeSort(Builder $query, ?string $sort): Builder
    {
        if ($sort === null || strtolower($sort) === 'off') {
            return $query;
        }

        foreach (explode(',', $sort) as $segment) {
            $dir = 'asc';
            $col = trim($segment);

            if (str_starts_with($col, '-')) {
                $dir = 'desc';
                $col = ltrim($col, '-');
            }

            if ($col !== '') {
                $query->orderBy($col, $dir);
            }
        }

        return $query;
    }

    public function scopeFilter(Builder $query, $filters): Builder
    {
        if ($filters === null || strtolower((string) $filters) === 'off') {
            return $query;
        }

        $filters = is_string($filters) ? json_decode($filters, true) : $filters;

        if (! is_array($filters)) {
            return $query;
        }

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    public function scopeWithRelations(Builder $query, $with): Builder
    {
        if ($with === null || strtolower((string) $with) === 'off') {
            return $query;
        }

        $with = is_string($with) ? array_map('trim', explode(',', $with)) : (array) $with;

        if (! empty($with)) {
            $query->with($with);
        }

        return $query;
    }

    public function scopeWithPivotRelations(Builder $query, $pivot): Builder
    {
        if ($pivot === null || strtolower((string) $pivot) === 'off') {
            return $query;
        }

        $pivot = is_string($pivot) ? array_map('trim', explode(',', $pivot)) : (array) $pivot;

        foreach ($pivot as $relation) {
            if ($relation !== '') {
                $query->with($relation);
            }
        }

        return $query;
    }

    public static function paginateFromRequest(Builder $query, $pagination, ?int $perPage, ?int $page)
    {
        if ($pagination === null || strtolower((string) $pagination) === 'off') {
            return $query->get();
        }

        $perPage = $perPage ?? 15;
        $page = $page ?? null;

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public static function validate($request, $record = null): void
    {
        // Override in child models using Form Request in app when needed.
    }

    public static function createFromRequest($request, $user = null)
    {
        return static::query()->create($request->all());
    }

    public function updateFromRequest($request, $user = null)
    {
        $this->fill($request->all());
        $this->save();

        return $this;
    }

    protected function searchColumns(): array
    {
        return [];
    }
}
