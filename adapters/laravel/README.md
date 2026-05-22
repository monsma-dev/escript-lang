# EScript Laravel Adapter

Translates EScript IR into Laravel-native artifacts:

- `routes/api.php` — Route definitions
- `app/Http/Controllers/` — Controller stubs with type hints
- `app/Http/Requests/` — FormRequest validation classes
- `app/Services/` — Service classes with dependency injection
- `app/DTOs/` — Data Transfer Object classes

## Usage

```bash
php escript compile escript/ --output=build/ir
php adapters/laravel/generate.php build/ir/basic-api.ir.json --output=laravel-output/
```

## What it generates

Given the `basic-api.es` example, the adapter produces:

- `routes/api.php` with `Route::get()`, `Route::post()`, etc.
- Middleware groups applied per-route
- Controller methods with proper return types
- FormRequest classes for DTOs with validation rules
- Service classes with constructor injection

## Fail-closed enforcement

The adapter **refuses to generate** code for:

- Routes with `fail_mode: open` that lack `@unsafe`
- Services referencing undefined guards
- DTOs with `mixed` types without `@unsafe`

These checks mirror the compiler's validation — the adapter is a second line of defense.
