# Laravel Integration Notes (for real Laravel app)

## 1) Register middleware alias

In `app/Http/Kernel.php`, add:

```php
protected $middlewareAliases = [
    // ...existing aliases
    'compat.session.bridge' => \App\Http\Middleware\CompatSessionBridge::class,
];
```

## 2) Register compat config

Ensure `config/compat.php` exists (provided in this package).

## 3) Set compat root path

In Laravel `.env`:

```env
COMPAT_ROOT=C:/EducAidV2/EducAidV2
```

## 4) Route files

Copy route content from:
- `routes/web.php`
- `routes/api.php`

## 5) Zero-schema-change rule

Do not run migrations that alter existing EducAid schema.
Use Laravel for routing/middleware orchestration only during compatibility phase.
