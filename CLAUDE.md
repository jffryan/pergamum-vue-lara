# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Pergamum is a personal library management system for tracking books, reading history, a reading backlog, and statistics. It is a Vue 3 SPA backed by a Laravel 9 REST API, developed in Docker.

## Development Environment

The project runs via Docker Compose. The standard workflow is:

```bash
docker compose up -d          # Start all services (PHP, Nginx, Vite dev server, MySQL)
docker compose down           # Stop services
```

Services:
- **PHP** (Laravel) — app server
- **Nginx** — web server / reverse proxy
- **Vite** — frontend dev server (port 5173, configurable via `VITE_PORT`)
- **MySQL 8.0** — database

## Commands

**Frontend (run inside Vite container or with Node 20 locally):**
```bash
npm run dev       # Start Vite dev server
npm run build     # Production build
npm run test      # Run Vitest tests
```

**Backend (run inside PHP container or via `docker compose exec`):**
```bash
php artisan migrate           # Run database migrations
php artisan tinker            # Interactive REPL
php artisan key:generate      # Generate app key

# Run PHPUnit tests
php artisan test
./vendor/bin/phpunit
./vendor/bin/phpunit tests/Feature/SomeTest.php   # Single test file
./vendor/bin/phpunit --filter testMethodName      # Single test by name

# Laravel Pint (PHP code style)
./vendor/bin/pint
```

**Linting:**
```bash
npx eslint resources/js       # Lint frontend JS/Vue
npx eslint resources/js --fix # Auto-fix
```

## Architecture

### Request Flow

1. Browser hits Nginx → served by Laravel
2. `routes/web.php` — standard auth routes (login, logout, register) + a catch-all that returns `resources/views/home.blade.php`
3. `home.blade.php` is a minimal Blade template that mounts `#app` and loads the Vite bundle
4. Vue Router takes over all client-side navigation
5. Vue components dispatch to Pinia stores → Pinia stores call `resources/js/api/` modules → Axios `POST/GET` to `/api/*`
6. `routes/api.php` routes to Laravel controllers → JSON responses

### Frontend (`resources/js/`)

| Directory | Purpose |
|-----------|---------|
| `app.js` | Entry point — creates Vue app, registers plugins (Router, Pinia) |
| `App.vue` | Root layout (HeaderNav + SidebarNav wrapper) |
| `router/` | Vue Router config; routes split into `book-routes.js` and `author-routes.js` |
| `stores/` | Pinia stores: Auth, Books, Authors, Config, Genre, Backlog, NewBook |
| `api/` | Axios call modules (one per resource: BookController.js, AuthorController.js, etc.) |
| `views/` | Full-page components mounted by the router |
| `components/` | Reusable UI components, organized by feature subdirectory |
| `services/` | Business logic (BookServices.js — validation, error handling) |

`@` alias maps to `resources/js/`.

Authentication is Sanctum token-based. The token is stored in AuthStore and attached to Axios headers via `resources/js/bootstrap.js`. CSRF is handled automatically through cookies.

### Backend (`app/`)

| Directory | Purpose |
|-----------|---------|
| `Http/Controllers/` | 10 resource-style controllers (Books, Authors, Genres, Backlog, Versions, Statistics, Users, etc.) |
| `Models/` | Eloquent models: User, Book, Author, Genre, Format, Version, ReadInstance, BacklogItem |

**Model conventions:**
- Custom primary keys: `book_id`, `author_id` (not the default `id`)
- Slug-based URLs for book/author detail pages
- Domain logic lives on models (e.g., `Book::addToBacklog()`, date formatting methods)
- Relationships: many-to-many for book↔author and book↔genre via pivot tables

**Key API routes:**
- `GET/POST /api/books` — book listing and creation
- `GET /api/book/{slug}`, `GET /api/author/{slug}` — detail pages
- `GET/POST/DELETE /api/backlog` — reading backlog; `POST /api/backlog/update-ordinals` for drag-and-drop reorder (SortableJS)
- `POST /api/create-book/*` — multi-step new book workflow
- `POST /api/add-read-instance` — record a reading with date/format
- `GET /api/completed/{year}` — yearly reading statistics
- `GET /api/statistics` — aggregate stats
- `GET /api/config/formats` — format reference data

### Database

MySQL 8.0. Migrations are in `database/migrations/`. Notable schema:
- `books` → `book_author` (pivot) → `authors`
- `books` → `book_genre` (pivot) → `genres`
- `books` → `versions` (format editions) → `read_instances` (per-read history)
- `backlog_items` with ordinal column for ordered list

## Code Style

- **PHP:** Laravel Pint (PSR-12 based); 4-space indentation
- **JS/Vue:** ESLint Airbnb + Prettier; double quotes enforced; `camelcase` rule off; `no-console` off
- **Line endings:** CRLF (configured in `.editorconfig`)
- **Indentation:** 4 spaces for PHP/Blade; JS follows ESLint config
