# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Pergamum is a personal library / reading-tracker app for tracking books, reading history, lists, and statistics. Laravel 9 (PHP 8) JSON API backed by MySQL 8, with a Vue 3 + Pinia + Vue Router SPA served from a single Blade entrypoint. Styled with Tailwind, bundled with Vite. Auth is Sanctum SPA cookie/session (not token-based) — login posts to `/login` in `routes/web.php`, and the API group's `EnsureFrontendRequestsAreStateful` middleware reads the session for `auth:sanctum` routes. See `/documentation/auth.md`.

## Development environment

The whole stack runs in Docker via `compose.yml` (note: not `docker-compose.yml`) — there are four services on the `appnet` network:

- `php` — PHP-FPM (image built from `docker/php/Dockerfile`). Vendor + storage + bootstrap/cache live in named volumes, **not** bind mounts, so `composer install` runs inside the container.
- `nginx` — fronts PHP, exposes the app on `${APP_HTTP_PORT:-8080}`.
- `vite` — node:20 container running `npm run dev` on `${VITE_PORT:-5173}` with `CHOKIDAR_USEPOLLING=true` (HMR works on Windows hosts). Dev profile only.
- `db` — MySQL 8, host port `${DB_PORT_FORWARD:-3307}` → container 3306.

Common commands:

```bash
docker compose up -d                                  # start the stack
docker compose down                                   # stop
docker compose exec php php artisan migrate           # run migrations
docker compose exec php php artisan tinker
docker compose exec php composer install
docker compose exec vite npm install
docker compose exec vite npm run build                # production bundle
```

If running PHP locally instead: `php artisan serve`, `npm run dev`, `npm run build`. The Vite dev server must be reachable at the host/port encoded in `vite.config.js` (HMR is hardcoded to `localhost:${VITE_PORT}`).

## Tests, lint, format

- **PHP tests:** `php artisan test`, `vendor/bin/phpunit`, or for a single file/method: `vendor/bin/phpunit tests/Feature/SomeTest.php` / `vendor/bin/phpunit --filter testMethodName`. Tests live in `tests/Feature` and `tests/Unit`.
- **JS tests:** `npm test` (Vitest). Specs live in `resources/js/tests/{api,services,stores}`. Single file: `npx vitest resources/js/tests/stores/BooksStore.test.js`.
- **PHP format:** `vendor/bin/pint` (PSR-12, 4-space indent).
- **JS lint:** `npx eslint resources/js` (`--fix` to auto-fix). ESLint extends `airbnb-base` + `vue3-essential` + `prettier`; double quotes enforced; `camelcase` and `no-console` are off.
- **Line endings:** CRLF (set in `.editorconfig`).

## Aliases & Vite

The `@` alias maps to `resources/js`. It's configured in the ESLint resolver but **not** in `vite.config.js`, yet `@/views/...` imports work in `router/index.js`. If adding new `@/...` imports, verify Vite picks them up; add a `resolve.alias` to `vite.config.js` if not.

## Workflow

Three folders carry session-to-session context: `/feature-plans/`, `/documentation/`, and `CHANGELOG.md`. The first two have their own README explaining their conventions — read those when creating or updating files in them.

The lifecycle is: **draft plan → implement → write feature doc → update plan status → add changelog entry**. Before starting non-trivial work, skim `/feature-plans/` for plans that touch the area you're modifying, and design the current change so it doesn't block planned ones.

`CHANGELOG.md` gets an entry for any user-visible or behavior-changing update (new endpoints, schema changes, route changes, breaking refactors). Skip for pure formatting, comment edits, and dependency bumps unless they change behavior. Keep entries as summaries; git history carries the detail.

## Architecture

### Backend (Laravel)

- **Routing**: SPA fallback in `routes/web.php` returns the `home` Blade view for any non-API path. All data access goes through `routes/api.php` — a mix of `Route::resource` and explicit endpoints. Most routes are protected by `auth:sanctum`.
- **Models** use non-conventional primary keys throughout — `Book` uses `book_id`, and the same pattern applies to `Author`, `Genre`, `Format`, `Version`, `List`, `ListItem`, `ReadInstance`, and `User` (custom `_id` columns, not the default `id`). Watch for this when writing relationships or queries; pivot relations (`book_author`, `book_genre`) are explicitly named in the `belongsToMany` calls. Books, authors, and formats use slug-based URLs for detail pages.
- **Domain shape:** a `Book` has many `Author`s and `Genre`s (M2M via pivots). A `Book` has many `Version`s (specific editions/formats — track `page_count`, `audio_runtime`, `nickname`). A `Version` has many `ReadInstance`s (per-read history, scoped to `user_id`, with `rating` and `date_read`). `Lists` contain `ListItem`s that reference `version_id`, not `book_id`.
- **Services** in `app/Services/` (`BookService`, `AuthorService`, `StatisticsService`) hold business logic; controllers are thin and inject services via the constructor. Some domain logic also lives on models (e.g., date formatting).
- **Policies:** `BookListPolicy` for list authorization.

### Frontend (Vue SPA)

The SPA is mounted from `resources/views/home.blade.php` → `resources/js/app.js`. Layered structure:

```
views/        route components (one per URL)
components/   reusable pieces, grouped by feature subdirectory
router/       split into book-routes.js, author-routes.js, list-routes.js, admin-routes.js, plus root index.js
stores/       Pinia stores, one per domain (Auth, Books, Authors, Config, Genre, NewBook, Lists)
services/     orchestration that combines multiple stores/controllers (e.g. BookServices.js — validation, error handling)
api/          thin axios wrappers per domain — all use makeRequest/buildUrl from apiHelpers.js
utils/        shared helpers (validators.js, checkForChanges.js)
```

Data flow convention: **views → services/stores → api/<Domain>Controller → axios**. Don't call axios directly from components; route through the `api/` layer so the URL/method shape stays consistent (`buildUrl(entity, id)` builds `/api/<entity>/<id>`).

Axios is configured globally in `bootstrap.js` (sets `X-Requested-With: XMLHttpRequest` and attaches axios to `window`). Lodash is also globalized. There is no token attach step — auth rides on the session cookie set by `/login`, with CSRF handled automatically via the XSRF cookie that `GET /sanctum/csrf-cookie` seats before login/register.

### Code conventions

Prefer extensible systems over one-off implementations. When a feature looks like it'll have siblings — another book status, another list type, another stats view — build the seam (config-driven dispatch, a small registry, a base class with overrides) the first time rather than copy-pasting on the second. Don't abstract speculatively for cases that aren't on the roadmap or in `/feature-plans/`; "rule of three" is fine when nothing in the plans suggests a third is coming.

### Database

Migrations in `database/migrations` define the domain. See the backend models section above for the relationship shape; key tables are `books`, `authors`, `book_author`, `genres`, `book_genre`, `formats`, `versions`, `read_instances`, `lists`, `list_items`. Note custom PK columns throughout (`book_id`, `author_id`, etc.).