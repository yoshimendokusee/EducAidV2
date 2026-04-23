# Laravel Integration Notes (for real Laravel app)

## 1) Register middleware alias

In `app/Http/Kernel.php`, add:

```php
protected $middlewareAliases = [
    // ...existing aliases
    'legacy.session.bridge' => \App\Http\Middleware\LegacySessionBridge::class,
];
```

## 2) Register legacy config

Ensure `config/legacy.php` exists (provided in this package).

## 3) Set legacy root path

In Laravel `.env`:

```env
LEGACY_ROOT=C:/EducAidV2/EducAidV2
```

## 4) Route files

Copy route content from:
- `routes/web.php`
- `routes/api.php`

## 5) Zero-schema-change rule

Do not run migrations that alter existing EducAid schema.
Use Laravel for routing/middleware orchestration only during compatibility phase.
