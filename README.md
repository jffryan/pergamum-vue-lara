Pergamum was an ancient library. This is a modern one.

A personal library and reading-tracker app for cataloging books, tracking reading history across editions, building lists, and surfacing reading statistics.

## Stack

- Laravel 9 (PHP 8) JSON API, MySQL 8, Sanctum token auth
- Vue 3 SPA with Pinia, Vue Router, Tailwind, bundled by Vite
- Dockerized via `compose.yml` (php-fpm, nginx, vite, db)

## Quick start

```bash
docker compose up -d
docker compose exec php php artisan migrate
```

App is served on `${APP_HTTP_PORT:-8080}`; Vite dev server on `${VITE_PORT:-5173}`.

## Tests & tooling

- PHP: `php artisan test`, format with `vendor/bin/pint`
- JS: `npm test` (Vitest), lint with `npx eslint resources/js`

See `CLAUDE.md` for architecture notes and `/feature-plans/`, `/documentation/`, `CHANGELOG.md` for project history.
