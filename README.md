<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Internal Generic JSON API

This project includes a single, internal-only, schema-driven endpoint to query any Eloquent model dynamically without API Resources:

- Endpoint: `GET /api/data`
- Parameters:
	- `model`: Model name, e.g. `Entry`
	- `columns`: Optional comma-separated subset, e.g. `id,ref_num`
	- `filter[field]=value` or `filter[field][like]=text`
	- `q`: Global search across model-declared searchable columns
	- `sort`: Comma-separated fields; prefix with `-` for descending (e.g. `date_entry,-ref_num`)
	- `page`, `per_page`: Pagination controls
	- `with`: Comma-separated relations, supports nesting (`sender.person`)
	- `include_meta`: `true|false` (column metadata included by default)

Models must implement `apiSchema(): array` to declare columns, metadata, and searchable fields. See `app/Models/Entry.php` for an example and `app/Models/Concerns/ApiQueryable.php` for reusable helpers.

Response shape:

```
{
	"data": [...],
	"meta": {
		"columns": { "id": { "hidden": true, "type": "number" }, ... },
		"pagination": { "current_page": 1, "last_page": 10, "per_page": 25, "total": 250 }
	}
}
```

Behavior notes:
- By default, `data` includes only columns declared in the model's `apiSchema()['columns']` (including those marked `hidden: true`).
- Supplying `columns=` limits further to the validated subset (intersection with `apiSchema` columns).
- Relations serialize using their own model schema and include nested `meta.columns`.
