# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Pergamum is a personal library management system for tracking books, reading history, lists, and statistics. It is a Vue 3 SPA backed by a Laravel 9 REST API, developed in Docker.

## Development Environment

The project runs via Docker Compose. The standard workflow is:

```bash
docker compose up -d          # Start all services (PHP, Nginx, Vite dev server, MySQL)
docker compose down           # Stop services
```

The compose file is named `compose.yml` (not `docker-compose.yml`).

Services:
- **PHP** (Laravel) — app server
- **Nginx 1.27-alpine** — web server / reverse proxy (port 8080, configurable via `APP_HTTP_PORT`)
- **Node 20-alpine** — Vite dev server (port 5173, configurable via `VITE_PORT`; dev profile only)
- **MySQL 8.0** — database (port 3307 forward, configurable via `DB_PORT_FORWARD`)

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

| Directory/File | Purpose |
|----------------|---------|
| `app.js` | Entry point — creates Vue app, registers plugins (Router, Pinia) |
| `bootstrap.js` | Axios and Lodash setup; attaches Sanctum token to headers |
| `App.vue` | Root layout (HeaderNav + SidebarNav wrapper) |
| `router/` | Vue Router config; routes split into `book-routes.js`, `author-routes.js`, `list-routes.js`, `admin-routes.js` |
| `stores/` | Pinia stores: Auth, Books, Authors, Config, Genre, NewBook, Lists |
| `api/` | Axios call modules: BookController.js, AuthorController.js, GenresController.js, VersionController.js, ListController.js, BulkUploadApi.js, apiHelpers.js |
| `views/` | Full-page components mounted by the router |
| `components/` | Reusable UI components, organized by feature subdirectory |
| `services/` | Business logic (BookServices.js — validation, error handling) |
| `utils/` | Shared utilities: validators.js, checkForChanges.js |

`@` alias maps to `resources/js/`.

Authentication is Sanctum token-based. The token is stored in AuthStore and attached to Axios headers via `resources/js/bootstrap.js`. CSRF is handled automatically through cookies.

**Views:**
- `HomeView.vue`, `LoginView.vue`, `AboutView.vue`, `UserDashboard.vue`
- `LibraryView.vue`, `AddBooksView.vue`, `NewBookView.vue`, `BookView.vue`, `EditBookView.vue`
- `AddReadHistoryView.vue`, `AddVersionView.vue`, `BulkUploadView.vue`
- `AuthorView.vue`, `FormatView.vue`, `GenresView.vue`, `GenreView.vue`
- `CompletedView.vue`, `StatisticsDashboard.vue`
- `ListsView.vue`, `ListView.vue`
- `ErrorNotFoundView.vue`
- `admin/AdminHome.vue`, `admin/AdminActionView.vue`

**Components (organized by subdirectory):**
- `admin/` — Format management (CreateFormat.vue, FormatsIndex.vue, FormatsList.vue)
- `auth/` — UserLoginForm.vue
- `books/forms/` — BookCreateEditForm.vue
- `books/table/` — BookshelfTable.vue, BookTableRow.vue, VersionTable.vue, VersionTableRow.vue
- `lists/` — ListItemsTable.vue
- `navs/` — HeaderNav.vue, SidebarNav.vue
- `newBook/` — multi-step form components (NewBookTitleInput.vue, NewAuthorsInput.vue, NewGenresInput.vue, NewVersionsInput.vue, NewReadInstanceInput.vue, NewBookVersionConfirmation.vue, NewBookProgressForm.vue, NewBookSubmitControls.vue)
- `updateBook/` — UpdateBookReadInstance.vue
- `ui/forms/` — BaseForm.vue
- `globals/alerts/` — AlertBox.vue
- `globals/loading/` — PageLoadingIndicator.vue
- `globals/svgs/` — CloseIcon.vue, UpArrow.vue

**Frontend tests** live in `resources/js/tests/` (Vitest): `api/apiHelpers.test.js`, `services/BookServices.test.js`, `stores/BooksStore.test.js`, `stores/NewBookStore.test.js`.

### Backend (`app/`)

| Directory | Purpose |
|-----------|---------|
| `Http/Controllers/` | Controllers: Books, Authors, Genres, Formats, Versions, Lists, ListItems, NewBook, Statistics, Config, BulkUpload, Users |
| `Models/` | Eloquent models: User, Book, Author, Genre, Format, Version, ReadInstance, BookList, ListItem |
| `Services/` | Backend business logic: BookService.php, AuthorService.php, StatisticsService.php |
| `Policies/` | Authorization: BookListPolicy.php |

**Model conventions:**
- Custom primary keys: `book_id`, `author_id`, `genre_id`, `format_id`, `version_id`, `list_id`, `list_item_id`, `read_instance_id`, `user_id` (not the default `id`)
- Slug-based URLs for book, author, and format detail pages
- Domain logic lives on models (e.g., date formatting methods)
- Relationships: many-to-many for book↔author and book↔genre via pivot tables

**Key API routes** (all protected by `auth:sanctum`):
- `GET/POST /api/books` — book listing and creation
- `GET /api/book/{slug}`, `GET /api/author/{slug}` — detail pages
- `POST /api/create-book/title`, `POST /api/create-book` — multi-step new book workflow
- `POST /api/add-read-instance` — record a reading with date/format/rating
- `GET /api/completed/years`, `GET /api/completed/{year}` — yearly reading statistics
- `GET /api/statistics` — aggregate stats
- `GET /api/config/formats`, `POST /api/formats` — format reference data and creation
- `POST /api/bulk-upload` — CSV bulk import of books
- `GET/POST /api/lists`, `GET /api/lists/{id}` — reading lists CRUD
- `PATCH /api/lists/{list}/reorder` — reorder list items (drag-and-drop)
- `POST /api/lists/{list}/items`, `DELETE /api/lists/{list}/items/{item}` — list item management
- `POST /api/create-authors`, `GET/POST /api/genres`, `POST /api/versions` — related resource creation
- `GET /api/user` — current user info

### Database

MySQL 8.0. Migrations are in `database/migrations/`. Notable schema:
- `books` → `book_author` (pivot) → `authors`
- `books` → `book_genre` (pivot) → `genres`
- `books` → `versions` (format editions with `page_count`, `audio_runtime`, `nickname`) → `read_instances` (per-read history with `user_id`, `rating`, `date_read`)
- `lists` → `list_items` (ordered book groupings; items reference `version_id`, not `book_id`)
- `read_instances` is scoped to individual users via `user_id`

### Key Dependencies

**Frontend:** Vue 3.2, Vue Router 4, Pinia 2, Axios 1.5, Tailwind CSS 3, Vite 4, Vitest 3, Papaparse 5 (CSV parsing), SortableJS 1.15 + sortablejs-vue3 (drag-and-drop list reordering)

**Backend:** Laravel 9, Laravel Sanctum 3, Laravel Pint 1, PHPUnit 9

## Code Style

- **PHP:** Laravel Pint (PSR-12 based); 4-space indentation
- **JS/Vue:** ESLint Airbnb + Prettier; double quotes enforced; `camelcase` rule off; `no-console` off
- **Line endings:** CRLF (configured in `.editorconfig`)
- **Indentation:** 4 spaces for PHP/Blade; JS follows ESLint config
