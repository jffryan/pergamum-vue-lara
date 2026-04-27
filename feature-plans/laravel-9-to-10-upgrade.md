---
path: /feature-plans/
status: draft
---

# Laravel 9 to 10 Upgrade

## Goal

Upgrade the Laravel framework from 9 to 10 to stay on a supported release, picking up security patches and preparing the path for future upgrades (10 -> 11 -> 12). Reference: <https://laravel.com/docs/10.x/upgrade>.

## Approach

### Current state

- `laravel/framework: ^9.19`, `laravel/sanctum: ^3.0`, `phpunit/phpunit: ^9.5.10`
- `composer.json` requires `php: ^8.0.2`
- Docker PHP image is already `php:8.3-fpm-alpine` (runtime compatible — only the composer constraint is behind)
- Test suite is effectively empty (stock `ExampleTest` files only) — **manual smoke-testing of API routes is the real verification**

### Pre-flight

1. Create branch `chore/laravel-10-upgrade` off `main`.
2. Start from a clean tree (commit or stash unrelated changes).
3. Confirm container PHP version: `docker compose exec php php -v` -> expect 8.3.x.
4. Snapshot the DB: `docker compose exec db mysqldump ...` to a local file as cheap insurance.

### Step 1 — Bump `composer.json`

| Package | From | To | Reason |
|---|---|---|---|
| `php` | `^8.0.2` | `^8.1` | L10 minimum |
| `laravel/framework` | `^9.19` | `^10.0` | the upgrade |
| `laravel/sanctum` | `^3.0` | `^3.3` | L10 requires Sanctum 3.3+ |
| `phpunit/phpunit` (dev) | `^9.5.10` | `^10.0` | L10 default |
| `nunomaduro/collision` (dev) | `^6.1` | `^7.0` | L10 compat |
| `spatie/laravel-ignition` (dev) | `^1.0` | `^2.0` | L10 compat |
| `laravel/sail` (dev) | `^1.0.1` | `^1.18` | avoid resolver noise |
| `doctrine/dbal` | `^3.7` | `^3.7` | unchanged (removal target in L11) |

Leave `laravel/pint`, `laravel/tinker`, `fakerphp/faker`, `mockery/mockery`, `guzzlehttp/guzzle` as-is — constraints already allow L10-compatible versions.

### Step 2 — Composer update

```bash
docker compose exec php composer update --with-all-dependencies
```

Most likely resolver failure: a transitive Sanctum or Ignition pin. Resolve by tightening the direct constraint, not `--ignore-platform-reqs`.

### Step 3 — Adjust skeleton files

Only items that actually need changing for this codebase:

- **`phpunit.xml`** — PHPUnit 10 changed the schema. Run `vendor/bin/phpunit --migrate-configuration` to auto-rewrite. Drop `processUncoveredFiles="true"` from `<coverage>`.
- **`app/Exceptions/Handler.php`** — add return type: `public function register(): void`.
- **`app/Console/Kernel.php`** — add return types: `protected function schedule(Schedule $schedule): void`, `protected function commands(): void`.
- **`app/Http/Kernel.php`** — do NOT rename `$routeMiddleware` to `$middlewareAliases` yet (both work in L10; forced rename is L11).
- **`app/Http/Middleware/*`** — all stock, no edits needed.

### Step 4 — Code audit for L10 breaking changes

Verify each against this codebase (most are no-ops):

1. Doctrine DBAL stays — confirm no direct `Doctrine\DBAL` usage.
2. `$casts` -> `casts()` method — not required until L11.
3. Mailables — none in codebase.
4. `Redirect::home` removed — grep to confirm unused.
5. `assertDeleted`/`assertSoftDeleted` signature changes — only stock ExampleTests.
6. `Route::home` named route — n/a, SPA fallback is custom regex.
7. Carbon bump — verify no `Carbon::setTestNow` + raw `DateTime` mixing.
8. Validation `prohibits` semantics — grep to confirm unused.
9. Eloquent `$dates` removed — grep to confirm unused (should use `$casts`).

### Step 5 — Boot and smoke-test

```bash
docker compose exec php php artisan config:clear
docker compose exec php php artisan route:clear
docker compose exec php php artisan view:clear
docker compose exec php php artisan migrate --pretend
docker compose exec php php artisan route:list        # boots framework end-to-end
```

### Step 6 — Manual API smoke test

Walk these endpoints (exercises raw-SQL and service-layer code most likely to surface regressions):

- `GET /` — SPA loads
- `GET /api/books` — `BookController@index` with `selectRaw`
- `GET /api/books/{slug}` — single-book read
- `GET /api/genres` — `GenreController@index` with `orderByRaw`
- `GET /api/genres/{slug}` — `GenreController@show` with `selectRaw`
- `GET /api/statistics` — `StatisticsService::yearly` with `selectRaw('YEAR(date_read)...')`
- `GET /api/backlog` — `BookController@index` `selectRaw` joined to `backlog_items`
- `POST /api/books` — create flow through `BookService`
- Auth-protected route (`/api/user`) — Sanctum 3.3 sanity check

### Step 7 — Lint, format, commit

```bash
docker compose exec php vendor/bin/pint
docker compose exec php vendor/bin/phpunit
```

One commit, message body listing bumped packages and skeleton files touched.

### Rollback plan

```bash
git checkout main -- composer.json composer.lock
docker compose exec php composer install
```

DB snapshot from pre-flight covers the unlikely case that writes produced bad data during smoke testing.

## Touches existing systems

- **`composer.json` / `composer.lock`** — version bumps across multiple packages.
- **`phpunit.xml`** — schema migration for PHPUnit 10.
- **`app/Exceptions/Handler.php`**, **`app/Console/Kernel.php`** — return type additions.
- **Raw SQL in `StatisticsService`, `BookController`, `GenreController`** — not expected to break, but needs smoke-test verification since there's no test coverage.

## Open questions

- **Write feature tests first?** The empty test suite is the biggest risk multiplier. Writing 2-3 feature tests for high-traffic API routes (`/api/books`, `/api/genres`, `/api/backlog`) before this upgrade would give the 10 -> 11 -> 12 chain a real safety net. Worth doing as a prerequisite or out of scope for this PR?

---

<!-- After implementation, flip status to `living`, delete the sections above,
     move descriptive content to /documentation/<feature>.md, and keep only
     the two sections below. -->

## Future improvements

- **10 -> 11 upgrade** — rename `$routeMiddleware` to `$middlewareAliases`, remove `doctrine/dbal`, migrate to `bootstrap/app.php` / no-Kernel structure, adopt `casts()` method form on models.
- **PHPUnit config** — migrate to new `<source>` element when deprecation warnings appear.
- **Test coverage** — write real feature tests for core API routes before the next upgrade.

## Known limitations

- **Custom primary keys** (`book_id`, `author_id`, etc.) — no L10 changes affect this, but route-model binding is the place to watch if anything looks off post-upgrade.
- **Sanctum** is installed but only `/api/user` is actively protected — minimal surface for Sanctum 3.3 regressions but also minimal verification coverage.
